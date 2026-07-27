<?php

declare(strict_types=1);

namespace App\Http\Resources;

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
            'delivery' => $this->delivery,
            // Free-form staff adjustments after delivery (discount/tax/fee).
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
                $this->whenLoaded('proofs', fn () => $this->proofs->each(
                    fn ($proof) => $proof->setRelation('quote', $this->resource)
                ))
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
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{id: int, po_ref: string, invoice_ref: ?string, amount: string, currency: string, payment_state: string}|null
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
            'currency' => $invoice->currency,
            'payment_state' => $invoice->payment_state->value,
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
