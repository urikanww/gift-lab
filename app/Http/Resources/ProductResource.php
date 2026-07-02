<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Product;
use App\Services\PricingService;
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
            // Canonical public URL key — frontends should link by slug, not id.
            'slug' => $this->slug,
            'description' => $this->description,
            'class' => $this->class->value,
            // Public marketplace category (how buyers browse) — see CategoryClassifier.
            'category' => $this->category,
            // Indicative sell price (qty 1, no variant), NOT the raw pre-margin
            // supplier cost. base_cost is internal — exposing it on the public
            // catalogue let anyone back out the margin (business-intel leak).
            'from_price' => app(PricingService::class)->unitPrice($this->resource, null, 1),
            'currency' => $this->currency,
            'dimensions' => $this->dimensions,
            'weight' => $this->weight,
            'print_method' => $this->print_method?->value,
            'stock_mode' => $this->stock_mode->value,
            'image_url' => $this->image_url,
            'is_printable' => $this->is_printable,
            'creator_credit' => $this->creator_credit,
            // Interactive viewer availability: we hold a local model file
            // (never exposes the storage path itself).
            'has_model' => $this->class === \App\Enums\ProductClass::Model3d
                && (string) $this->model_file_ref !== ''
                && ! str_starts_with((string) $this->model_file_ref, 'http'),
            'variants' => VariantResource::collection($this->whenLoaded('variants')),
        ];
    }
}
