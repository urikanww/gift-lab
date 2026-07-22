<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PricingConfig;
use App\Models\Quote;
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
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
