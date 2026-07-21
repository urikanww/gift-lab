<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\LineItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LineItem
 */
class LineItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'quote_id' => $this->quote_id,
            // The displayed order identifier. quote_id stays because the realtime
            // stores join incoming broadcasts against on-screen rows by it.
            'quote_reference' => $this->quote?->reference,
            'job_id' => $this->job_id,
            'product_id' => $this->product_id,
            'variant_id' => $this->variant_id,
            'qty' => $this->qty,
            'unit_price' => $this->unit_price,
            'currency' => $this->currency,
            'line_total' => $this->lineTotal(),
            'customization' => $this->customization,
            'line_state' => $this->line_state->value,
            'procured_qty' => $this->procured_qty,
            'procured_price' => $this->procured_price,
            // Advisory finding from procurement - a shortfall that no longer
            // blocks the order. Staff check it at the production gate.
            'procurement_note' => $this->procurement_note,
            'lead_time_days' => $this->lead_time_days,
            'product' => new ProductResource($this->whenLoaded('product')),
        ];
    }
}
