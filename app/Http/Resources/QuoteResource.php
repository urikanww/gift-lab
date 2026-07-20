<?php

declare(strict_types=1);

namespace App\Http\Resources;

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
            'notes' => $this->notes,
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
