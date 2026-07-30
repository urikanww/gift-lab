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
use App\Models\Shipment;
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

                // 1:1 shipment per job (Stage 2a). Each job books its own
                // consignment on ship, so it needs its own shipment to carry
                // the courier fields. Phase 2b collapses this to one shipment
                // per quote (the seam this line creates).
                $shipment = Shipment::create(['quote_id' => $quote->id]);
                $job->shipment()->associate($shipment)->save();

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
            ->with(['quote', 'shipment', 'lineItems.product.modelParts'])
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

        // Courier fields now live on the job's shipment (Stage 2a). Written and
        // saved on the shipment here, before the job's own state save below, so
        // the SHIPPED transition and its consignment land together. Both the
        // manual /advance endpoint and ShipmentService's courier flow route
        // through here, so this is the single write site.
        if ($target === JobState::Shipped && $consignmentRef !== null) {
            $shipment = $this->shipmentFor($job);
            $shipment->consignment_ref = $consignmentRef;
            if ($carrier !== null) {
                $shipment->carrier = $carrier;
            }
            if ($labelUrl !== null) {
                $shipment->label_url = $labelUrl;
            }
            $shipment->save();
        }

        $job->transitionTo($target);

        $this->audit->log(
            $job,
            'production_job.advanced',
            ['state' => $from],
            ['state' => $target->value, 'consignment_ref' => $job->shipment?->consignment_ref],
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
        // already guarantees this is a genuine ...->SHIPPED move (Shipped->
        // Shipped is not a legal edge, so it throws before reaching here).
        //
        // L13: this is NOT a strict once-per-job invariant. A reshipped parcel
        // legitimately passes SHIPPED again (SHIPPED->IN_PRODUCTION->SHIPPED via
        // resolveReturnReship), and re-notifying is correct - it really is on its
        // way again. What we DO dedupe is the parcel-split case just below (M19):
        // one "on its way" email per ORDER, not one per parcel.
        //
        // Deferred via DB::afterCommit: this is the ONLY milestone send site
        // that isn't reached through stateChangedAfterCommit, and advance() is
        // itself sometimes called inside a caller's own DB::transaction (see
        // ShipmentService::createForJob). Sending eagerly here would let an
        // async queue worker pick the mail up before the SHIPPED state/
        // consignment_ref actually commits (null tracking in the email), or
        // send a "your order shipped" email for a shipment whose transaction
        // then rolls back. afterCommit is a safe no-op when advance() is
        // called outside any transaction (e.g. the plain /advance endpoint) -
        // Laravel runs the callback immediately in that case.
        //
        // Context is captured as scalars right now, not the $job model itself:
        // OrderMilestoneMail is ShouldQueue + SerializesModels, and by the time
        // a worker dequeues it the job row may have moved on.
        if ($target === JobState::Shipped && $job->quote !== null) {
            $quote = $job->quote;

            // M19: one "on its way" email per ORDER, not per parcel. A
            // parcel-split order builds several jobs; only the first to ship
            // notifies the buyer. Every other milestone is order-level, so this
            // one should be too. (This job is already SHIPPED here, so we look
            // for ANOTHER job on the quote already shipped/closed.)
            $anotherAlreadyShipped = $quote->jobs()
                ->whereKeyNot($job->id)
                ->whereIn('state', [JobState::Shipped->value, JobState::Closed->value])
                ->exists();

            if (! $anotherAlreadyShipped) {
                $shipment = $job->shipment;
                $consignmentRef = $shipment?->consignment_ref;
                $context = [
                    'consignment_ref' => $consignmentRef,
                    'carrier_label' => $shipment?->carrier?->label(),
                    'tracking_url' => $consignmentRef !== null ? $shipment?->carrier?->trackingUrl($consignmentRef) : null,
                ];

                DB::afterCommit(fn () => $this->notifier->send($quote, OrderMilestone::Shipped, $context));
            }
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
     * The job's shipment - the row that now carries its courier fields. Every
     * job built via buildJobsForQuote already has one (1:1); this defensively
     * creates one for a legacy/factory job that reaches a courier write without
     * a shipment, so the SHIPPED write always has somewhere to land.
     */
    private function shipmentFor(ProductionJob $job): Shipment
    {
        $shipment = $job->shipment;
        if ($shipment === null) {
            $shipment = Shipment::create(['quote_id' => $job->quote_id]);
            $job->shipment()->associate($shipment)->save();
        }

        return $shipment;
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
     *  - reship: the courier's footprint is cleared on the shipment
     *    (consignment_ref, carrier, label_url, last_courier_status/_at,
     *    delivered_at) and the job goes back to IN_PRODUCTION to re-queue; a
     *    later ship books a FRESH NinjaVan number
     *    (NinjaVanTrackingNumber::forShipment) - the old consignment_ref must be
     *    cleared first, since it is unique-indexed.
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
    /**
     * Human marker written to last_courier_status when a staff member confirms
     * delivery by hand. Distinct from any NinjaVan status label so the tracker
     * and status history read honestly: this delivery was staff-confirmed, not
     * courier-reported.
     */
    public const MANUAL_DELIVERED_STATUS = 'Delivered (confirmed by staff)';

    /**
     * Staff fallback for a delivery that really happened but whose NinjaVan
     * webhook never arrived (or the account has no webhook configured): mark the
     * SHIPPED job delivered, which closes it and - if it's the order's last job -
     * closes the quote, firing the buyer's "delivered" milestone exactly as the
     * webhook path would. A parcel the courier flagged as returned/failed is NOT
     * eligible here (that's a different outcome) - it routes to resolveReturn().
     */
    public function markDelivered(ProductionJob $job, ?string $note = null): ProductionJob
    {
        if ($job->state !== JobState::Shipped) {
            throw new DomainRuleException(
                'Only a shipped job can be marked delivered.'
            );
        }

        if (NinjaVanStatusMapper::isNeedsAttentionLabel($job->shipment?->last_courier_status)) {
            throw new DomainRuleException(
                'This parcel is flagged as returned/failed by the courier; resolve it through the returned-parcel resolution instead of marking it delivered.'
            );
        }

        return DB::transaction(function () use ($job, $note): ProductionJob {
            // Shipment-then-job lock order, matching the NinjaVan webhook
            // (which locks the shipment then its member jobs). Acquiring the
            // shipment lock FIRST here means a Delivered webhook racing this
            // manual confirmation serializes on the shipment row instead of
            // deadlocking on an ABBA lock inversion (webhook: shipment->job vs.
            // an old job->shipment order here).
            $lockedShipment = $job->shipment_id !== null
                ? Shipment::query()->whereKey($job->shipment_id)->lockForUpdate()->first()
                : null;

            // L16: lock the job and RE-READ its state under the lock, mirroring
            // the webhook's TOCTOU guard. A delivered webhook racing this manual
            // confirmation also takes these locks; whichever commits second
            // re-reads the now-CLOSED job here and no-ops instead of advancing
            // again (which would fire a second "delivered" email).
            $locked = ProductionJob::query()->whereKey($job->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->state !== JobState::Shipped) {
                // The other path already closed it - idempotent no-op.
                return $locked;
            }

            // Courier fields live on the shipment now; stamp the manual-
            // confirmation marker there BEFORE advancing, so the row the
            // tracker reads reflects a staff-confirmed delivery. Reuse the row
            // already locked above when present.
            if ($lockedShipment !== null) {
                $locked->setRelation('shipment', $lockedShipment);
                $shipment = $lockedShipment;
            } else {
                $shipment = $this->shipmentFor($locked);
            }
            $previousCourierStatus = $shipment->last_courier_status;

            $shipment->last_courier_status = self::MANUAL_DELIVERED_STATUS;
            $shipment->last_courier_status_at = now();
            $shipment->save();

            // Same close path the webhook uses: closes the job, closes the quote
            // when it's the last one, and fires the delivered milestone email.
            $locked = $this->advance($locked, JobState::Closed);

            $this->audit->log($locked, 'production_job.manually_delivered', [
                'last_courier_status' => $previousCourierStatus,
                'state' => JobState::Shipped->value,
            ], [
                'note' => $note,
                'state' => $locked->state->value,
            ]);

            return $locked;
        });
    }

    /**
     * Jobs in transit: SHIPPED but not yet delivered/closed, newest first. Not
     * part of the FCFS production board (those are READY/IN_PRODUCTION) - this
     * is the "awaiting delivery" list staff use to manually confirm delivery
     * when the courier webhook is silent. Soft-deleted/cancelled quotes are
     * excluded via whereHas (respects the parent SoftDeletes scope).
     *
     * @return Collection<int, ProductionJob>
     */
    public function inTransit(): Collection
    {
        return ProductionJob::query()
            ->where('state', JobState::Shipped->value)
            // Returned/failed parcels leave this list for the Needs-attention
            // surface - they can't be "marked delivered" (the backend rejects
            // it), so they don't belong on the awaiting-delivery board. The
            // courier status lives on the shipment now (Stage 2a): a job with no
            // shipment, or one whose shipment status is null/non-attention,
            // still belongs on the awaiting-delivery board.
            ->whereDoesntHave('shipment', fn ($s) => $s
                ->whereIn('last_courier_status', NinjaVanStatusMapper::NEEDS_ATTENTION_LABELS))
            ->whereHas('quote')
            ->with(['quote', 'shipment', 'lineItems.product'])
            ->orderByDesc('updated_at')
            ->get();
    }

    /**
     * Parcels NinjaVan reported returned/attempt-failed: SHIPPED jobs whose
     * last courier status is one of NinjaVanStatusMapper's needsAttention
     * labels. The Needs-attention surface; each is resolved via resolveReturn
     * (reship / close / cancel-credit). Cancelled/soft-deleted quotes excluded.
     *
     * @return Collection<int, ProductionJob>
     */
    public function needsAttention(): Collection
    {
        return ProductionJob::query()
            ->where('state', JobState::Shipped->value)
            ->whereHas('shipment', fn ($s) => $s
                ->whereIn('last_courier_status', NinjaVanStatusMapper::NEEDS_ATTENTION_LABELS))
            ->whereHas('quote')
            ->with(['quote', 'shipment', 'lineItems.product'])
            ->get()
            // The status_at that orders this board lives on the shipment now;
            // newest-flagged first, sorted in PHP since it is a related column
            // (the needs-attention list is small).
            ->sortByDesc(fn (ProductionJob $job): ?string => $job->shipment?->last_courier_status_at?->toIso8601String())
            ->values();
    }

    public function resolveReturn(ProductionJob $job, string $disposition, ?string $note = null): ProductionJob
    {
        if ($job->state !== JobState::Shipped || ! NinjaVanStatusMapper::isNeedsAttentionLabel($job->shipment?->last_courier_status)) {
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
            $lastCourierStatus = $job->shipment?->last_courier_status;

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
            $shipment = $job->shipment;
            $lastCourierStatus = $shipment?->last_courier_status;

            // Clear the old courier footprint FIRST - consignment_ref is
            // unique-indexed (on shipments now), so a later ship() booking a
            // fresh NinjaVanTrackingNumber::forShipment() value must not collide
            // with this now-abandoned one. The footprint lives on the shipment.
            if ($shipment !== null) {
                $shipment->consignment_ref = null;
                $shipment->carrier = null;
                $shipment->label_url = null;
                $shipment->last_courier_status = null;
                $shipment->last_courier_status_at = null;
                $shipment->delivered_at = null;
                $shipment->save();
            }

            // transitionTo() saves the job (state change only now - the courier
            // footprint that was cleared above lives on the shipment).
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
            $lastCourierStatus = $job->shipment?->last_courier_status;

            $job->loadMissing('quote');
            $quote = $job->quote;

            if ($quote !== null) {
                // M15: does any SIBLING parcel still stand (delivered or in
                // flight, i.e. not itself already returned)? If so, cancel &
                // credit ONLY this parcel and leave the order live. If this is
                // the only/last live parcel, fall back to the whole-order cancel.
                $siblingStillLive = $quote->jobs()
                    ->whereKeyNot($job->getKey())
                    ->where('state', '!=', JobState::Returned->value)
                    ->exists();

                if ($siblingStillLive) {
                    app(QuoteService::class)->returnParcel($quote, $job, $note);
                } else {
                    // Mark the parcel returned before the whole-order cancel so
                    // it leaves the awaiting-delivery board and isn't faked as
                    // delivered; cancel() then closes the money loop.
                    $job->transitionTo(JobState::Returned);
                    app(QuoteService::class)->cancel($quote, $note);
                }
            }

            $this->audit->log($job, 'production_job.return_resolved', [
                'last_courier_status' => $lastCourierStatus,
            ], [
                'disposition' => 'cancel_credit',
                'note' => $note,
                'state' => $job->fresh()?->state->value ?? $job->state->value,
            ]);

            $fresh = $job->fresh() ?? $job;

            $fresh->loadMissing('quote');
            if ($fresh->quote !== null) {
                Broadcasting::dispatch(fn () => ProductionQueueUpdated::dispatch($fresh, 'returned'));
            }

            return $fresh;
        });
    }
}
