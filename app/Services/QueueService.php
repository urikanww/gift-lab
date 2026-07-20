<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\JobState;
use App\Enums\JobTrack;
use App\Enums\LineItemState;
use App\Enums\QuoteState;
use App\Events\OrderTrackingUpdated;
use App\Events\ProductionQueueUpdated;
use App\Events\QuoteStateChanged;
use App\Models\ProductionJob;
use App\Models\Proof;
use App\Models\Quote;
use App\Support\Broadcasting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Assembles production jobs from a fully-resolved quote and reads the shared
 * FCFS queue. Gate 2 (spec principle 1): a job is only created once its lines
 * are confirmed READY; readiness time - not order time - drives queue order.
 */
final class QueueService
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * Build production jobs for a quote whose line items are all resolved
     * (READY or DROPPED), sets ready_at = now (spec principle 2), attaches the
     * print-ready file, and moves the quote to READY.
     *
     * Grouping: UV-track lines (CORE + SCRAPED_UV) share the one approved proof
     * artwork, so they collapse into a single UV job. MODEL_3D lines each print
     * on their own jig with their own UV-flattened decal (print_file_ref) - a
     * distinct file, qty and print method per item - so every 3D line becomes
     * its own job. One UV job + one job per 3D line.
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

        // UV lines fold into one bucket per track; each 3D line gets a bucket of
        // its own (keyed by line id) so it materialises as a standalone job.
        $groups = $readyLines->groupBy(function ($line): string {
            $track = $line->product->class->track();

            return $track === JobTrack::ThreeD
                ? JobTrack::ThreeD->value.':'.$line->id
                : $track->value;
        });

        $jobs = collect();

        DB::transaction(function () use ($quote, $groups, $approvedProof, &$jobs): void {
            foreach ($groups as $lines) {
                $track = $lines->first()->product->class->track();
                $job = ProductionJob::create([
                    'quote_id' => $quote->id,
                    'track' => $track->value,
                    'ready_at' => now(),
                    'state' => JobState::Ready->value,
                    'artwork_ref' => $this->resolveArtworkRef($track, $lines, $approvedProof),
                    'print_method' => $lines->first()->product->print_method?->value,
                    'qty' => (int) $lines->sum('qty'),
                    'created_by' => auth()->id(),
                ]);

                foreach ($lines as $line) {
                    $line->job_id = $job->id;
                    $line->save();
                }

                $jobs->push($job);
                DB::afterCommit(fn () => Broadcasting::dispatch(fn () => ProductionQueueUpdated::dispatch($job, 'queued')));
            }

            $previous = $quote->state->value;
            $quote->transitionTo(QuoteState::Ready);
            DB::afterCommit(fn () => Broadcasting::dispatch(fn () => QuoteStateChanged::dispatch($quote, $previous)));
        });

        return $jobs;
    }

    /**
     * The print-ready file a track's job hands the shop floor. MODEL_3D lines
     * (3D track) carry a UV-flattened production decal in
     * customization.print_file_ref - the file the UV printer/jig actually
     * consumes - which must supersede the proof mockup (artwork_version_ref,
     * the buyer's on-canvas sign-off). Everything else, and any legacy/flat 3D
     * line predating the decal pipeline (no print_file_ref), falls back to the
     * approved proof artwork.
     *
     * A 3D group is a single line (buildJobsForQuote gives each 3D line its own
     * job), so the loop returns that one line's decal; the fallback covers the
     * legacy no-print_file_ref case. UV groups skip straight to the proof.
     *
     * @param  Collection<int, \App\Models\LineItem>  $lines
     */
    private function resolveArtworkRef(JobTrack $track, Collection $lines, Proof $approvedProof): ?string
    {
        if ($track === JobTrack::ThreeD) {
            foreach ($lines as $line) {
                $printFileRef = $line->customization['print_file_ref'] ?? null;
                if (is_string($printFileRef) && $printFileRef !== '') {
                    return $printFileRef;
                }
            }
        }

        return $approvedProof->artwork_version_ref;
    }

    /**
     * Advance many jobs to the same target in one call. Each job is guarded by
     * canTransitionTo; jobs in the wrong current state are collected as skipped
     * rather than failing the whole batch. Returns [advanced ids, skipped ids].
     *
     * @param  array<int, int>  $jobIds
     * @return array{advanced: array<int, int>, skipped: array<int, int>}
     */
    public function advanceBatch(array $jobIds, JobState $target): array
    {
        $advanced = [];
        $skipped = [];

        foreach (ProductionJob::query()->whereIn('id', $jobIds)->get() as $job) {
            if ($job->state->canTransitionTo($target)) {
                $this->advance($job, $target);
                $advanced[] = $job->id;
            } else {
                $skipped[] = $job->id;
            }
        }

        return ['advanced' => $advanced, 'skipped' => $skipped];
    }

    /**
     * Advance a job to its single next lifecycle state (scan/one-tap). SHIPPED is
     * refused here - it needs a consignment ref/carrier - so a scan can never
     * silently fire the buyer's "on the way" signal without a real handover.
     *
     * @throws \App\Exceptions\DomainRuleException when the next state is SHIPPED
     */
    public function advanceNext(ProductionJob $job): ProductionJob
    {
        $next = $job->state->nextStates()[0] ?? null;

        if ($next === null) {
            throw new \App\Exceptions\DomainRuleException('This job has no further state to advance to.');
        }

        if ($next === JobState::Shipped) {
            throw new \App\Exceptions\DomainRuleException(
                'Marking a job shipped needs a consignment reference. Use the ship action.'
            );
        }

        return $this->advance($job, $next);
    }

    /**
     * The shared production queue, FCFS by readiness. No customer-type priority.
     *
     * @return Collection<int, ProductionJob>
     */
    public function queue(): Collection
    {
        // Eager-load the line items + their product so the floor can see each
        // saved customization and render the decorated 3D model (final-product
        // visualization) without an N+1 per card. The quote comes along too:
        // ProductionJobResource reads quote.reference (the identifier the floor
        // and the buyer both name an order by), so leaving the relation lazy
        // would cost one query per card - invisible on screen, only a query
        // count catches it. See QuoteReferenceExposureTest's N+1 guard.
        return ProductionJob::query()
            ->queueOrder()
            ->with(['quote', 'lineItems.product.modelParts'])
            ->get();
    }

    /**
     * Advance a job's production state and broadcast the queue change. Every
     * transition is audit-logged (who/when/old→new) because this state is the
     * single source of truth the public tracker reads.
     */
    public function advance(
        ProductionJob $job,
        JobState $target,
        ?string $consignmentRef = null,
        ?\App\Enums\Carrier $carrier = null,
    ): ProductionJob {
        $from = $job->state->value;

        // Persisted in the same save as the state change (transitionTo saves).
        if ($target === JobState::Shipped && $consignmentRef !== null) {
            $job->consignment_ref = $consignmentRef;
            if ($carrier !== null) {
                $job->carrier = $carrier;
            }
        }

        $job->transitionTo($target);

        $this->audit->log(
            $job,
            'production_job.advanced',
            ['state' => $from],
            ['state' => $target->value, 'consignment_ref' => $job->consignment_ref],
        );

        $action = match ($target) {
            JobState::InProduction => 'started',
            JobState::Shipped => 'shipped',
            JobState::Closed => 'closed',
            JobState::Ready => 'queued',
        };

        Broadcasting::dispatch(fn () => ProductionQueueUpdated::dispatch($job, $action));

        // When the final job for a quote closes, close the quote too
        // (READY -> CLOSED). Without this edge the tracker's DELIVERED stage -
        // which keys off QuoteState::Closed - was unreachable: no other code
        // path ever performed the READY->CLOSED transition.
        $job->loadMissing('quote');
        if ($target === JobState::Closed
            && $job->quote !== null
            && $job->quote->state === QuoteState::Ready
        ) {
            $allClosed = $job->quote->jobs()
                ->where('state', '!=', JobState::Closed->value)
                ->doesntExist();

            if ($allClosed) {
                // transitionTo does two writes (the state save and the audit
                // insert), so it needs a transaction to stay atomic. This is the
                // last transition in an order's life - the one the buyer's
                // delivery tracker keys off and the top row of the status
                // history - so a committed state with a lost audit row is the
                // worst place for the trail to go quiet.
                DB::transaction(function () use ($job): void {
                    $previous = $job->quote->state->value;
                    $job->quote->transitionTo(QuoteState::Closed);
                    DB::afterCommit(fn () => Broadcasting::dispatch(fn () => QuoteStateChanged::dispatch($job->quote, $previous)));
                });
            }
        }

        // A job advance normally leaves the quote in READY, so no
        // QuoteStateChanged fires - push the tracker update directly
        // (IN_PRODUCTION/SHIPPED/DELIVERED are the stages buyers watch most).
        // The QuoteStateChanged above already mirrors onto the tracker for the
        // closing case, so avoid a duplicate there.
        if ($job->quote !== null && $job->quote->state !== QuoteState::Closed) {
            Broadcasting::dispatch(fn () => OrderTrackingUpdated::dispatch($job->quote));
        }

        return $job;
    }
}
