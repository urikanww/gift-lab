<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\LineItemState;
use App\Enums\PaymentState;
use App\Enums\ProofState;
use App\Enums\QuoteState;
use App\Enums\StockMovementReason;
use App\Enums\UserRole;
use App\Events\ProofStatusChanged;
use App\Events\QuoteStateChanged;
use App\Exceptions\DomainRuleException;
use App\Mail\QuoteReadyMail;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Proof;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Variant;
use App\Services\Procurement\ProcurementManager;
use App\Support\Broadcasting;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

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
        private readonly StockLedger $ledger,
    ) {}

    /**
     * Create a DRAFT quote from designer line specs, pricing every line from
     * dynamic config and freezing a price/spec snapshot per line (spec 6.4).
     *
     * @param  array<int, array{product_id: int, variant_id: ?int, qty: int, customization: ?array<string, mixed>}>  $lineSpecs
     */
    public function create(int $companyId, array $lineSpecs, ?string $notes, ?string $neededBy = null, ?string $idempotencyKey = null, ?array $shipping = null): Quote
    {
        // Replay of an already-submitted cart (double-click / network retry)
        // returns the original draft instead of minting a duplicate (audit A12).
        if ($idempotencyKey !== null) {
            $existing = Quote::query()
                ->where('company_id', $companyId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                return $existing->load('lineItems');
            }
        }

        try {
            return $this->createFresh($companyId, $lineSpecs, $notes, $neededBy, $idempotencyKey, $shipping);
        } catch (UniqueConstraintViolationException $e) {
            // Two identical submits raced past the lookup; the loser lands
            // here and returns the winner's quote.
            if ($idempotencyKey === null) {
                throw $e;
            }

            return Quote::query()
                ->where('company_id', $companyId)
                ->where('idempotency_key', $idempotencyKey)
                ->firstOrFail()
                ->load('lineItems');
        }
    }

    /**
     * @param  array<int, array{product_id: int, variant_id: ?int, qty: int, customization: ?array<string, mixed>}>  $lineSpecs
     */
    private function createFresh(int $companyId, array $lineSpecs, ?string $notes, ?string $neededBy, ?string $idempotencyKey, ?array $shipping): Quote
    {
        return DB::transaction(function () use ($companyId, $lineSpecs, $notes, $neededBy, $idempotencyKey, $shipping): Quote {
            // Batch-load products/variants once (two queries) instead of one
            // query per line - same pattern as PriceEstimateController.
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
                    throw (new ModelNotFoundException)->setModel(Product::class, [(int) $spec['product_id']]);
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
                    'has_text' => ! empty($r['customization']['text']),
                ],
                $resolved,
            ));

            $quote = Quote::create([
                'company_id' => $companyId,
                'idempotency_key' => $idempotencyKey,
                'state' => QuoteState::Draft->value,
                'currency' => 'SGD',
                'subtotal' => $totals['subtotal'],
                'delivery' => $totals['delivery'],
                'total' => $totals['total'],
                'notes' => $notes,
                'needed_by' => $neededBy,
                'created_by' => Auth::id(),
            ]);

            // Snapshot the buyer's ship-to as its own row on the quote. Text is
            // copied here, not referenced - a later edit to a saved address must
            // never mutate this placed order. Staff may omit it, in which case
            // shippingAddressOrDefault() keeps returning the company default.
            if ($shipping !== null) {
                $quote->shippingAddress()->create([
                    'recipient_name' => $shipping['recipient_name'],
                    'phone' => $shipping['phone'],
                    'email' => $shipping['email'] ?? null,
                    'line1' => $shipping['line1'],
                    'line2' => $shipping['line2'] ?? null,
                    'city' => $shipping['city'] ?? null,
                    'state' => $shipping['state'] ?? null,
                    'postal_code' => $shipping['postal_code'],
                    'country' => ($shipping['country'] ?? null) ?: 'SG',
                    'notes' => $shipping['notes'] ?? null,
                ]);
            }

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
    public function send(Quote $quote, ?string $artworkRef = null, ?string $proofNotes = null): Quote
    {
        if ($quote->state !== QuoteState::Draft) {
            throw new DomainRuleException('Only DRAFT quotes can be sent.');
        }

        return DB::transaction(function () use ($quote, $artworkRef, $proofNotes): Quote {
            $previous = $quote->state->value;
            $quote->price_snapshot_at = now();
            $quote->save();

            if ($artworkRef !== null) {
                $quote->transitionTo(QuoteState::Proofing);          // slim path
                $this->createProofVersion($quote, $artworkRef, $proofNotes);
                $this->emailQuoteReady($quote, true);
            } else {
                $quote->transitionTo(QuoteState::Sent);
                $this->emailQuoteReady($quote, false);
            }

            DB::afterCommit(fn () => Broadcasting::dispatch(fn () => QuoteStateChanged::dispatch($quote, $previous)));

            return $quote;
        });
    }

    public function accept(Quote $quote): Quote
    {
        return DB::transaction(function () use ($quote): Quote {
            $previous = $quote->state->value;
            $quote->accepted_at = now();
            $quote->accepted_by = Auth::id();
            $quote->save();
            $quote->transitionTo(QuoteState::Accepted);
            DB::afterCommit(fn () => Broadcasting::dispatch(fn () => QuoteStateChanged::dispatch($quote, $previous)));

            return $quote;
        });
    }

    /**
     * Staff issues a proof. First proof moves ACCEPTED -> PROOFING; subsequent
     * proofs (after a change request) increment the version on the same quote.
     */
    public function issueProof(Quote $quote, string $artworkRef, ?string $notes): Proof
    {
        return DB::transaction(function () use ($quote, $artworkRef, $notes): Proof {
            $enteredProofing = false;
            if ($quote->state === QuoteState::Accepted) {
                $previous = $quote->state->value;
                $quote->transitionTo(QuoteState::Proofing);
                DB::afterCommit(fn () => Broadcasting::dispatch(fn () => QuoteStateChanged::dispatch($quote, $previous)));
                $enteredProofing = true;
            }

            if ($quote->state !== QuoteState::Proofing) {
                throw new DomainRuleException('Quote must be ACCEPTED or PROOFING to issue a proof.');
            }

            $proof = $this->createProofVersion($quote, $artworkRef, $notes);

            if ($enteredProofing) {
                $this->emailQuoteReady($quote, true);
            }

            return $proof;
        });
    }

    /**
     * Create the next proof version row for a quote and broadcast it. Shared by
     * issueProof (ACCEPTED/PROOFING) and the slim send path (DRAFT -> PROOFING).
     */
    private function createProofVersion(Quote $quote, string $artworkRef, ?string $notes): Proof
    {
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
            if ($quote->accepted_at === null) {
                $quote->accepted_at = now();
                $quote->accepted_by = Auth::id();
                $quote->save();
            }
            $previous = $quote->state->value;
            $quote->transitionTo(QuoteState::ProofApproved);

            DB::afterCommit(fn () => Broadcasting::dispatch(fn () => ProofStatusChanged::dispatch($proof, $quote->company_id)));
            DB::afterCommit(fn () => Broadcasting::dispatch(fn () => QuoteStateChanged::dispatch($quote, $previous)));

            return $proof;
        });
    }

    /**
     * Buyer requests changes: proof -> CHANGES_REQUESTED. On the existing/accepted
     * path (accepted_at set) the quote stays PROOFING so staff can issue a new proof
     * version. On the slim path (accepted_at null) the rejection may concern price or
     * artwork, so the quote advances to CHANGES_REQUESTED for staff triage.
     */
    public function requestProofChanges(Proof $proof, ?string $notes): Proof
    {
        return DB::transaction(function () use ($proof, $notes): Proof {
            if ($notes !== null) {
                $proof->notes = $notes;
            }
            $proof->transitionTo(ProofState::ChangesRequested);

            $quote = $proof->quote;
            // Slim path: price was never separately accepted, so the rejection may be
            // about price or artwork -> send to CHANGES_REQUESTED for staff triage.
            // Existing path (accepted_at set): artwork-only revision -> stay PROOFING.
            if ($quote->accepted_at === null && $quote->state === QuoteState::Proofing) {
                $previous = $quote->state->value;
                $quote->transitionTo(QuoteState::ChangesRequested);
                DB::afterCommit(fn () => Broadcasting::dispatch(fn () => QuoteStateChanged::dispatch($quote, $previous)));
            }

            DB::afterCommit(fn () => Broadcasting::dispatch(fn () => ProofStatusChanged::dispatch($proof, $quote->company_id)));

            return $proof;
        });
    }

    /**
     * Staff issues the invoice: quote PROOF_APPROVED -> INVOICED -> CONFIRMED.
     */
    public function issueInvoice(Quote $quote, string $poRef, ?string $invoiceRef, ?string $terms): Invoice
    {
        return DB::transaction(function () use ($quote, $poRef, $invoiceRef, $terms): Invoice {
            $invoice = Invoice::create([
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
            $quote->transitionTo(QuoteState::Invoiced);
            $quote->transitionTo(QuoteState::Confirmed);
            DB::afterCommit(fn () => Broadcasting::dispatch(fn () => QuoteStateChanged::dispatch($quote, $previous)));

            $this->audit->log($invoice, 'invoice.issued', null, ['po_ref' => $poRef, 'amount' => $quote->total]);

            return $invoice;
        });
    }

    /**
     * Cancel a quote at any pre-production stage (Draft…Procuring). Terminal -
     * makes the CANCELLED state reachable so a buyer/staff can abandon a quote.
     * A READY/CLOSED quote is already on the floor and cannot be cancelled (the
     * state machine has no such edge; transitionTo throws).
     */
    public function cancel(Quote $quote, ?string $reason): Quote
    {
        return DB::transaction(function () use ($quote, $reason): Quote {
            $previous = $quote->state->value;
            $quote->transitionTo(QuoteState::Cancelled);

            // Give back any stock already consumed by this quote's lines. A quote
            // can be cancelled mid-PROCURING, after some CORE lines have SALE'd
            // their blanks - reverse exactly what each line took (backorder lines
            // included, which pulls a negative balance back toward zero).
            $this->returnConsumedStock($quote);

            $this->audit->log($quote, 'quote.cancelled', ['state' => $previous], ['reason' => $reason]);

            DB::afterCommit(fn () => Broadcasting::dispatch(fn () => QuoteStateChanged::dispatch($quote, $previous)));

            return $quote->fresh(['lineItems']);
        });
    }

    /**
     * Reverse the stock each line consumed, as compensating RETURN movements.
     * Reads the ledger (SALE movements referencing the line) rather than trusting
     * procured_qty, so it stays correct across partial/backorder consumption and
     * never double-returns.
     */
    private function returnConsumedStock(Quote $quote): void
    {
        $quote->loadMissing('lineItems.variant');

        foreach ($quote->lineItems as $line) {
            if ($line->variant === null) {
                continue;
            }

            $consumed = (int) StockMovement::query()
                ->where('ref_type', $line->getMorphClass())
                ->where('ref_id', $line->getKey())
                ->where('reason', StockMovementReason::Sale->value)
                ->sum('delta');

            // SALE deltas are negative; return the opposite. Nothing consumed → skip.
            if ($consumed < 0) {
                $this->ledger->record(
                    $line->variant,
                    -$consumed,
                    StockMovementReason::Return,
                    $line,
                    note: 'quote cancelled',
                );
            }
        }
    }

    /**
     * Run procurement across all pending lines (gate 2). Moves the quote into
     * PROCURING, procures each line, then queues jobs if everything resolved.
     */
    public function procure(Quote $quote): Quote
    {
        // transitionTo does two writes (the state save and the audit insert), so
        // it needs a transaction to stay atomic. Every sibling call site already
        // has one; this was the sole exception, and the one path where a failed
        // audit insert would commit the state while the caller saw an exception.
        if ($quote->state === QuoteState::Confirmed) {
            DB::transaction(function () use ($quote): void {
                $previous = $quote->state->value;
                $quote->transitionTo(QuoteState::Procuring);
                DB::afterCommit(fn () => Broadcasting::dispatch(fn () => QuoteStateChanged::dispatch($quote, $previous)));
            });
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
            // Money delta this decision introduces against the quote's frozen
            // totals. Tracked as a delta (not a full re-price) so the setup /
            // customization fees baked into the original subtotal survive.
            $totalDelta = 0.0;

            switch ($decision['action']) {
                case 'amend':
                    $before = (float) $line->lineTotal();
                    $line->qty = $decision['qty'];
                    $line->unit_price = $decision['unit_price'];
                    $line->save();
                    $line->transitionTo(LineItemState::Amended);
                    $this->procurement->procureLine($line);
                    $totalDelta = (float) $line->lineTotal() - $before;
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
                    $totalDelta = -(float) $line->lineTotal();
                    break;
            }

            $this->audit->log($line, 'line_item.reconfirmed', null, $decision);

            if (round($totalDelta, 2) !== 0.0) {
                $this->retotalAfterReconfirm($line, $totalDelta);
            }
        });

        $this->tryQueue($line->quote->fresh(['lineItems']));

        return $line->fresh();
    }

    /**
     * Re-anchor the quote's money figures (and any issued PO/invoice amount)
     * after a reconfirmation changed what will actually be produced. Without
     * this the buyer is invoiced for the pre-amend order while the floor
     * fulfils the amended one - the exact dispute the PO exists to prevent.
     */
    private function retotalAfterReconfirm(LineItem $line, float $totalDelta): void
    {
        $quote = $line->quote()->lockForUpdate()->first();

        $before = [
            'subtotal' => $quote->subtotal,
            'total' => $quote->total,
        ];

        $quote->subtotal = round((float) $quote->subtotal + $totalDelta, 2);
        $quote->total = round((float) $quote->subtotal + (float) $quote->delivery, 2);
        $quote->save();

        // The invoice amount was frozen at issue time; keep the authoritative
        // invoice figure in lock-step with the amended quote.
        $invoice = $quote->purchaseOrders()->latest('issued_at')->first();
        if ($invoice !== null) {
            $invoiceBefore = ['amount' => $invoice->amount];
            $invoice->amount = $quote->total;
            $invoice->save();
            $this->audit->log($invoice, 'invoice.retotaled', $invoiceBefore, ['amount' => $invoice->amount]);
        }

        $this->audit->log($quote, 'quote.retotaled_after_reconfirm', $before, [
            'subtotal' => $quote->subtotal,
            'total' => $quote->total,
            'line_item_id' => $line->id,
        ]);
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

    /**
     * Queue the buyer-facing "quote (and proof) ready" email. Fires only after
     * the enclosing transaction commits, so a rolled-back send never emails.
     * No-ops silently if no buyer recipient can be resolved for the company.
     */
    private function emailQuoteReady(Quote $quote, bool $hasProof): void
    {
        $recipient = $this->resolveBuyerRecipient($quote);
        if ($recipient === null) {
            return;
        }

        $proofImageUrl = null;
        if ($hasProof && ($proof = $quote->proofs()->latest('version')->first()) !== null) {
            $proofImageUrl = URL::temporarySignedRoute('proofs.image', now()->addDays(14), ['proof' => $proof->id]);
        }

        DB::afterCommit(fn () => Mail::to($recipient->email)->queue(
            new QuoteReadyMail($quote, $hasProof, $proofImageUrl, $recipient->name)
        ));
    }

    /**
     * Resolve the genuine buyer user to notify for this quote. The email CTA
     * links to the login-gated /quotes/{id} SPA route, so we only ever target
     * a real buyer account - never the company's shared billing_email inbox.
     */
    private function resolveBuyerRecipient(Quote $quote): ?User
    {
        // Self-service: the creator is a genuine buyer of this company -> notify them.
        $creator = $quote->creator;
        if ($creator !== null
            && $creator->email !== null
            && $creator->company_id === $quote->company_id) {
            return $creator;
        }

        // Staff-created (or creator isn't a company buyer): notify the company's
        // primary buyer contact - the earliest buyer user with an email. The CTA is
        // login-gated, so we target a real buyer account, not the shared billing_email.
        return User::query()
            ->where('company_id', $quote->company_id)
            ->where('role', UserRole::Buyer->value)
            ->whereNotNull('email')
            ->orderBy('id')
            ->first();
    }

    private function hasCustomization(?array $customization): bool
    {
        if ($customization === null) {
            return false;
        }

        return ! empty($customization['logo_size'])
            || ! empty($customization['artwork_ref'])
            || ! empty($customization['text']);
    }
}
