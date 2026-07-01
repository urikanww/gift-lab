<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\JobState;
use App\Enums\LineItemState;
use App\Enums\QuoteState;
use App\Events\ProductionQueueUpdated;
use App\Events\QuoteStateChanged;
use App\Models\ProductionJob;
use App\Models\Quote;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Assembles production jobs from a fully-resolved quote and reads the shared
 * FCFS queue. Gate 2 (spec principle 1): a job is only created once its lines
 * are confirmed READY; readiness time — not order time — drives queue order.
 */
final class QueueService
{
    /**
     * Build production jobs for a quote whose line items are all resolved
     * (READY or DROPPED). Groups READY lines by track, sets ready_at = now
     * (spec principle 2), attaches the approved proof artwork as the print file,
     * and moves the quote to READY. One job per track.
     *
     * @return Collection<int, ProductionJob>
     */
    public function buildJobsForQuote(Quote $quote): Collection
    {
        $quote->loadMissing('lineItems.product');

        $unresolved = $quote->lineItems->filter(
            fn ($line): bool => ! $line->line_state->isResolvedForQueue()
        );

        if ($unresolved->isNotEmpty()) {
            throw new RuntimeException(
                "Quote {$quote->id} has {$unresolved->count()} unresolved line(s); cannot queue."
            );
        }

        $approvedProof = $quote->approvedProof();

        if ($approvedProof === null) {
            throw new RuntimeException("Quote {$quote->id} has no approved proof; production gate not met.");
        }

        $readyLines = $quote->lineItems->filter(
            fn ($line): bool => $line->line_state === LineItemState::Ready
        );

        $byTrack = $readyLines->groupBy(fn ($line): string => $line->product->class->track()->value);

        $jobs = collect();

        DB::transaction(function () use ($quote, $byTrack, $approvedProof, &$jobs): void {
            foreach ($byTrack as $trackValue => $lines) {
                $job = ProductionJob::create([
                    'quote_id' => $quote->id,
                    'track' => $trackValue,
                    'ready_at' => now(),
                    'state' => JobState::Ready->value,
                    'artwork_ref' => $approvedProof->artwork_version_ref,
                    'print_method' => $lines->first()->product->print_method?->value,
                    'qty' => (int) $lines->sum('qty'),
                    'created_by' => auth()->id(),
                ]);

                foreach ($lines as $line) {
                    $line->job_id = $job->id;
                    $line->save();
                }

                $jobs->push($job);
                DB::afterCommit(fn () => ProductionQueueUpdated::dispatch($job, 'queued'));
            }

            $previous = $quote->state->value;
            $quote->transitionTo(QuoteState::Ready);
            DB::afterCommit(fn () => QuoteStateChanged::dispatch($quote, $previous));
        });

        return $jobs;
    }

    /**
     * The shared production queue, FCFS by readiness. No customer-type priority.
     *
     * @return Collection<int, ProductionJob>
     */
    public function queue(): Collection
    {
        // No eager-load: ProductionJobResource emits only quote_id (the FK on the
        // job row itself) and never reads the quote relation, so with('quote')
        // was a dead extra whereIn query + hydration on every queue read.
        return ProductionJob::query()->queueOrder()->get();
    }

    /**
     * Advance a job's production state and broadcast the queue change.
     */
    public function advance(ProductionJob $job, JobState $target): ProductionJob
    {
        $job->transitionTo($target);

        $action = match ($target) {
            JobState::InProduction => 'started',
            JobState::Shipped => 'shipped',
            JobState::Closed => 'closed',
            JobState::Ready => 'queued',
        };

        ProductionQueueUpdated::dispatch($job, $action);

        return $job;
    }
}
