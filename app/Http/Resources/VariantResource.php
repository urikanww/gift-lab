<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Variant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Variant
 */
class VariantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // Read the DB column explicitly to avoid clashing with the model's
            // internal attribute bag when accessed through the resource proxy.
            'attributes' => $this->resource->getAttribute('attributes'),
            'sku' => $this->sku,
            'price_delta' => $this->price_delta,
            'currency' => $this->currency,
            // Indicative availability only; never the authoritative read (spec 3).
            'in_stock' => $this->stock_on_hand > 0,
        ];
    }
}
