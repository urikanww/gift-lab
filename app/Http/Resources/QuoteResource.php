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
            // Opaque handle the buyer can share for login-free tracking.
            'tracking_code' => $this->tracking_code,
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
            'line_items' => LineItemResource::collection($this->whenLoaded('lineItems')),
            'proofs' => ProofResource::collection($this->whenLoaded('proofs')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
