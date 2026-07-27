<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Carrier;
use App\Enums\JobState;
use App\Enums\JobTrack;
use App\Enums\LineItemState;
use App\Enums\OrderMilestone;
use App\Enums\ProofState;
use App\Enums\QuoteState;
use App\Events\OrderTrackingUpdated;
use App\Events\ProductionQueueUpdated;
use App\Events\QuoteStateChanged;
use App\Exceptions\DomainRuleException;
use App\Models\LineItem;
use App\Models\ProductionJob;
use App\Models\Quote;
use App\Services\Courier\NinjaVanStatusMapper;
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
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly OrderNotifier $notifier,
    ) {}

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

        DB::transaction(function () use ($quote, $groups, &$jobs): void {
            foreach ($groups as $lines) {
                $track = $lines->first()->product->class->track();
                $job = ProductionJob::create([
                    'quote_id' => $quote->id,
                    'track' => $track->value,
                    'ready_at' => now(),
                    'state' => JobState::Ready->value,
                    'artwork_refs' => $this->resolveArtworkRefs($track, $lines),
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
     * Print-ready files a job hands the floor, one entry per artwork line it
     * covers: { line_item_id, product_name, ref }. A 3D line uses its UV decal
     * (customization.print_file_ref) when present, else that line's approved
     * proof. A UV line uses that line's approved proof artwork. A line with no
     * approved proof is a gate violation - the order can't be READY unless every
     * artwork line is approved-or-dropped - so throw.
     *
     * @param  Collection<int, LineItem>  $lines
     * @return array<int, array{line_item_id: int, product_name: string, ref: string}>
     */
    private function resolveArtworkRefs(JobTrack $track, Collection $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            if (! $line->needsProof()) {
                continue; // plain stock line in a batched group - nothing to print
            }
            $ref = null;
            if ($track === JobTrack::ThreeD) {
                $printFileRef = $line->customization['print_file_ref'] ?? null;
                $ref = is_string($printFileRef) && $printFileRef !== '' ? $printFileRef : null;
            }
            if ($ref === null) {
                $approved = $line->proofs()->where('state', ProofState::Approved->value)->orderByDesc('version')->first();
                if ($approved === null) {
                    throw new RuntimeException("Line {$line->id} has no approved proof; cannot build its print file.");
                }
                $ref = $approved->artwork_version_ref;
            }
            $out[] = [
                'line_item_id' => $line->id,
                'product_name' => (string) ($line->product?->name ?? "Line #{$line->id}"),
                'ref' => $ref,
            ];
        }

        return $out;
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
            if ($job->state->canTransitionTo($target) && ! $this->isReshipOnlyTransition($job, $target)) {
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
     * @throws DomainRuleException when the next state is SHIPPED
     */
    public function advanceNext(ProductionJob $job): ProductionJob
    {
        $next = $job->state->nextStates()[0] ?? null;

        if ($next === null) {
            throw new DomainRuleException('This job has no further state to advance to.');
        }

        if ($next === JobState::Shipped) {
            throw new DomainRuleException(
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
        ?Carrier $carrier = null,
        ?string $labelUrl = null,
    ): ProductionJob {
        // Shipped -> InProduction exists on the enum ONLY for
        // resolveReturn()'s 'reship' disposition, which mutates the job
        // directly (clearing the stale consignment_ref/carrier/label_url/
        // courier-status fields first) rather than calling this method. A
        // plain advance (or a batch advance) must never be able to silently
        // bounce a job that's actually in transit back to production with
        // its old courier footprint still attached - that transition is
        // gated behind resolveReturn's own needsAttention guard instead.
        if ($this->isReshipOnlyTransition($job, $target)) {
            throw new DomainRuleException(
                'A shipped job can only return to production through the returned-parcel resolution, not a direct advance.'
            );
        }

        $from = $job->state->value;

        // Persisted in the same save as the state change (transitionTo saves).
        if ($target === JobState::Shipped && $consignmentRef !== null) {
            $job->consignment_ref = $consignmentRef;
            if ($carrier !== null) {
                $job->carrier = $carrier;
            }
            if ($labelUrl !== null) {
                $job->label_url = $labelUrl;
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

        // Buyer email: "your order is on its way". This is the only send site
        // for OrderMilestone::Shipped - unlike every other milestone it is not
        // driven off Quote::transitionTo() (there is no QuoteState for a job
        // being shipped), so without this call the milestone's copy and its
        // "enabled by default" flag simply never fire. transitionTo() above
        // already guarantees this is a genuine ...->SHIPPED move: JobState::
        // Shipped->canTransitionTo(Shipped) is false, so a second advance() to
        // SHIPPED on an already-shipped job throws before reaching here - one
        // send per job, guaranteed by the state machine rather than a separate
        // "already notified" flag.
        if ($target === JobState::Shipped && $job->quote !== null) {
            $this->notifier->send($job->quote, OrderMilestone::Shipped);
        }

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

    /**
     * True only for the SHIPPED -> IN_PRODUCTION move - legal on the JobState
     * enum solely so resolveReturn()'s 'reship' disposition can use it, never
     * for a plain/batch advance. See advance()'s and advanceBatch()'s use of
     * this guard.
     */
    private function isReshipOnlyTransition(ProductionJob $job, JobState $target): bool
    {
        return $job->state === JobState::Shipped && $target === JobState::InProduction;
    }

    /**
     * Staff resolution for a job NinjaVan reported returned/failed (see
     * NinjaVanStatusMapper's needsAttention family) - the gap this closes: a
     * returned job used to sit SHIPPED forever with no way for staff to move
     * it forward. Three dispositions:
     *
     *  - close: the parcel is written off/abandoned. Advances the job to
     *    CLOSED via the normal advance() path (audits, broadcasts, and
     *    closes the quote once every job on it is CLOSED - same as a
     *    delivered job).
     *  - reship: the courier's footprint is cleared (consignment_ref,
     *    carrier, label_url, last_courier_status/_at, delivered_at) and the
     *    job goes back to IN_PRODUCTION to re-queue; a later ship books a
     *    FRESH per-job NinjaVan number (NinjaVanTrackingNumber::forJob) -
     *    the old consignment_ref must be cleared first, since it is
     *    unique-indexed.
     *  - cancel_credit: routes through QuoteService::cancel() (Task 13),
     *    which voids any live invoice and mints a credit note for what was
     *    collected. The job itself is left SHIPPED - this disposition means
     *    "the order is cancelled", not "the job continues" - the quote's own
     *    Closed state (unreachable once Cancelled) is what stops it
     *    re-appearing anywhere active.
     *
     * Guarded to only ever act on a job that is both still SHIPPED and whose
     * last_courier_status is one of NinjaVanStatusMapper's needsAttention
     * labels - a normal in-transit job (or one with no courier event at all)
     * throws DomainRuleException (422) rather than silently no-opping.
     *
     * QuoteService is resolved from the container rather than constructor-
     * injected: QuoteService itself depends on QueueService (it drives
     * production-job queuing), so a constructor dependency the other way
     * would be circular.
     */
    public function resolveReturn(ProductionJob $job, string $disposition, ?string $note = null): ProductionJob
    {
        if ($job->state !== JobState::Shipped || ! NinjaVanStatusMapper::isNeedsAttentionLabel($job->last_courier_status)) {
            throw new DomainRuleException(
                'This job is not flagged as a returned/failed parcel awaiting resolution.'
            );
        }

        return match ($disposition) {
            'close' => $this->resolveReturnClose($job, $note),
            'reship' => $this->resolveReturnReship($job, $note),
            'cancel_credit' => $this->resolveReturnCancelCredit($job, $note),
            default => throw new DomainRuleException("Unknown return disposition: {$disposition}."),
        };
    }

    private function resolveReturnClose(ProductionJob $job, ?string $note): ProductionJob
    {
        return DB::transaction(function () use ($job, $note): ProductionJob {
            $lastCourierStatus = $job->last_courier_status;

            $job = $this->advance($job, JobState::Closed);

            $this->audit->log($job, 'production_job.return_resolved', [
                'last_courier_status' => $lastCourierStatus,
            ], [
                'disposition' => 'close',
                'note' => $note,
                'state' => $job->state->value,
            ]);

            return $job;
        });
    }

    private function resolveReturnReship(ProductionJob $job, ?string $note): ProductionJob
    {
        return DB::transaction(function () use ($job, $note): ProductionJob {
            $lastCourierStatus = $job->last_courier_status;

            // Clear the old courier footprint FIRST - consignment_ref is
            // unique-indexed, so a later ship() booking a fresh
            // NinjaVanTrackingNumber::forJob() value must not collide with
            // this now-abandoned one.
            $job->consignment_ref = null;
            $job->carrier = null;
            $job->label_url = null;
            $job->last_courier_status = null;
            $job->last_courier_status_at = null;
            $job->delivered_at = null;

            // transitionTo() saves the model - every dirty attribute above
            // (plus the state change) lands in one write.
            $job->transitionTo(JobState::InProduction);

            $this->audit->log($job, 'production_job.return_resolved', [
                'last_courier_status' => $lastCourierStatus,
            ], [
                'disposition' => 'reship',
                'note' => $note,
                'state' => $job->state->value,
            ]);

            Broadcasting::dispatch(fn () => ProductionQueueUpdated::dispatch($job, 'started'));

            return $job;
        });
    }

    private function resolveReturnCancelCredit(ProductionJob $job, ?string $note): ProductionJob
    {
        return DB::transaction(function () use ($job, $note): ProductionJob {
            $lastCourierStatus = $job->last_courier_status;

            $job->loadMissing('quote');

            if ($job->quote !== null) {
                app(QuoteService::class)->cancel($job->quote, $note);
            }

            $this->audit->log($job, 'production_job.return_resolved', [
                'last_courier_status' => $lastCourierStatus,
            ], [
                'disposition' => 'cancel_credit',
                'note' => $note,
                'state' => $job->state->value,
            ]);

            return $job->fresh() ?? $job;
        });
    }
}
