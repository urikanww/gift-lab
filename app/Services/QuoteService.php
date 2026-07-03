<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\LineItemState;
use App\Enums\PaymentState;
use App\Enums\ProofState;
use App\Enums\QuoteState;
use App\Events\ProofStatusChanged;
use App\Events\QuoteStateChanged;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Proof;
use App\Models\PurchaseOrder;
use App\Models\Quote;
use App\Models\Variant;
use App\Exceptions\DomainRuleException;
use App\Services\Procurement\ProcurementManager;
use App\Support\Broadcasting;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates the quote spine end to end. Controllers stay thin; every state
 * change is guarded by the model state machines and broadcast over Reverb so
 * the buyer and floor never poll.
 */
final class QuoteService
{
    public function __construct(
        private readonly PricingService $pricing,
        private readonly ProcurementManager $procurement,
        private readonly QueueService $queue,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Create a DRAFT quote from designer line specs, pricing every line from
     * dynamic config and freezing a price/spec snapshot per line (spec 6.4).
     *
     * @param  array<int, array{product_id: int, variant_id: ?int, qty: int, customization: ?array<string, mixed>}>  $lineSpecs
     */
    public function create(int $companyId, array $lineSpecs, ?string $notes, ?string $neededBy = null): Quote
    {
        return DB::transaction(function () use ($companyId, $lineSpecs, $notes, $neededBy): Quote {
            // Batch-load products/variants once (two queries) instead of one
            // query per line — same pattern as PriceEstimateController.
            $productIds = array_values(array_unique(array_map(
                static fn (array $spec): int => (int) $spec['product_id'],
                $lineSpecs,
            )));
            $variantIds = array_values(array_filter(array_unique(array_map(
                static fn (array $spec): ?int => isset($spec['variant_id']) ? (int) $spec['variant_id'] : null,
                $lineSpecs,
            )), static fn (?int $id): bool => $id !== null));

            $products = $productIds === []
                ? collect()
                : Product::query()->whereIn('id', $productIds)->get()->keyBy('id');
            $variants = $variantIds === []
                ? collect()
                : Variant::query()->whereIn('id', $variantIds)->get()->keyBy('id');

            $resolved = [];
            foreach ($lineSpecs as $spec) {
                $product = $products->get((int) $spec['product_id']);
                if ($product === null) {
                    // Preserve findOrFail semantics: a bad product id still 404s.
                    throw (new ModelNotFoundException())->setModel(Product::class, [(int) $spec['product_id']]);
                }
                $variant = isset($spec['variant_id']) ? $variants->get((int) $spec['variant_id']) : null;
                $customization = $spec['customization'] ?? null;

                $resolved[] = [
                    'product' => $product,
                    'variant' => $variant,
                    'qty' => (int) $spec['qty'],
                    'has_customization' => $this->hasCustomization($customization),
                    'customization' => $customization,
                ];
            }

            $totals = $this->pricing->quoteTotals(array_map(
                static fn (array $r): array => [
                    'product' => $r['product'],
                    'variant' => $r['variant'],
                    'qty' => $r['qty'],
                    'has_customization' => $r['has_customization'],
                    'logo_size' => $r['customization']['logo_size'] ?? null,
                ],
                $resolved,
            ));

            $quote = Quote::create([
                'company_id' => $companyId,
                'state' => QuoteState::Draft->value,
                'currency' => 'SGD',
                'subtotal' => $totals['subtotal'],
                'delivery' => $totals['delivery'],
                'total' => $totals['total'],
                'notes' => $notes,
                'needed_by' => $neededBy,
                'created_by' => Auth::id(),
            ]);

            foreach ($resolved as $index => $r) {
                LineItem::create([
                    'quote_id' => $quote->id,
                    'product_id' => $r['product']->id,
                    'variant_id' => $r['variant']?->id,
                    'qty' => $r['qty'],
                    'unit_price' => $totals['lines'][$index]['unit_price'],
                    'currency' => 'SGD',
                    'customization' => $r['customization'],
                    'line_state' => LineItemState::Pending->value,
                    'frozen_snapshot' => [
                        'product_name' => $r['product']->name,
                        'base_cost' => $r['product']->base_cost,
                        'price_delta' => $r['variant']?->price_delta,
                        'unit_price' => $totals['lines'][$index]['unit_price'],
                        'frozen_at' => now()->toIso8601String(),
                    ],
                ]);
            }

            return $quote->fresh(['lineItems']);
        });
    }

    /**
     * Staff amends line prices/quantities on a DRAFT quote. Margin floor is
     * enforced in the Form Request; here we re-total, log the amendment, and
     * record who/what/when.
     *
     * @param  array<int, array{id: int, unit_price: float, qty: int}>  $lineAmendments
     */
    public function amend(Quote $quote, array $lineAmendments, ?float $delivery, ?string $notes): Quote
    {
        if ($quote->state !== QuoteState::Draft) {
            throw new DomainRuleException('Only DRAFT quotes can be amended.');
        }

        return DB::transaction(function () use ($quote, $lineAmendments, $delivery, $notes): Quote {
            $before = ['subtotal' => $quote->subtotal, 'delivery' => $quote->delivery, 'total' => $quote->total];
            $log = $quote->amendment_log ?? [];
            $subtotal = 0.0;

            foreach ($lineAmendments as $amendment) {
                $line = $quote->lineItems()->findOrFail($amendment['id']);
                $log[] = [
                    'line_item_id' => $line->id,
                    'from' => ['unit_price' => $line->unit_price, 'qty' => $line->qty],
                    'to' => ['unit_price' => $amendment['unit_price'], 'qty' => $amendment['qty']],
                    'by' => Auth::id(),
                    'at' => now()->toIso8601String(),
                ];

                $line->unit_price = $amendment['unit_price'];
                $line->qty = $amendment['qty'];
                $line->save();

                $subtotal += (float) $line->lineTotal();
            }

            $quote->subtotal = round($subtotal, 2);
            $quote->delivery = $delivery ?? (float) $quote->delivery;
            $quote->total = round((float) $quote->subtotal + (float) $quote->delivery, 2);
            $quote->amendment_log = $log;
            $quote->amended_by = Auth::id();
            if ($notes !== null) {
                $quote->notes = $notes;
            }
            $quote->save();

            $this->audit->log($quote, 'quote.amended', $before, [
                'subtotal' => $quote->subtotal,
                'delivery' => $quote->delivery,
                'total' => $quote->total,
            ]);

            return $quote->fresh(['lineItems']);
        });
    }

    /**
     * Send the quote to the buyer, freezing the price snapshot timestamp.
     */
    public function send(Quote $quote): Quote
    {
        $previous = $quote->state->value;
        $quote->price_snapshot_at = now();
        $quote->save();
        $quote->transitionTo(QuoteState::Sent);

        Broadcasting::dispatch(fn () => QuoteStateChanged::dispatch($quote, $previous));

        return $quote;
    }

    public function accept(Quote $quote): Quote
    {
        $previous = $quote->state->value;
        $quote->transitionTo(QuoteState::Accepted);
        Broadcasting::dispatch(fn () => QuoteStateChanged::dispatch($quote, $previous));

        return $quote;
    }

    /**
     * Staff issues a proof. First proof moves ACCEPTED -> PROOFING; subsequent
     * proofs (after a change request) increment the version on the same quote.
     */
    public function issueProof(Quote $quote, string $artworkRef, ?string $notes): Proof
    {
        return DB::transaction(function () use ($quote, $artworkRef, $notes): Proof {
            if ($quote->state === QuoteState::Accepted) {
                $previous = $quote->state->value;
                $quote->transitionTo(QuoteState::Proofing);
                DB::afterCommit(fn () => Broadcasting::dispatch(fn () => QuoteStateChanged::dispatch($quote, $previous)));
            }

            if ($quote->state !== QuoteState::Proofing) {
                throw new DomainRuleException('Quote must be ACCEPTED or PROOFING to issue a proof.');
            }

            $nextVersion = ((int) $quote->proofs()->max('version')) + 1;

            $proof = Proof::create([
                'quote_id' => $quote->id,
                'version' => $nextVersion,
                'artwork_version_ref' => $artworkRef,
                'state' => ProofState::Sent->value,
                'notes' => $notes,
            ]);

            DB::afterCommit(fn () => Broadcasting::dispatch(fn () => ProofStatusChanged::dispatch($proof, $quote->company_id)));

            return $proof;
        });
    }

    /**
     * Buyer approves a proof: immutable sign-off + quote -> PROOF_APPROVED.
     */
    public function approveProof(Proof $proof): Proof
    {
        return DB::transaction(function () use ($proof): Proof {
            $proof->approved_by = Auth::id();
            $proof->approved_at = now();
            $proof->transitionTo(ProofState::Approved);

            $this->audit->log($proof, 'proof.approved', null, [
                'version' => $proof->version,
                'artwork_version_ref' => $proof->artwork_version_ref,
                'approved_by' => $proof->approved_by,
            ]);

            $quote = $proof->quote;
            $previous = $quote->state->value;
            $quote->transitionTo(QuoteState::ProofApproved);

            DB::afterCommit(fn () => Broadcasting::dispatch(fn () => ProofStatusChanged::dispatch($proof, $quote->company_id)));
            DB::afterCommit(fn () => Broadcasting::dispatch(fn () => QuoteStateChanged::dispatch($quote, $previous)));

            return $proof;
        });
    }

    /**
     * Buyer requests changes: proof -> CHANGES_REQUESTED. Quote stays PROOFING
     * so staff can issue a new proof version bound to new artwork.
     */
    public function requestProofChanges(Proof $proof, ?string $notes): Proof
    {
        if ($notes !== null) {
            $proof->notes = $notes;
        }
        $proof->transitionTo(ProofState::ChangesRequested);

        Broadcasting::dispatch(fn () => ProofStatusChanged::dispatch($proof, $proof->quote->company_id));

        return $proof;
    }

    /**
     * Staff issues the PO/invoice: quote PROOF_APPROVED -> PO_ISSUED -> CONFIRMED.
     */
    public function issuePurchaseOrder(Quote $quote, string $poRef, ?string $invoiceRef, ?string $terms): PurchaseOrder
    {
        return DB::transaction(function () use ($quote, $poRef, $invoiceRef, $terms): PurchaseOrder {
            $po = PurchaseOrder::create([
                'quote_id' => $quote->id,
                'po_ref' => $poRef,
                'invoice_ref' => $invoiceRef,
                'terms' => $terms ?? $quote->company->default_terms,
                'payment_state' => PaymentState::Unpaid->value,
                'amount' => $quote->total,
                'currency' => $quote->currency,
                'issued_by' => Auth::id(),
                'issued_at' => now(),
            ]);

            $previous = $quote->state->value;
            $quote->transitionTo(QuoteState::PoIssued);
            $quote->transitionTo(QuoteState::Confirmed);
            DB::afterCommit(fn () => Broadcasting::dispatch(fn () => QuoteStateChanged::dispatch($quote, $previous)));

            $this->audit->log($po, 'purchase_order.issued', null, ['po_ref' => $poRef, 'amount' => $quote->total]);

            return $po;
        });
    }

    /**
     * Cancel a quote at any pre-production stage (Draft…Procuring). Terminal —
     * makes the CANCELLED state reachable so a buyer/staff can abandon a quote.
     * A READY/CLOSED quote is already on the floor and cannot be cancelled (the
     * state machine has no such edge; transitionTo throws).
     */
    public function cancel(Quote $quote, ?string $reason): Quote
    {
        return DB::transaction(function () use ($quote, $reason): Quote {
            $previous = $quote->state->value;
            $quote->transitionTo(QuoteState::Cancelled);

            $this->audit->log($quote, 'quote.cancelled', ['state' => $previous], ['reason' => $reason]);

            DB::afterCommit(fn () => Broadcasting::dispatch(fn () => QuoteStateChanged::dispatch($quote, $previous)));

            return $quote->fresh(['lineItems']);
        });
    }

    /**
     * Run procurement across all pending lines (gate 2). Moves the quote into
     * PROCURING, procures each line, then queues jobs if everything resolved.
     */
    public function procure(Quote $quote): Quote
    {
        if ($quote->state === QuoteState::Confirmed) {
            $previous = $quote->state->value;
            $quote->transitionTo(QuoteState::Procuring);
            Broadcasting::dispatch(fn () => QuoteStateChanged::dispatch($quote, $previous));
        }

        if ($quote->state !== QuoteState::Procuring) {
            throw new DomainRuleException('Quote must be CONFIRMED or PROCURING to run procurement.');
        }

        // Eager-load product + variant so procureLine()/strategies don't fire a
        // query per line (N+1) when resolving class and landed cost.
        $quote->loadMissing('lineItems.product', 'lineItems.variant');

        foreach ($quote->lineItems as $line) {
            if ($line->line_state === LineItemState::Pending || $line->line_state === LineItemState::Amended) {
                $this->procurement->procureLine($line);
            }
        }

        $this->tryQueue($quote->fresh(['lineItems']));

        return $quote->fresh(['lineItems', 'jobs']);
    }

    /**
     * Resolve a line stuck in AWAITING_RECONFIRM, then attempt to queue.
     *
     * @param  array{action: string, qty?: int, unit_price?: float}  $decision
     */
    public function reconfirmLine(LineItem $line, array $decision): LineItem
    {
        if ($line->line_state !== LineItemState::AwaitingReconfirm) {
            throw new DomainRuleException('Line item is not awaiting reconfirmation.');
        }

        DB::transaction(function () use ($line, $decision): void {
            switch ($decision['action']) {
                case 'amend':
                    $line->qty = $decision['qty'];
                    $line->unit_price = $decision['unit_price'];
                    $line->save();
                    $line->transitionTo(LineItemState::Amended);
                    $this->procurement->procureLine($line);
                    break;

                case 'approve':
                    // Accept the jumped price / short qty as-is and complete.
                    $line->transitionTo(LineItemState::Purchased);
                    $line->transitionTo(LineItemState::Inbound);
                    $line->transitionTo(LineItemState::Received);
                    $line->transitionTo(LineItemState::Ready);
                    break;

                case 'drop':
                    $line->transitionTo(LineItemState::Dropped);
                    break;
            }

            $this->audit->log($line, 'line_item.reconfirmed', null, $decision);
        });

        $this->tryQueue($line->quote->fresh(['lineItems']));

        return $line->fresh();
    }

    /**
     * Queue the quote's jobs once every line is resolved (READY or DROPPED),
     * provided at least one line is READY. A wholly-dropped quote is not queued.
     */
    private function tryQueue(Quote $quote): void
    {
        $quote->loadMissing('lineItems');

        $allResolved = $quote->lineItems->every(
            fn ($line): bool => $line->line_state->isResolvedForQueue()
        );
        $anyReady = $quote->lineItems->contains(
            fn ($line): bool => $line->line_state === LineItemState::Ready
        );

        if ($allResolved && $anyReady && $quote->state === QuoteState::Procuring) {
            $this->queue->buildJobsForQuote($quote);
        }
    }

    private function hasCustomization(?array $customization): bool
    {
        if ($customization === null) {
            return false;
        }

        return ! empty($customization['logo_size'])
            || ! empty($customization['artwork_ref']);
    }
}
