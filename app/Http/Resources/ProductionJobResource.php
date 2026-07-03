<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ProductionJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProductionJob
 */
class ProductionJobResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'quote_id' => $this->quote_id,
            'track' => $this->track->value,
            'state' => $this->state->value,
            'ready_at' => $this->ready_at?->toIso8601String(),
            'artwork_ref' => $this->artwork_ref,
            'consignment_ref' => $this->consignment_ref,
            'print_method' => $this->print_method?->value,
            'qty' => $this->qty,
        ];
    }
}
