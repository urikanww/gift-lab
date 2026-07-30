<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ApprovalOrder;
use App\Enums\JobState;
use App\Enums\LineItemState;
use App\Enums\OrderMilestone;
use App\Enums\PaymentState;
use App\Enums\ProofState;
use App\Enums\QuoteState;
use App\Enums\StockMovementReason;
use App\Enums\UserRole;
use App\Events\ProofStatusChanged;
use App\Events\QuoteStateChanged;
use App\Exceptions\DomainRuleException;
use App\Mail\QuoteReadyMail;
use App\Models\CreditNote;
use App\Models\Filament;
use App\Models\Invoice;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\ProductionJob;
use App\Models\Proof;
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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

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
        private readonly OrderNotifier $notifier,
        private readonly StaffNotifier $staffNotifier,
        private readonly ProofCompositeService $composites,
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
     * Clone a past order into a fresh DRAFT, re-priced at today's config (GST
     * threads through create() automatically, same as any other new quote).
     *
     * Only line SPECS are cloned - product_id, variant_id, qty, customization
     * - from lines that are neither Dropped nor Cancelled. Everything else
     * (proofs, invoice, jobs, shipping address, adjustments, amendment log,
     * state, subtotal/total) is deliberately left behind: this mints a new
     * order, not a resurrection of the old one.
     *
     * Lines whose product no longer exists (hard-deleted) or was soft-deleted
     * since the original order are dropped BEFORE calling create(): that
     * method resolves products via a batched whereIn, so a single stale
     * product id would 404 the whole reorder instead of just skipping one
     * line.
     */
    public function reorder(Quote $source): Quote
    {
        $lines = $source->lineItems()
            ->whereNotIn('line_state', [LineItemState::Dropped->value, LineItemState::Cancelled->value])
            ->get();

        $productIds = $lines->pluck('product_id')->unique()->values();
        // L21: only re-order products that are still BUYABLE today (published +
        // orderable), not merely still-existing. buyable() also excludes
        // soft-deleted rows, so a product that was unpublished / pulled from sale
        // since the original order is skipped rather than silently re-priced and
        // re-ordered off a listing the buyer can no longer reach.
        $survivingProductIds = $productIds->isEmpty()
            ? collect()
            : Product::query()->buyable()->whereIn('id', $productIds)->pluck('id');

        // Same for variants (also SoftDeletes): a line whose variant was removed
        // must be skipped, not re-created variant-less (which would silently
        // re-price off the base product). Only lines with a variant_id are
        // checked; a variant-less line is unaffected.
        $variantIds = $lines->pluck('variant_id')->filter()->unique()->values();
        $survivingVariantIds = $variantIds->isEmpty()
            ? collect()
            : Variant::query()->whereIn('id', $variantIds)->pluck('id');

        $specs = $lines
            ->filter(fn (LineItem $line): bool => $survivingProductIds->contains($line->product_id)
                && ($line->variant_id === null || $survivingVariantIds->contains($line->variant_id)))
            ->map(fn (LineItem $line): array => [
                'product_id' => $line->product_id,
                'variant_id' => $line->variant_id,
                'qty' => $line->qty,
                'customization' => $line->customization,
            ])
            ->values()
            ->all();

        if ($specs === []) {
            throw new DomainRuleException('This order has no lines left to reorder.');
        }

        // Fresh draft: no notes, needed-by, idempotency key or shipping carried
        // over - a reorder is a new order, not a resubmission of the old one.
        return $this->create($source->company_id, $specs, null, null, null, null);
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
                // Rate is SNAPSHOT here, at create time: gst_rate is copied onto
                // the row rather than re-read from live config, so a later edit
                // to tax.gst_pct never reprices a quote that already exists (the
                // same freeze discipline as every other per-line snapshot in
                // this method).
                'gst_amount' => $totals['gst'],
                'gst_rate' => $totals['gst_rate'],
                'total' => $totals['total'],
                'notes' => $notes,
                'needed_by' => $neededBy,
                'created_by' => Auth::id(),
            ]);

            // Seed the status trail at creation, so the history reads from Draft
            // (the moment the order was placed) rather than jumping in at its
            // first later transition. Same shape transitionTo() logs, with a null
            // "from" for the initial state; the actor is the creator.
            $this->audit->log(
                $quote,
                'quote.state_changed',
                ['state' => null],
                ['state' => QuoteState::Draft->value],
            );

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

            // Lines where the buyer asked staff to do the design (they uploaded
            // references + notes rather than laying it out). Collected here so a
            // single staff alert fires per order once the transaction commits.
            $designRequestLines = [];

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

                if (($r['customization']['mode'] ?? null) === 'buyer_uploaded') {
                    $designRequestLines[] = [
                        'product_name' => $r['product']->name,
                        'qty' => (int) $r['qty'],
                    ];
                }
            }

            // Tell staff a human needs to produce artwork for these lines. After
            // commit so the quote + lines are settled before the alert lands.
            if ($designRequestLines !== []) {
                DB::afterCommit(fn () => $this->staffNotifier->designRequested($quote, $designRequestLines));
            }

            return $quote->fresh(['lineItems']);
        });
    }

    /**
     * Staff amends line prices/quantities on a DRAFT quote. Margin floor is
     * enforced in the Form Request; here we re-total, log the amendment, and
     * record who/what/when.
     *
     * @param  array<int, array{id?: int|null, product_id?: int, variant_id?: int|null, unit_price: float, qty: int}>  $lineAmendments
     * @param  array<int, int>  $removedLineIds
     * @param  array<int, array{label?: string, amount?: float}>|null  $adjustments  Null leaves the
     *                                                                               existing set untouched; an array (including empty) REPLACES it. Signed amounts:
     *                                                                               negative discounts, positive charges. Folded into the total after delivery.
     * @param  string|null  $remark  Staff's reason for this edit, stamped onto every entry of the
     *                               save's batch. Required by the endpoint (AmendQuoteRequest); optional here so
     *                               internal callers and older tests can amend without one.
     */
    public function amend(
        Quote $quote,
        array $lineAmendments,
        ?float $delivery,
        ?string $notes,
        array $removedLineIds = [],
        ?array $adjustments = null,
        ?string $remark = null,
    ): Quote {
        // DRAFT-only for staff, with a deliberate superadmin override: a
        // superadmin can correct an order's lines at any stage (a wrong price
        // caught after invoicing, a late line swap). The mandatory remark and
        // the edit trail capture who did it and why, and an already-issued
        // invoice is re-anchored to the new total below.
        if ($quote->state !== QuoteState::Draft && ! (Auth::user()?->isSuperadmin() ?? false)) {
            throw new DomainRuleException('Only DRAFT quotes can be amended.');
        }

        return DB::transaction(function () use ($quote, $lineAmendments, $delivery, $notes, $removedLineIds, $adjustments, $remark): Quote {
            // Lock the quote row before any read of subtotal/delivery/total that
            // this transaction will delta against. Without this, two concurrent
            // amends (or an amend racing retotalAfterReconfirm()'s own
            // lockForUpdate()) can both read the same pre-transaction subtotal
            // and both add their delta on top of it, silently losing one of the
            // two updates. Re-fetched (not just $quote->refresh()) to match the
            // lockForUpdate() idiom retotalAfterReconfirm() already uses, and
            // every read/write below operates on this same locked instance.
            $quote = Quote::query()->lockForUpdate()->findOrFail($quote->id);

            $before = [
                'subtotal' => $quote->subtotal,
                'delivery' => $quote->delivery,
                'gst_amount' => $quote->gst_amount,
                'total' => $quote->total,
            ];
            $log = $quote->amendment_log ?? [];
            // Subtotal is adjusted by DELTA, never rebuilt from a bare
            // unit_price*qty resum (Wave 2 money bug): the quote's frozen
            // subtotal already carries the quote-level setup fee and each
            // customized line's flat/per-unit decoration fees (baked in at
            // create() time via PricingService::quoteTotals, but never stored
            // per-line), so a full resum silently stripped them. Mirrors the
            // same delta discipline retotalAfterReconfirm() already uses:
            // touch only what this save actually changed, leave everything
            // else - including a no-op save - byte-for-byte alone.
            $subtotalDelta = 0.0;

            // One Save can touch several lines plus delivery and notes. Stamp
            // every entry it produces with a shared batch id, one timestamp and
            // one actor, so the trail can be grouped as "3 changes by Ada at
            // 14:02" rather than a scatter of loose rows. The actor NAME is
            // snapshotted here, not just the id, so the history still reads
            // correctly after a staff account is deleted.
            $now = now()->toIso8601String();
            $batch = (string) Str::uuid();
            $actorId = Auth::id();
            $actorName = Auth::user()?->name;
            $entry = fn (array $extra): array => array_merge([
                'batch' => $batch,
                'by' => $actorId,
                'by_name' => $actorName,
                'at' => $now,
                // The staff reason for this save, carried on every entry so the
                // trail can show it once per batch regardless of which entries
                // the save produced.
                'remark' => $remark,
            ], $extra);

            // Capture the editable scalars BEFORE the loops below mutate them, so
            // a delivery/notes change can be logged against its real prior value.
            $oldDelivery = (float) $quote->delivery;
            $oldNotes = $quote->notes;

            // Amendments are merged over the quote's full line set, never used to
            // rebuild it. Validation requires only min:1 lines, so summing the
            // payload alone let a partial submission drop the untouched lines out
            // of the money while leaving them on the order to be produced and
            // shipped. Reject unknown ids first so a typo'd id is an error rather
            // than a silent no-op.
            $amendmentsByLineId = [];
            $additions = [];
            foreach ($lineAmendments as $amendment) {
                if (($amendment['id'] ?? null) === null) {
                    $additions[] = $amendment;

                    continue;
                }

                $amendmentsByLineId[(int) $amendment['id']] = $amendment;
            }

            // Read the lines fresh rather than through a possibly-stale loaded
            // relation - the subtotal is rebuilt from these rows. Product name
            // rides along so an edited/removed line reads as a name in the log,
            // not "Product #12" once the id is all that survives.
            // 'class' rides along too - lineSubtotalContribution() needs it to
            // decide whether a customized line's UV decor fee applies
            // (MODEL_3D only), without a second query per line.
            $lines = $quote->lineItems()->with('product:id,name,class')->get();

            $unknown = array_diff(array_keys($amendmentsByLineId), $lines->pluck('id')->all());
            if ($unknown !== []) {
                throw (new ModelNotFoundException)->setModel(LineItem::class, array_values($unknown));
            }

            $removedIds = array_map('intval', $removedLineIds);
            $foreign = array_diff($removedIds, $lines->pluck('id')->all());
            if ($foreign !== []) {
                throw (new ModelNotFoundException)->setModel(LineItem::class, array_values($foreign));
            }

            foreach ($lines as $line) {
                // Removal wins over an amendment for the same line, and a removed
                // line contributes nothing to the subtotal. Soft-deleted so the
                // order's history survives - see LineItem's SoftDeletes.
                if (in_array($line->id, $removedIds, true)) {
                    $log[] = $entry([
                        'action' => 'removed',
                        'line_item_id' => $line->id,
                        'product_name' => $line->product?->name,
                        'from' => ['unit_price' => $line->unit_price, 'qty' => $line->qty],
                        'to' => null,
                    ]);

                    // Removing a line pulls its whole (fee-inclusive) contribution
                    // out of the subtotal - computed BEFORE the delete, since a
                    // DROPPED/CANCELLED line already contributes zero (defect b)
                    // and must stay at zero, not go negative.
                    $subtotalDelta -= $this->lineSubtotalContribution($line);

                    $line->delete();

                    continue;
                }

                $amendment = $amendmentsByLineId[$line->id] ?? null;

                if ($amendment !== null) {
                    // Named distinctly from the outer $before (the audit-log
                    // snapshot array) - reusing that name here would silently
                    // overwrite it with a float and corrupt the audit entry.
                    $beforeContribution = $this->lineSubtotalContribution($line);

                    $log[] = $entry([
                        'action' => 'edited',
                        'line_item_id' => $line->id,
                        'product_name' => $line->product?->name,
                        'from' => ['unit_price' => $line->unit_price, 'qty' => $line->qty],
                        'to' => ['unit_price' => $amendment['unit_price'], 'qty' => $amendment['qty']],
                    ]);

                    $line->unit_price = $amendment['unit_price'];
                    $line->qty = $amendment['qty'];
                    $line->save();

                    // Delta only: the line's own (possibly fee-bearing) change,
                    // not a resum of the whole quote. An untouched line below
                    // (no amendment, no removal) contributes no delta at all -
                    // this is what keeps a delivery/notes-only save from
                    // moving the subtotal by so much as a cent (defect c).
                    $subtotalDelta += $this->lineSubtotalContribution($line) - $beforeContribution;
                }
            }

            foreach ($additions as $addition) {
                $line = $this->addAmendedLine($quote, $addition);

                $log[] = $entry([
                    'action' => 'added',
                    'line_item_id' => $line->id,
                    // Snapshotted onto the line at creation - no extra query.
                    'product_name' => $line->frozen_snapshot['product_name'] ?? null,
                    'from' => null,
                    'to' => ['unit_price' => $line->unit_price, 'qty' => $line->qty],
                ]);

                $subtotalDelta += $this->lineSubtotalContribution($line);
            }

            $newDelivery = $delivery ?? (float) $quote->delivery;
            // Delivery is its own entry: it moves the order total without
            // touching any line, so it would otherwise leave no trace.
            if (round($newDelivery, 2) !== round($oldDelivery, 2)) {
                $log[] = $entry([
                    'action' => 'delivery',
                    'from' => ['delivery' => round($oldDelivery, 2)],
                    'to' => ['delivery' => round($newDelivery, 2)],
                ]);
            }

            // Notes likewise - a staff note change is an edit worth attributing.
            if ($notes !== null && $notes !== $oldNotes) {
                $log[] = $entry([
                    'action' => 'notes',
                    'from' => ['notes' => $oldNotes],
                    'to' => ['notes' => $notes],
                ]);
            }

            // Free-form adjustments (discount/tax/surcharge). Null = the editor
            // did not touch them, so leave the set as-is; an array replaces it
            // wholesale. Normalised to {label, amount} with a signed 2dp amount.
            $oldAdjustments = $quote->adjustments ?? [];
            $newAdjustments = $adjustments === null
                ? $oldAdjustments
                : array_values(array_map(
                    fn (array $a): array => [
                        'label' => trim((string) ($a['label'] ?? '')),
                        'amount' => round((float) ($a['amount'] ?? 0), 2),
                    ],
                    $adjustments,
                ));

            if ($adjustments !== null && $newAdjustments !== $oldAdjustments) {
                $log[] = $entry([
                    'action' => 'adjustments',
                    'from' => ['total' => $this->sumAdjustments($oldAdjustments)],
                    'to' => ['total' => $this->sumAdjustments($newAdjustments)],
                ]);
            }

            $quote->adjustments = $newAdjustments;

            $quote->subtotal = round((float) $quote->subtotal + $subtotalDelta, 2);
            $quote->delivery = $newDelivery;

            // GST is recomputed on the post-delta (subtotal + delivery) base, but
            // at the quote's own SNAPSHOT gst_rate - never the live tax.gst_pct
            // config - so a rate change after the quote was created never
            // reprices it on a later amend. Adjustments are deliberately left
            // out of the GST base (spec: GST does not apply to free-form staff
            // adjustments; a "tax" adjustment would double-count).
            $gstRate = (float) $quote->gst_rate / 100;
            $quote->gst_amount = $this->pricing->gstAmount(
                (float) $quote->subtotal + (float) $quote->delivery,
                $gstRate,
            );

            // Adjustments land after delivery and GST, and can pull the total
            // down (a discount) as well as up (a tax/fee) - see
            // Quote::adjustmentsTotal.
            $quote->total = round(
                (float) $quote->subtotal + (float) $quote->delivery + (float) $quote->gst_amount + $quote->adjustmentsTotal(),
                2,
            );
            $quote->amendment_log = $log;
            $quote->amended_by = $actorId;
            if ($notes !== null) {
                $quote->notes = $notes;
            }
            $quote->save();

            $this->audit->log($quote, 'quote.amended', $before, [
                'subtotal' => $quote->subtotal,
                'delivery' => $quote->delivery,
                'gst_amount' => $quote->gst_amount,
                'total' => $quote->total,
            ]);

            // A superadmin edit can land on an already-invoiced order. Keep the
            // authoritative invoice amount (and its GST component) in step with
            // the new total, exactly as a post-procurement reconfirmation does,
            // so the buyer is never invoiced for a superseded figure. No-op on a
            // DRAFT (no invoice yet).
            if ($quote->state !== QuoteState::Draft) {
                $this->reanchorInvoices($quote);
            }

            // A drop/amendment changes which proof-needing lines remain, so
            // re-roll the order's proof state - otherwise dropping the last
            // unresolved artwork line after its siblings are approved strands
            // the order in PROOFING (approved proofs are immutable, so no proof
            // event ever fires again). recomputeProofState() queries fresh and
            // no-ops when there are no proof-needing lines or the target is not
            // a legal transition.
            $quote->recomputeProofState();

            return $quote->fresh(['lineItems']);
        });
    }

    /**
     * Re-anchor every LIVE (non-VOID) invoice on the quote to its current total,
     * shared by amend()'s superadmin path and retotalAfterReconfirm(). A VOID
     * invoice is a closed, credit-noted document (see voidInvoiceAndCredit()) -
     * it is skipped entirely, never overwritten. Raising the amount on an
     * invoice already marked PAID means it is no longer fully paid, so that
     * invoice is downgraded to PARTIAL rather than silently left reading PAID
     * against a now-larger figure. Iterates every invoice for the same reason
     * voidInvoiceAndCredit() does (see its docblock) rather than assuming
     * exactly one via latest()->first().
     */
    private function reanchorInvoices(Quote $quote): void
    {
        $invoices = $quote->purchaseOrders()->get();

        foreach ($invoices as $invoice) {
            if ($invoice->payment_state === PaymentState::Void) {
                continue;
            }

            $invoiceBefore = [
                'amount' => $invoice->amount,
                'gst_amount' => $invoice->gst_amount,
                'payment_state' => $invoice->payment_state->value,
            ];

            $raised = round((float) $quote->total, 2) > round((float) $invoice->amount, 2);

            $invoice->amount = $quote->total;
            $invoice->gst_amount = $quote->gst_amount;
            if ($raised && $invoice->payment_state === PaymentState::Paid) {
                $invoice->payment_state = PaymentState::Partial;
            }
            $invoice->save();

            $this->audit->log($invoice, 'invoice.retotaled', $invoiceBefore, [
                'amount' => $invoice->amount,
                'gst_amount' => $invoice->gst_amount,
                'payment_state' => $invoice->payment_state->value,
            ]);
        }
    }

    /**
     * Create a line added through the amend screen. Mirrors the shape create()
     * writes, including the frozen snapshot, so an added line is indistinguishable
     * downstream from one that arrived with the original order. The unit price is
     * the staff figure (already margin-floor checked in AmendQuoteRequest), not a
     * recomputed catalogue price - staff amend precisely because the catalogue
     * price is not what the supplier is charging today.
     *
     * @param  array{product_id: int, variant_id?: int|null, unit_price: float, qty: int}  $addition
     */
    /**
     * Signed sum of an adjustment set, matching Quote::adjustmentsTotal but
     * usable on a raw array (the pre-save snapshot) for logging the delta.
     *
     * @param  array<int, array{label?: string, amount?: mixed}>  $adjustments
     */
    private function sumAdjustments(array $adjustments): float
    {
        $sum = 0.0;
        foreach ($adjustments as $adjustment) {
            $amount = $adjustment['amount'] ?? null;
            if (is_numeric($amount)) {
                $sum += (float) $amount;
            }
        }

        return round($sum, 2);
    }

    /**
     * A line's current contribution to the quote subtotal: raw unit_price × qty
     * plus the SAME per-line decoration fee overlay the canonical pricer
     * applies at create time (PricingService::lineCustomizationFee - flat +
     * per-unit size/text/UV), read fresh from the line's own (unchanged by
     * amend) customization field. This is what amend() deltas against, so a
     * staff-edited unit_price is preserved verbatim - only the fee overlay is
     * re-derived, never the price itself.
     *
     * A DROPPED/CANCELLED line contributes nothing (defect b): it will not be
     * fulfilled, so it must not be billed, however its unit_price/qty read.
     */
    private function lineSubtotalContribution(LineItem $line): float
    {
        if (in_array($line->line_state, [LineItemState::Dropped, LineItemState::Cancelled], true)) {
            return 0.0;
        }

        $customization = $line->customization ?? [];

        $fee = $this->pricing->lineCustomizationFee(
            $line->product,
            $line->qty,
            $this->hasCustomization($customization),
            $customization['logo_size'] ?? null,
            ! empty($customization['text']),
        );

        return round((float) $line->lineTotal() + $fee, 2);
    }

    private function addAmendedLine(Quote $quote, array $addition): LineItem
    {
        $product = Product::findOrFail($addition['product_id']);
        $variant = ($addition['variant_id'] ?? null) !== null
            ? Variant::findOrFail($addition['variant_id'])
            : null;

        return LineItem::create([
            'quote_id' => $quote->id,
            'product_id' => $product->id,
            'variant_id' => $variant?->id,
            'qty' => $addition['qty'],
            'unit_price' => $addition['unit_price'],
            'currency' => $quote->currency,
            'customization' => null,
            'line_state' => LineItemState::Pending->value,
            'frozen_snapshot' => [
                'product_name' => $product->name,
                'base_cost' => $product->base_cost,
                'price_delta' => $variant?->price_delta,
                'unit_price' => $addition['unit_price'],
                'frozen_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Set the buyer approval ordering for a quote (Feature A). Editable by staff
     * only while the order is still DRAFT; once sent it is locked, EXCEPT for a
     * superadmin, who may still flip it (mirrors amend()'s superadmin override).
     */
    public function setApprovalOrder(Quote $quote, ApprovalOrder $order): Quote
    {
        if ($quote->state !== QuoteState::Draft && ! (Auth::user()?->isSuperadmin() ?? false)) {
            throw new DomainRuleException('Approval order is locked once the order is sent.');
        }

        if ($quote->approval_order === $order) {
            return $quote;
        }

        // Wrap the state flip and its audit insert together, matching the
        // atomicity discipline amend() uses for every state+audit pair - a
        // failed audit insert must not leave the change persisted untraced.
        return DB::transaction(function () use ($quote, $order): Quote {
            $before = $quote->approval_order->value;
            $quote->approval_order = $order;
            $quote->save();

            $this->audit->log($quote, 'quote.approval_order_changed', ['approval_order' => $before], ['approval_order' => $order->value]);

            return $quote;
        });
    }

    /**
     * Send the quote to the buyer (DRAFT -> SENT), freezing the price snapshot
     * timestamp. Proofs are staged and sent per line through stageProof ->
     * sendProofs, so this is a plain quote send with no artwork attached.
     */
    public function send(Quote $quote): Quote
    {
        if ($quote->state !== QuoteState::Draft) {
            throw new DomainRuleException('Only DRAFT quotes can be sent.');
        }

        if ($this->requiresProofFirst($quote)) {
            throw new DomainRuleException('This order is set to proof-first; send the artwork proof to the buyer before asking for the price.');
        }

        return DB::transaction(function () use ($quote): Quote {
            $previous = $quote->state->value;
            $quote->price_snapshot_at = now();
            $quote->save();

            $quote->transitionTo(QuoteState::Sent);
            $this->emailQuoteReady($quote, false);

            DB::afterCommit(fn () => Broadcasting::dispatch(fn () => QuoteStateChanged::dispatch($quote, $previous)));

            return $quote;
        });
    }

    /**
     * Buyer agrees the price. Where that lands depends on which approval came
     * first: from SENT the artwork is still to come (-> ACCEPTED), while from
     * ARTWORK_APPROVED this is the second of the two approvals and the order is
     * ready to invoice (-> PROOF_APPROVED).
     */
    public function accept(Quote $quote): Quote
    {
        // L2: explicit state guard. Accept only applies to a quote awaiting the
        // buyer's price agreement (SENT, or ARTWORK_APPROVED on the slim path).
        // Without this, calling accept on any other state stamped accepted_at
        // and then bounced off the state machine with a raw transition 422
        // (rolled back, but an opaque message) - this gives a clean, honest one.
        if (! in_array($quote->state, [QuoteState::Sent, QuoteState::ArtworkApproved], true)) {
            throw new DomainRuleException('This order is not awaiting price acceptance.');
        }

        if ($quote->state === QuoteState::Sent && $this->requiresProofFirst($quote)) {
            throw new DomainRuleException('This order is set to proof-first; approve the artwork proof before agreeing the price.');
        }

        return DB::transaction(function () use ($quote): Quote {
            $previous = $quote->state->value;
            $quote->accepted_at = now();
            $quote->accepted_by = Auth::id();
            $quote->save();
            $quote->transitionTo(
                $quote->state === QuoteState::ArtworkApproved
                    ? QuoteState::ProofApproved
                    : QuoteState::Accepted,
            );

            // A plain-stock order (nothing to proof) has no proofing step: the
            // price is its only approval, so it is ready to invoice. Advance
            // ACCEPTED -> PROOF_APPROVED directly rather than stranding it in
            // ACCEPTED, whose only other forward exit needs a staged proof.
            if ($quote->state === QuoteState::Accepted && ! $this->hasProofNeedingLines($quote)) {
                $quote->transitionTo(QuoteState::ProofApproved);
            }

            DB::afterCommit(fn () => Broadcasting::dispatch(fn () => QuoteStateChanged::dispatch($quote, $previous)));

            return $quote;
        });
    }

    /**
     * Whether the order has any line that still needs a proof (customized and
     * not dropped). Used to tell a plain-stock order - which has no proofing
     * step - apart from one that must go through proof sign-off.
     */
    private function hasProofNeedingLines(Quote $quote): bool
    {
        return $quote->lineItems()->get()->contains(
            fn (LineItem $line): bool => $line->needsProof()
        );
    }

    /** proof_first ordering that actually has artwork to approve first. */
    private function requiresProofFirst(Quote $quote): bool
    {
        return $quote->approval_order === ApprovalOrder::ProofFirst
            && $this->hasProofNeedingLines($quote);
    }

    /** price_first ordering that actually has artwork (so proofs must wait for the price). */
    private function requiresPriceFirst(Quote $quote): bool
    {
        return $quote->approval_order === ApprovalOrder::PriceFirst
            && $this->hasProofNeedingLines($quote);
    }

    /**
     * Re-send the buyer's proof-review email for an open (SENT) proof, without
     * issuing a new version. For staff chasing a buyer who lost or never saw the
     * first mail. Reuses the same rich review email (proof thumbnail + sign-off
     * link) so the buyer gets exactly what they got the first time.
     */
    public function resendProof(Proof $proof): void
    {
        if ($proof->state !== ProofState::Sent) {
            throw new DomainRuleException('Only a proof still awaiting the buyer can be resent.');
        }

        // Recorded in the audit trail (actor + IP captured by AuditLogger), with
        // the order it belongs to so a resend is traceable to its quote.
        $this->audit->log($proof, 'proof.resent', null, [
            'version' => $proof->version,
            'quote_id' => $proof->quote_id,
            'quote_reference' => $proof->quote?->reference,
        ]);

        // Email THIS proof's artwork specifically - not the quote-wide latest
        // version, which on a multi-line order can be a different line (M13).
        $this->emailQuoteReady($proof->quote, true, $proof);
    }

    /**
     * Stage artwork for one line as a DRAFT proof (buyer not yet emailed). If the
     * line already holds an unsent DRAFT, its artwork is replaced rather than
     * bumping the version - re-picking a file before sending is not a revision.
     */
    public function stageProof(Quote $quote, LineItem $line, string $artworkRef): Proof
    {
        if (! $line->needsProof()) {
            throw new DomainRuleException('This line does not take a proof.');
        }

        return DB::transaction(function () use ($quote, $line, $artworkRef): Proof {
            $openDraft = $line->proofs()
                ->where('state', ProofState::Draft->value)
                ->orderByDesc('version')
                ->first();

            if ($openDraft !== null) {
                $openDraft->artwork_version_ref = $artworkRef;
                $openDraft->save();
                $proof = $openDraft;
            } else {
                $nextVersion = ((int) $line->proofs()->max('version')) + 1;
                $proof = Proof::create([
                    'quote_id' => $quote->id,
                    'line_item_id' => $line->id,
                    'version' => $nextVersion,
                    'artwork_version_ref' => $artworkRef,
                    'state' => ProofState::Draft->value,
                ]);
            }

            DB::afterCommit(fn () => Broadcasting::dispatch(fn () => ProofStatusChanged::dispatch($proof, $quote->company_id)));

            return $proof;
        });
    }

    /**
     * Send the current round: flip every staged DRAFT proof to SENT, move the
     * order into PROOFING, and email the buyer ONCE with the round's items.
     */
    public function sendProofs(Quote $quote): Quote
    {
        if ($this->requiresPriceFirst($quote) && $quote->accepted_at === null) {
            throw new DomainRuleException('This order is set to price-first; the buyer must agree the price before proofs are sent.');
        }

        $drafts = $quote->proofs()->where('state', ProofState::Draft->value)->get();

        if ($drafts->isEmpty()) {
            throw new DomainRuleException('Nothing is staged to send.');
        }

        return DB::transaction(function () use ($quote, $drafts): Quote {
            foreach ($drafts as $draft) {
                $draft->transitionTo(ProofState::Sent);
                DB::afterCommit(fn () => Broadcasting::dispatch(fn () => ProofStatusChanged::dispatch($draft, $quote->company_id)));
            }

            if (in_array($quote->state, [QuoteState::Accepted, QuoteState::ChangesRequested, QuoteState::Draft], true)) {
                $previous = $quote->state->value;
                if ($quote->state === QuoteState::Draft) {
                    $quote->price_snapshot_at = now();
                    $quote->save();
                }
                $quote->transitionTo(QuoteState::Proofing);
                DB::afterCommit(fn () => Broadcasting::dispatch(fn () => QuoteStateChanged::dispatch($quote, $previous)));
            }

            DB::afterCommit(fn () => $this->emailProofsReady($quote));

            return $quote;
        });
    }

    /**
     * Batched "proofs ready" email: the buyer is written to ONCE per round with
     * a thumbnail per item, rather than one email per line. Fired after commit
     * by sendProofs, so it queues directly (no further afterCommit needed).
     * No-ops silently when no buyer recipient can be resolved, or when the
     * buyer has switched the "Revised proof issued" notification off.
     */
    private function emailProofsReady(Quote $quote): void
    {
        if (! $this->notifier->isEnabled(OrderMilestone::ProofIssued)) {
            // L18: respecting the mute is intentional, but a silently email-less
            // proof round leaves the buyer to discover it in-portal. Log it so
            // "no proof email went out" is visible to staff/ops rather than
            // invisible (mirrors the not-fail-silently stance of M11/M17).
            Log::info('Proof-ready email suppressed: the "Revised proof issued" milestone is disabled.', [
                'quote_id' => $quote->id,
            ]);

            return;
        }

        $recipient = $this->resolveBuyerRecipient($quote);
        if ($recipient?->email === null) {
            return;
        }

        $items = $quote->proofs()
            ->where('state', ProofState::Sent->value)
            ->with('lineItem.product')
            ->get();

        Mail::to($recipient->email)->queue(
            new QuoteReadyMail($quote, $items, greetingName: $recipient->name)
        );
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

            // Approving artwork is NOT agreeing a price. On the artwork-first
            // route this used to back-fill acceptance silently, so a buyer could
            // be committed to a figure they were never shown - and there would
            // be no record of them having seen it. They now go on to accept the
            // price as a separate act; accept() completes the pair.
            //
            // Per-line: mutate only this line's proof, then let the rollup decide
            // the order state from ALL artwork lines. recomputeProofState()
            // broadcasts QuoteStateChanged itself, so we only fire the
            // proof-level ProofStatusChanged here.
            $quote = $proof->quote;
            $quote->recomputeProofState();

            DB::afterCommit(fn () => Broadcasting::dispatch(fn () => ProofStatusChanged::dispatch($proof, $quote->company_id)));

            return $proof;
        });
    }

    /**
     * Buyer requests changes: proof -> CHANGES_REQUESTED. On the existing/accepted
     * path (accepted_at set) the quote stays PROOFING so staff can issue a new proof
     * version. On the slim path (accepted_at null) the rejection may concern price or
     * artwork, so the quote advances to CHANGES_REQUESTED for staff triage.
     */
    /**
     * @param  array<int, string>  $attachments  Optional buyer reference-image
     *                                           storage keys (artwork/…).
     */
    public function requestProofChanges(Proof $proof, ?string $notes, array $attachments = []): Proof
    {
        return DB::transaction(function () use ($proof, $notes, $attachments): Proof {
            if ($notes !== null) {
                $proof->notes = $notes;
            }
            if ($attachments !== []) {
                $proof->change_refs = array_values($attachments);
            }
            $proof->transitionTo(ProofState::ChangesRequested);

            // Per-line: mutate only this line's proof, then let the rollup decide
            // the order state from ALL artwork lines. recomputeProofState()
            // broadcasts QuoteStateChanged itself when the order state moves.
            $quote = $proof->quote;
            $quote->recomputeProofState();

            DB::afterCommit(fn () => Broadcasting::dispatch(fn () => ProofStatusChanged::dispatch($proof, $quote->company_id)));

            // Put the ball back in staff's court: email every operator and push
            // a live nudge to the console. After commit, so a rolled-back
            // request never notifies anyone about a change that didn't land.
            DB::afterCommit(fn () => $this->staffNotifier->proofChangesRequested($proof));

            return $proof;
        });
    }

    /**
     * Approve every proof on the order still awaiting the buyer (SENT), in one
     * transaction. Leaves CHANGES_REQUESTED lines alone. Attributed to the actor
     * (buyer, or superadmin on-behalf). One roll-up at the end.
     */
    public function approveAllOpenProofs(Quote $quote, User $actor): void
    {
        DB::transaction(function () use ($quote, $actor): void {
            $open = $quote->proofs()->where('state', ProofState::Sent->value)->get();
            foreach ($open as $proof) {
                $proof->approved_by = $actor->id;
                $proof->approved_at = now();
                $proof->transitionTo(ProofState::Approved);
                $this->audit->log($proof, 'proof.approved', null, [
                    'version' => $proof->version, 'line_item_id' => $proof->line_item_id,
                    'approved_by' => $actor->id, 'batch' => true,
                ]);
                DB::afterCommit(fn () => Broadcasting::dispatch(fn () => ProofStatusChanged::dispatch($proof, $quote->company_id)));
            }
            $quote->recomputeProofState();
        });
    }

    /**
     * Staff issues the invoice: quote PROOF_APPROVED -> INVOICED -> CONFIRMED.
     */
    public function issueInvoice(Quote $quote, string $poRef, ?string $invoiceRef, ?string $terms): Invoice
    {
        // Both routes are meant to guarantee this by the time PROOF_APPROVED is
        // reached, so it should be unreachable - which is exactly why it is
        // worth asserting. Invoicing an order the buyer never priced is the
        // failure this whole two-approval split exists to prevent.
        if ($quote->accepted_at === null) {
            throw new DomainRuleException('Quote cannot be invoiced before the buyer has agreed the price.');
        }

        return DB::transaction(function () use ($quote, $poRef, $invoiceRef, $terms): Invoice {
            // Lock the quote row for the duration of the check-then-create so two
            // concurrent PROOF_APPROVED requests (double-submit / retry) can't
            // both observe "no invoice yet" and both create one - the second
            // delivery blocks here until the first commits, then sees the
            // freshly-created invoice below and returns it instead of minting a
            // duplicate (mirrors PaymentService::confirmPaid's TOCTOU guard).
            $locked = Quote::query()
                ->whereKey($quote->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $existing = $locked->purchaseOrders()->first();
            if ($existing !== null) {
                return $existing;
            }

            $invoice = Invoice::create([
                'quote_id' => $locked->id,
                'po_ref' => $poRef,
                'invoice_ref' => $invoiceRef,
                'terms' => $terms ?? $locked->company->default_terms,
                'payment_state' => PaymentState::Unpaid->value,
                // The invoice amount is the quote's GST-inclusive total; gst_amount
                // and gst_rate are copied across (not re-derived) so the invoice
                // carries the same frozen snapshot the quote already holds.
                'amount' => $locked->total,
                'gst_amount' => $locked->gst_amount,
                'gst_rate' => $locked->gst_rate,
                'currency' => $locked->currency,
                'issued_by' => Auth::id(),
                'issued_at' => now(),
            ]);

            $previous = $locked->state->value;
            $locked->transitionTo(QuoteState::Invoiced);
            $locked->transitionTo(QuoteState::Confirmed);
            DB::afterCommit(fn () => Broadcasting::dispatch(fn () => QuoteStateChanged::dispatch($locked, $previous)));

            $this->audit->log($invoice, 'invoice.issued', null, ['po_ref' => $poRef, 'amount' => $locked->total]);

            return $invoice;
        });
    }

    /**
     * Staff records the real-world outcome of a B2B invoice: there is no
     * Stripe path for B2B, so payment is reconciled manually against a bank
     * transfer / cheque / cash receipt staff have evidence of elsewhere.
     * Mirrors PaymentService::confirmPaid's lock + audit shape. A VOID invoice
     * is terminal - reconciling it to anything else is refused explicitly
     * rather than silently allowed. Requesting the state the invoice is
     * already in is a no-op (idempotent-friendly for retried requests).
     */
    public function reconcilePayment(Quote $quote, PaymentState $target, ?string $note = null, ?float $amountPaid = null): Invoice
    {
        return DB::transaction(function () use ($quote, $target, $note, $amountPaid): Invoice {
            $locked = Quote::query()
                ->whereKey($quote->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $invoice = $locked->purchaseOrders()->lockForUpdate()->first();
            if ($invoice === null) {
                throw new DomainRuleException('This order has no invoice to reconcile yet.');
            }

            $current = $invoice->payment_state;

            if ($current === PaymentState::Void) {
                throw new DomainRuleException('This invoice is VOID and cannot be reconciled to another state.');
            }

            // H3/M21: record HOW MUCH was collected, so a later cancel credits
            // only the received amount (never the full invoice) and staff can
            // see the balance owed.
            //   - PARTIAL: the entered amount, which must be > 0 and strictly
            //     less than the invoice total (>= total is fully PAID, refused
            //     so "partial" always means an outstanding balance).
            //   - PAID: the full invoice amount, stamped automatically.
            //   - VOID: leaves amount_paid untouched (whatever was collected).
            if ($target === PaymentState::Partial) {
                if ($amountPaid === null || $amountPaid <= 0.0) {
                    throw new DomainRuleException('A partial payment must record the amount collected.');
                }
                if ($amountPaid >= (float) $invoice->amount) {
                    throw new DomainRuleException('A partial amount must be less than the invoice total - use PAID for the full amount.');
                }
                $invoice->amount_paid = $amountPaid;
            } elseif ($target === PaymentState::Paid) {
                $invoice->amount_paid = $invoice->amount;
            }

            if ($current === $target) {
                // Same state requested twice (retry / double-click). For PARTIAL
                // this still lets staff correct the recorded amount above, so
                // persist rather than early-returning.
                $invoice->save();

                return $invoice;
            }

            $invoice->payment_state = $target;
            $invoice->save();

            $this->audit->log($invoice, 'payment.reconciled', [
                'payment_state' => $current->value,
            ], [
                'payment_state' => $target->value,
                'amount_paid' => $invoice->amount_paid,
                'note' => $note,
            ]);

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

            // MODEL_3D lines draw filament (no ledger), so return exactly the
            // grams each line recorded consuming - otherwise cancelling a 3D
            // order silently loses that filament from inventory.
            $this->returnConsumedFilament($quote);

            // Close the money loop: a PAID/PARTIAL B2B invoice has no gateway to
            // refund through, so cancelling one voids it and mints a credit note
            // for what was collected. Must run inside this same transaction -
            // transitionTo() above already threw for a 2nd cancel (Cancelled has
            // no outgoing edges), so getting here at all means this is the one
            // and only time this quote is closed.
            $this->voidInvoiceAndCredit($quote, $reason);

            $this->audit->log($quote, 'quote.cancelled', ['state' => $previous], ['reason' => $reason]);

            DB::afterCommit(fn () => Broadcasting::dispatch(fn () => QuoteStateChanged::dispatch($quote, $previous)));

            return $quote->fresh(['lineItems']);
        });
    }

    /**
     * Close the money loop on a cancelled quote. There is no payment gateway to
     * refund through in B2B - a PAID/PARTIAL invoice is closed by minting a
     * credit note for the exact (GST-inclusive) amount already invoiced, then
     * voiding the invoice so it stops reading as collectible. An UNPAID invoice
     * is simply voided (nothing was collected, so nothing to credit). A quote
     * that was never invoiced is a clean no-op.
     *
     * Iterates every LIVE invoice on the quote rather than trusting
     * purchaseOrders()->latest()->first(): issueInvoice() already guarantees at
     * most one invoice per quote in the steady state (locked check-then-create),
     * so this is a defensive superset rather than a behaviour change - and it
     * sidesteps needing a soft-delete-aware unique index on invoices.quote_id,
     * which would need driver-specific handling (native partial index on
     * MySQL, a filtered index expression on SQLite) to get right across both
     * the app's MySQL target and the SQLite test suite.
     */
    private function voidInvoiceAndCredit(Quote $quote, ?string $reason): void
    {
        $invoices = $quote->purchaseOrders()->lockForUpdate()->get();

        foreach ($invoices as $invoice) {
            // H3: credit ONLY what was actually collected, never the full
            // invoice. collectedAmount() is amount_paid for a PARTIAL/PAID
            // invoice (recorded at reconcile time), falling back to the full
            // amount only for a legacy PAID invoice with no recorded figure. A
            // PARTIAL that collected 400 of 1000 credits 400, not 1000.
            $collected = $invoice->collectedAmount();

            if ($collected > 0.0) {
                $creditNote = CreditNote::create([
                    'quote_id' => $quote->id,
                    'invoice_id' => $invoice->id,
                    'amount' => $collected,
                    'reason' => $reason,
                    'issued_by' => Auth::id(),
                    'issued_at' => now(),
                ]);

                $this->audit->log($creditNote, 'credit_note.issued', null, [
                    'invoice_id' => $invoice->id,
                    'amount' => $creditNote->amount,
                ]);
            }

            if ($invoice->payment_state !== PaymentState::Void) {
                $before = $invoice->payment_state->value;
                $invoice->payment_state = PaymentState::Void;
                $invoice->save();

                $this->audit->log($invoice, 'invoice.voided', ['payment_state' => $before], [
                    'payment_state' => PaymentState::Void->value,
                ]);
            }
        }
    }

    /**
     * M15: cancel & credit ONE returned SHIPMENT of a multi-shipment order
     * without disturbing its delivered siblings. A shipment can group several
     * jobs (parcel-split), so this restocks EVERY member job's lines, reduces
     * the invoice by the shipment's proportional share, and credits only that
     * share of what was actually collected (proportional to the deposit, so a
     * part-paid order never refunds more than it received). The order stays
     * live; every member job of the shipment moves to the terminal RETURNED
     * state. A 1-job shipment behaves exactly as the old job-scoped version.
     *
     * The shipment's share is by line value (unit*qty + decoration fee) over the
     * whole order, so the GST + delivery folded into the invoice total are
     * allocated pro-rata rather than needing a separate per-shipment breakdown.
     *
     * The caller (QueueService::resolveReturnCancelCredit) decides this vs a
     * whole-order cancel by whether any sibling shipment is still live, and owns
     * the broadcast/audit around it - this method only moves money + stock.
     */
    public function returnParcel(Quote $quote, \App\Models\Shipment $shipment, ?string $reason): void
    {
        DB::transaction(function () use ($quote, $shipment, $reason): void {
            $shipment->loadMissing('jobs.lineItems.variant', 'jobs.lineItems.product');
            $quote->loadMissing('lineItems');

            // Every member job's lines - a shipment can group several jobs
            // (parcel-split), so the returned parcel is the WHOLE shipment, not
            // just the one job staff clicked.
            $parcelLines = $shipment->jobs->flatMap(fn ($j) => $j->lineItems);

            $allLinesValue = 0.0;
            foreach ($quote->lineItems as $line) {
                $allLinesValue += $this->lineSubtotalContribution($line);
            }

            $parcelLinesValue = 0.0;
            foreach ($parcelLines as $line) {
                $parcelLinesValue += $this->lineSubtotalContribution($line);
            }

            $fraction = $allLinesValue > 0.0 ? min(1.0, $parcelLinesValue / $allLinesValue) : 0.0;

            // Restock only this shipment's lines - sibling shipments' stock
            // stays consumed.
            $this->returnConsumedStockForLines($parcelLines);
            $this->returnConsumedFilamentForLines($parcelLines);

            foreach ($quote->purchaseOrders()->lockForUpdate()->get() as $invoice) {
                if ($invoice->payment_state === PaymentState::Void) {
                    continue;
                }

                $parcelInvoiceValue = round((float) $invoice->amount * $fraction, 2);
                $refund = round($invoice->collectedAmount() * $fraction, 2);

                // Credit only the parcel's proportional slice of money actually
                // collected - never the full parcel value on a part-paid order.
                // One credit note per invoice (the loop already does one each).
                if ($refund > 0.0) {
                    $creditNote = CreditNote::create([
                        'quote_id' => $quote->id,
                        'invoice_id' => $invoice->id,
                        'amount' => $refund,
                        'reason' => $reason,
                        'issued_by' => Auth::id(),
                        'issued_at' => now(),
                    ]);

                    $this->audit->log($creditNote, 'credit_note.issued', null, [
                        'invoice_id' => $invoice->id,
                        'amount' => $refund,
                        'scope' => 'parcel',
                        'shipment_id' => $shipment->id,
                    ]);
                }

                // The order now bills only the retained goods: drop the parcel's
                // share off the invoice total, and off amount collected (the
                // refund left the wallet, so it is no longer "paid").
                $invoice->amount = max(0.0, round((float) $invoice->amount - $parcelInvoiceValue, 2));
                if ($invoice->amount_paid !== null) {
                    $invoice->amount_paid = max(0.0, round((float) $invoice->amount_paid - $refund, 2));
                }
                $invoice->save();

                $this->audit->log($invoice, 'invoice.parcel_returned', null, [
                    'shipment_id' => $shipment->id,
                    'reduced_by' => $parcelInvoiceValue,
                    'new_amount' => $invoice->amount,
                ]);
            }

            // Move EVERY member job of the returned shipment to terminal
            // RETURNED. Idempotent: the caller (resolveReturnCancelCredit) may
            // have already flipped the SHIPPED members before calling here, and
            // RETURNED is terminal (no self-transition), so skip any already there.
            foreach ($shipment->jobs as $j) {
                if ($j->state !== JobState::Returned) {
                    $j->transitionTo(JobState::Returned);
                }
            }
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
        $this->returnConsumedStockForLines($quote->lineItems);
    }

    /**
     * Line-scoped stock return. The quote-level cancel returns every line; a
     * single returned parcel (M15) returns only that parcel's lines, leaving
     * the delivered siblings' stock consumed.
     *
     * @param  iterable<int, LineItem>  $lines
     */
    private function returnConsumedStockForLines(iterable $lines): void
    {
        foreach ($lines as $line) {
            $line->loadMissing('variant');
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
     * Return the filament each MODEL_3D line consumed. Filament is a bare
     * counter with no ledger, so the grams drawn are read from the line's
     * recorded consumed_grams (set at procurement time) and added back to the
     * matching filament, then cleared so a re-run can't double-return.
     */
    private function returnConsumedFilament(Quote $quote): void
    {
        $quote->loadMissing('lineItems.product');
        $this->returnConsumedFilamentForLines($quote->lineItems);
    }

    /**
     * Line-scoped filament return. Same split as returnConsumedStockForLines:
     * a returned parcel gives back only its own lines' filament (M15).
     *
     * @param  iterable<int, LineItem>  $lines
     */
    private function returnConsumedFilamentForLines(iterable $lines): void
    {
        foreach ($lines as $line) {
            $line->loadMissing('product');
            $grams = (float) ($line->consumed_grams ?? 0);
            if ($grams <= 0 || $line->product === null) {
                continue;
            }

            $filament = Filament::query()
                ->where('material', $line->product->filament_material)
                ->where('color', $line->product->filament_color)
                ->lockForUpdate()
                ->first();

            if ($filament === null) {
                continue;
            }

            $filament->qty_on_hand = (float) $filament->qty_on_hand + $grams;
            $filament->save();

            $line->consumed_grams = null;
            $line->save();
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

        // jobs.shipment: QuoteResource::shipmentSummary reads each job's
        // shipment (Stage 2a), so load it here to avoid an N+1 per job.
        return $quote->fresh(['lineItems', 'jobs.shipment']);
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

        // Nothing could be sourced at all - no variant, no filament, no weight
        // estimate. Accepting that would bill zero and build a job for zero
        // units; dropping the line is what staff actually mean here.
        if ($decision['action'] === 'approve' && (int) $line->procured_qty < 1) {
            throw new DomainRuleException(
                'Nothing could be sourced for this line, so there is nothing to accept. Drop the line instead.'
            );
        }

        $notifyLineChanged = false;

        DB::transaction(function () use ($line, $decision, &$notifyLineChanged): void {
            // Money delta this decision introduces against the quote's frozen
            // totals. Tracked as a delta (not a full re-price) so the setup /
            // customization fees baked into the original subtotal survive.
            $totalDelta = 0.0;

            switch ($decision['action']) {
                case 'amend':
                    // Fee-inclusive, mirroring amend()'s own delta discipline
                    // (lineSubtotalContribution(), not bare lineTotal()) - a
                    // customized line's flat/per-unit decoration fee is baked
                    // into the frozen subtotal and must be re-derived from the
                    // AMENDED qty, not silently dropped or left at the
                    // pre-reconfirm figure.
                    $before = $this->lineSubtotalContribution($line);
                    $line->qty = $decision['qty'];
                    $line->unit_price = $decision['unit_price'];
                    $line->save();
                    $line->transitionTo(LineItemState::Amended);
                    $this->procurement->procureLine($line);
                    $totalDelta = $this->lineSubtotalContribution($line) - $before;
                    $notifyLineChanged = true;
                    break;

                case 'approve':
                    // Accept what could actually be sourced. This branch used to
                    // complete the line without touching the money, so the buyer
                    // was invoiced for the quantity ordered while the floor only
                    // ever made the quantity available - the one decision of the
                    // three that did not re-total.
                    //
                    // A price jump leaves procured_qty at the ordered figure, so
                    // the delta is zero and the quoted price stands: accepting a
                    // price rise means absorbing it, not passing it on.
                    //
                    // Fee-inclusive (lineSubtotalContribution(), not bare
                    // lineTotal()), mirroring amend/drop: on a quantity shortfall
                    // the removed units must lose the per-unit decoration fee too,
                    // not just the unit price - otherwise the buyer is billed
                    // decoration for units the floor never made.
                    $before = $this->lineSubtotalContribution($line);
                    $line->qty = $line->procured_qty;
                    $line->save();

                    $line->transitionTo(LineItemState::Purchased);
                    $line->transitionTo(LineItemState::Inbound);
                    $line->transitionTo(LineItemState::Received);
                    $line->transitionTo(LineItemState::Ready);
                    $totalDelta = $this->lineSubtotalContribution($line) - $before;
                    break;

                case 'drop':
                    // Measured BEFORE the transition: lineSubtotalContribution()
                    // reads back 0.0 for a DROPPED line, so the fee-inclusive
                    // figure must be captured while the line is still counted.
                    // Bare lineTotal() (unit x qty) used to leave a customized
                    // line's flat + per-unit decoration fee stranded in the
                    // subtotal on drop - the buyer billed a setup fee for a line
                    // that was never produced.
                    $before = $this->lineSubtotalContribution($line);
                    $line->transitionTo(LineItemState::Dropped);
                    $totalDelta = -$before;
                    $notifyLineChanged = true;
                    break;
            }

            $this->audit->log($line, 'line_item.reconfirmed', null, $decision);

            if (round($totalDelta, 2) !== 0.0) {
                $this->retotalAfterReconfirm($line, $totalDelta);
            }
        });

        // Off by default (see OrderMilestone::LineChanged); OrderNotifier::send()
        // itself checks the toggle, so this only queues mail when staff have
        // opted in. Deferred to after the transaction commits, matching every
        // other milestone email in this service.
        if ($notifyLineChanged) {
            $quoteId = $line->quote_id;
            DB::afterCommit(function () use ($quoteId): void {
                $quote = Quote::find($quoteId);
                if ($quote !== null) {
                    $this->notifier->send($quote, OrderMilestone::LineChanged);
                }
            });
        }

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
            'gst_amount' => $quote->gst_amount,
            'total' => $quote->total,
        ];

        $quote->subtotal = round((float) $quote->subtotal + $totalDelta, 2);

        // GST is recomputed on the new (post-delta) subtotal + delivery base, at
        // the quote's own SNAPSHOT gst_rate - never the live tax.gst_pct config -
        // exactly like amend(). Adjustments stay outside the GST base.
        $gstRate = (float) $quote->gst_rate / 100;
        $quote->gst_amount = $this->pricing->gstAmount(
            (float) $quote->subtotal + (float) $quote->delivery,
            $gstRate,
        );

        // Keep the staff adjustments (discount/tax/fee) in the re-anchored total,
        // exactly as the amend path does - otherwise a reconfirmation would
        // quietly wipe them from what the buyer is invoiced.
        $quote->total = round(
            (float) $quote->subtotal + (float) $quote->delivery + (float) $quote->gst_amount + $quote->adjustmentsTotal(),
            2,
        );
        $quote->save();

        // The invoice amount was frozen at issue time; keep the authoritative
        // invoice figure (and its GST component) in lock-step with the amended
        // quote. Skips a VOID invoice and downgrades PAID->PARTIAL on a raise -
        // see reanchorInvoices()'s docblock.
        $this->reanchorInvoices($quote);

        $this->audit->log($quote, 'quote.retotaled_after_reconfirm', $before, [
            'subtotal' => $quote->subtotal,
            'gst_amount' => $quote->gst_amount,
            'total' => $quote->total,
            'line_item_id' => $line->id,
        ]);
    }

    /**
     * True once every line is resolved (READY or DROPPED) and at least one is
     * READY - i.e. there is something to make and nothing still undecided. A
     * wholly-dropped quote is never queued.
     */
    public function isReadyForProduction(Quote $quote): bool
    {
        $quote->loadMissing('lineItems');

        return $quote->state === QuoteState::Procuring
            && $quote->lineItems->every(fn ($line): bool => $line->line_state->isResolvedForQueue())
            && $quote->lineItems->contains(fn ($line): bool => $line->line_state === LineItemState::Ready);
    }

    /**
     * Queue the quote's jobs, but only once a person has confirmed the goods are
     * actually in hand.
     *
     * This used to fire automatically the moment the system believed every line
     * was resolved. Since most goods are bought in after the order is placed,
     * that belief rests on stock figures nobody maintains - so the floor could
     * be handed work for goods that had not arrived. The confirmation is now the
     * gate; see confirmStock().
     */
    private function tryQueue(Quote $quote): void
    {
        if ($this->isReadyForProduction($quote) && $quote->stock_confirmed_at !== null) {
            $this->queue->buildJobsForQuote($quote);

            return;
        }

        $this->cancelIfNothingLeftToProduce($quote);
    }

    /**
     * An order whose every line was dropped has nothing to make and no way
     * forward: jobs are never built, so it sat in PROCURING for good - finished
     * from the staff member's point of view, invisible to everyone else, and
     * never closed or cancelled. Cancel it explicitly instead.
     */
    private function cancelIfNothingLeftToProduce(Quote $quote): void
    {
        $quote->loadMissing('lineItems');

        if ($quote->state !== QuoteState::Procuring || $quote->lineItems->isEmpty()) {
            return;
        }

        $allDropped = $quote->lineItems->every(
            fn ($line): bool => $line->line_state === LineItemState::Dropped
        );

        if (! $allDropped) {
            return;
        }

        $previous = $quote->state->value;
        $quote->transitionTo(QuoteState::Cancelled);
        $this->audit->log($quote, 'quote.cancelled', null, [
            'reason' => 'Every line was dropped during procurement, leaving nothing to produce.',
        ]);

        DB::afterCommit(fn () => Broadcasting::dispatch(fn () => QuoteStateChanged::dispatch($quote, $previous)));
    }

    /**
     * Staff confirm the goods are in hand and release the order to the floor.
     *
     * Attributed deliberately. With the automatic checks advisory, this is the
     * only remaining safety net before production starts, so it records who
     * looked rather than being an anonymous button press.
     */
    public function confirmStock(Quote $quote): Quote
    {
        if (! $this->isReadyForProduction($quote)) {
            throw new DomainRuleException(
                'Every line must be resolved, with at least one to produce, before stock can be confirmed.'
            );
        }

        if ($quote->stock_confirmed_at !== null) {
            throw new DomainRuleException('Stock has already been confirmed for this order.');
        }

        return DB::transaction(function () use ($quote): Quote {
            $quote->stock_confirmed_at = now();
            $quote->stock_confirmed_by = Auth::id();
            $quote->save();

            $this->audit->log($quote, 'quote.stock_confirmed', null, [
                'confirmed_by' => $quote->stock_confirmed_by,
                'confirmed_at' => $quote->stock_confirmed_at?->toIso8601String(),
            ]);

            $this->tryQueue($quote->fresh(['lineItems']));

            // jobs.shipment: QuoteResource::shipmentSummary reads each job's
        // shipment (Stage 2a), so load it here to avoid an N+1 per job.
        return $quote->fresh(['lineItems', 'jobs.shipment']);
        });
    }

    /**
     * Queue the buyer-facing "quote (and proof) ready" email. Fires only after
     * the enclosing transaction commits, so a rolled-back send never emails.
     * No-ops silently if no buyer recipient can be resolved for the company.
     */
    private function emailQuoteReady(Quote $quote, bool $hasProof, ?Proof $specificProof = null): void
    {
        $recipient = $this->resolveBuyerRecipient($quote);
        if ($recipient === null) {
            return;
        }

        $proofImageUrl = null;
        // Resend targets ONE proof (versions are per-line, so the quote-wide
        // "latest version" can be a different line's artwork - M13). Fall back
        // to the latest only when no specific proof is given (the send() path).
        if ($hasProof && ($proof = $specificProof ?? $quote->proofs()->latest('version')->first()) !== null) {
            // PROOF_IMAGE_URL_TTL_DAYS (the presigned-URL ceiling on S3/Spaces),
            // shared with QuoteReadyMail's batched builder so the two proof paths
            // sign for the same lifetime. On a local dev disk this still resolves
            // to the (host-served) app route. Either way the
            // buyer opening the email within the week sees the artwork - and on
            // Spaces the link is a direct, reachable bucket URL, not localhost.
            //
            // A proof issued straight from the buyer's designer artwork is a
            // TRANSPARENT design-only PNG - on its own it reads as a logo
            // floating on white. Prefer the flattened design-on-product
            // composite; fall back to the raw artwork for uploaded proofs (or
            // when compositing is unavailable).
            $expiry = now()->addDays(QuoteReadyMail::PROOF_IMAGE_URL_TTL_DAYS);
            $proofImageUrl = $this->composites->signedCompositeUrl($proof, $expiry)
                ?? $proof->signedArtworkUrl($expiry);
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
