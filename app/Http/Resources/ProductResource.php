<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'class' => $this->class->value,
            'base_cost' => $this->base_cost,
            'currency' => $this->currency,
            'dimensions' => $this->dimensions,
            'weight' => $this->weight,
            'print_method' => $this->print_method?->value,
            'stock_mode' => $this->stock_mode->value,
            'image_url' => $this->image_url,
            'is_printable' => $this->is_printable,
            'creator_credit' => $this->creator_credit,
            'variants' => VariantResource::collection($this->whenLoaded('variants')),
        ];
    }
}
