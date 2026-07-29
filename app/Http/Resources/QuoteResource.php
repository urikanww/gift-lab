<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\LineItemState;
use App\Enums\ProofState;
use App\Models\PricingConfig;
use App\Models\Quote;
use App\Services\OrderNotifier;
use App\Services\ReminderSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Quote
 */
class QuoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            // Opaque order reference for buyer/public URLs (/orders/{reference}).
            'reference' => $this->reference,
            // Opaque handle the buyer can share for login-free tracking.
            'tracking_code' => $this->tracking_code,
            // Permanent signed deep link for the buyer's confirmation/QR.
            'tracking_link' => app(\App\Services\OrderTracker::class)->signedFrontendLink($this->resource),
            // Present only when the relation is loaded (staff listings). Null-safe:
            // Company soft-deletes, so a loaded relation can still be null.
            'company_name' => $this->whenLoaded('company', fn () => $this->company?->name),
            'state' => $this->state->value,
            'currency' => $this->currency,
            'subtotal' => $this->subtotal,
            // LT16: the personalisation/decoration fee is folded into subtotal
            // but has no line of its own, so the item rows (unit*qty) visibly
            // sum to LESS than subtotal with an unexplained gap. Surface the
            // aggregate fee (subtotal - sum of active line totals) so the client
            // can render it as its own row and the numbers reconcile. Only
            // meaningful once lineItems is loaded; omitted otherwise.
            'customization_fee' => $this->when(
                $this->relationLoaded('lineItems'),
                fn (): string => $this->customizationFeeTotal(),
            ),
            'delivery' => $this->delivery,
            // GST amount + rate (pct, e.g. "9.00") snapshotted at create/amend
            // time by QuoteService - see PricingService::quoteTotals(). Rate is
            // frozen per-quote, so a later config edit never reprices a past
            // order.
            'gst' => $this->gst_amount,
            'gst_rate' => $this->gst_rate,
            // Free-form staff adjustments after delivery (discount/surcharge).
            // GST is computed automatically (see the gst/gst_rate keys above)
            // and must never be added here - it would double the tax.
            // Buyer-visible on purpose: they move what is owed and appear on the
            // invoice, so hiding them would leave an unexplained total. Always an
            // array (never null) so the client renders it without a guard.
            'adjustments' => $this->adjustments ?? [],
            'total' => $this->total,
            'price_snapshot_at' => $this->price_snapshot_at?->toIso8601String(),
            // The production gate. Null while the order is still waiting for a
            // person to confirm the goods are in hand.
            'stock_confirmed_at' => $this->stock_confirmed_at?->toIso8601String(),
            'stock_confirmed_by' => $this->stock_confirmed_by,
            // Whether buyer self-service payment is actually available. The Pay
            // now button used to render for every buyer regardless: on a B2B
            // tenant, where it is off by default, it always failed - and the
            // failure used to blank the whole order page.
            'pay_now_enabled' => (bool) (
                ((array) PricingConfig::value('config', 'pay_now_cutoff', ['b2c_enabled' => false]))['b2c_enabled'] ?? false
            ),
            'notes' => $this->notes,
            // Staff-only edit trail for DRAFT amendments: what changed, who
            // changed it and when. Carries internal prices and margins, so it is
            // gated on staff and never serialised into a buyer's payload. Empty
            // array (not absent) for staff on an order that was never amended, so
            // the client can render "no edits yet" without a null dance.
            'amendment_log' => $this->when(
                (bool) ($request->user()?->isStaff() ?? false),
                fn (): array => $this->amendment_log ?? [],
            ),
            // Buyer's requested delivery deadline (Y-m-d); null when unset.
            'needed_by' => $this->needed_by?->toDateString(),
            // Both child resources expose quote_reference, reached through their
            // own quote relation. Hand them this quote rather than letting each
            // row lazy-load it - the parent IS their quote, so an eager-load
            // would only re-fetch the row we already hold, and no eager-load at
            // all would be one query per line/proof.
            'line_items' => LineItemResource::collection(
                $this->whenLoaded('lineItems', fn () => $this->lineItems->each(
                    fn ($item) => $item->setRelation('quote', $this->resource)
                ))
            ),
            'proofs' => ProofResource::collection(
                $this->whenLoaded('proofs', function () use ($request) {
                    $isStaff = (bool) ($request->user()?->isStaff() ?? false);

                    // Buyers must not see unsent DRAFT proofs (staged, not yet
                    // sent). Staff do - staging happens on DRAFTs. M12.
                    return $this->proofs
                        ->filter(fn ($proof): bool => $isStaff || $proof->state !== ProofState::Draft)
                        ->each(fn ($proof) => $proof->setRelation('quote', $this->resource))
                        ->values();
                })
            ),
            // Staff-only buyer-notification picture for this order: which milestone
            // email the buyer was sent on reaching the current state (paired with
            // the transition time from the history endpoint on the client), and
            // when the next automatic chase is due. Drives the order page's
            // notification panel. Carries no buyer address, but it exposes the
            // internal chase cadence, so it is gated on staff.
            'reminder' => $this->when(
                (bool) ($request->user()?->isStaff() ?? false),
                fn (): array => $this->reminderSummary(),
            ),
            // Staff-only: the B2B invoice raised against this order (once
            // issueInvoice has fired), driving the payment-reconciliation
            // control on the order page. Null until an invoice exists.
            'invoice' => $this->when(
                (bool) ($request->user()?->isStaff() ?? false),
                fn (): ?array => $this->invoiceSummary(),
            ),
            // Staff-only: the order's production jobs and their shipment status,
            // so the order page can show what has shipped and confirm delivery
            // without opening the production queue.
            'shipments' => $this->when(
                (bool) ($request->user()?->isStaff() ?? false),
                fn (): array => $this->shipmentSummary(),
            ),
            // Light per-line preview (product name + thumbnail + qty) for list
            // and reorder-rail cards, so they can show what's in an order without
            // the full LineItemResource. Present only when lineItems are loaded.
            'items_preview' => $this->whenLoaded('lineItems', fn (): array => $this->lineItems->map(fn ($line): array => [
                'name' => $line->product?->name,
                'image_url' => $line->product?->image_url,
                'qty' => (int) $line->qty,
            ])->values()->all()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Staff-only shipment view: each production job with its state, carrier and
     * consignment ref. Empty until jobs are built, and only populated when the
     * jobs relation is loaded (show() eager-loads it) to avoid an N+1.
     *
     * @return array<int, array{job_id: int, state: string, carrier: ?string, consignment_ref: ?string, delivered_at: ?string}>
     */
    private function shipmentSummary(): array
    {
        if (! $this->relationLoaded('jobs')) {
            return [];
        }

        return $this->jobs->map(fn ($job): array => [
            'job_id' => $job->id,
            'state' => $job->state->value,
            'carrier' => $job->carrier?->value,
            'consignment_ref' => $job->consignment_ref,
            'delivered_at' => $job->delivered_at?->toIso8601String(),
        ])->values()->all();
    }

    /**
     * The aggregate personalisation/decoration fee baked into subtotal: subtotal
     * minus the sum of active (non dropped/cancelled) line totals. Formatted to
     * 2dp as a string to match the other money fields. Dropped/cancelled lines
     * contribute nothing to subtotal, so they're excluded here too, or the fee
     * would read low (or negative) on an amended order.
     */
    private function customizationFeeTotal(): string
    {
        $activeLineTotals = $this->lineItems
            ->reject(fn ($line): bool => in_array(
                $line->line_state,
                [LineItemState::Dropped, LineItemState::Cancelled],
                true,
            ))
            ->reduce(fn (float $carry, $line): float => $carry + (float) $line->lineTotal(), 0.0);

        return number_format(max(0.0, round((float) $this->subtotal - $activeLineTotals, 2)), 2, '.', '');
    }

    /**
     * @return array{id: int, po_ref: string, invoice_ref: ?string, amount: string, amount_paid: ?string, balance_owed: float, currency: string, payment_state: string, gst: string, gst_rate: string}|null
     */
    private function invoiceSummary(): ?array
    {
        // Only meaningful once purchaseOrders is eager-loaded (the show()
        // action does this); other actions that return a QuoteResource
        // without it simply omit the invoice rather than firing an N+1 query.
        if (! $this->relationLoaded('purchaseOrders')) {
            return null;
        }

        // At most one invoice per quote today - issueInvoice's TOCTOU guard
        // returns the existing row rather than minting a second - so the
        // latest is THE invoice.
        $invoice = $this->purchaseOrders->last();
        if ($invoice === null) {
            return null;
        }

        return [
            'id' => $invoice->id,
            'po_ref' => $invoice->po_ref,
            'invoice_ref' => $invoice->invoice_ref,
            'amount' => $invoice->amount,
            // H3/M21: how much has been collected, and what's still owed - so
            // staff can see a PARTIAL invoice's outstanding balance, and a
            // delivered-but-unpaid order (LT14) reads its own gap.
            'amount_paid' => $invoice->amount_paid,
            'balance_owed' => $invoice->balanceOwed(),
            'currency' => $invoice->currency,
            'payment_state' => $invoice->payment_state->value,
            // GST already folded into `amount` (see Invoice::gst_amount/gst_rate
            // in QuoteService::issueInvoice); surfaced separately so the invoice
            // panel can show it as its own line, same as the quote breakdown.
            'gst' => $invoice->gst_amount,
            'gst_rate' => $invoice->gst_rate,
        ];
    }

    /**
     * @return array{current_milestone: ?string, current_milestone_enabled: bool, last_reminded_at: ?string, next: array<string, mixed>|null}
     */
    private function reminderSummary(): array
    {
        $milestone = OrderNotifier::milestoneForState($this->state);
        $notifier = app(OrderNotifier::class);

        return [
            // The buyer email tied to the CURRENT state (null when the state is
            // silent, e.g. PROOF_APPROVED). The client pairs the key with the
            // matching transition time from the history trail to show "sent when".
            'current_milestone' => $milestone?->value,
            'current_milestone_enabled' => $milestone !== null && $notifier->isEnabled($milestone),
            'last_reminded_at' => $this->last_reminded_at?->toIso8601String(),
            // Forward-looking: the next automatic chase, or null when none pends.
            'next' => app(ReminderSchedule::class)->next($this->resource),
        ];
    }
}
