<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\ProductClass;
use App\Enums\StockMode;
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
            // Canonical public URL key - frontends should link by slug, not id.
            'slug' => $this->slug,
            'description' => $this->description,
            'class' => $this->class->value,
            // Public marketplace category (how buyers browse) - see CategoryClassifier.
            'category' => $this->category,
            // Indicative sell price (qty 1, no variant), NOT the raw pre-margin
            // supplier cost. base_cost is internal - exposing it on the public
            // catalogue let anyone back out the margin (business-intel leak).
            'from_price' => app(PricingService::class)->unitPrice($this->resource, null, 1),
            'currency' => $this->currency,
            'dimensions' => $this->dimensions,
            'weight' => $this->weight,
            'print_method' => $this->print_method?->value,
            'stock_mode' => $this->stock_mode->value,
            // Customer-facing availability, honest about on-demand items: a
            // STOCKED blank at zero stock reads "made to order" when backorder
            // is allowed, "out of stock" otherwise. 3D/make-to-order is always
            // made to order.
            'availability' => $this->availabilityStatus(),
            'image_url' => $this->image_url,
            'is_printable' => $this->is_printable,
            'creator_credit' => $this->creator_credit,
            // Interactive viewer availability: we hold a local model file
            // (never exposes the storage path itself).
            'has_model' => $this->class === ProductClass::Model3d
                && (string) $this->model_file_ref !== ''
                && ! str_starts_with((string) $this->model_file_ref, 'http'),
            // Admin-authored decoration zone (model-space mm) - drives the
            // customer decal preview + the zone-constrained designer mapping.
            'print_zone' => $this->print_zone,
            // True when an authored GLB is stored (preferred preview mesh).
            'has_glb' => $this->decor_glb_ref !== null,
            'variants' => VariantResource::collection($this->whenLoaded('variants')),
        ];
    }

    /**
     * in_stock | made_to_order | out_of_stock. Uses the eager-loaded variants
     * (the catalogue always loads them) to avoid an N+1; falls back to the
     * stock mode + backorder flag when variants aren't loaded.
     */
    private function availabilityStatus(): string
    {
        if ($this->stock_mode === StockMode::MakeToOrder) {
            return 'made_to_order';
        }

        if ($this->relationLoaded('variants')) {
            $anyInStock = $this->variants->contains(fn ($v): bool => $v->stock_on_hand > 0);
            if ($anyInStock) {
                return 'in_stock';
            }
        }

        return $this->allow_backorder ? 'made_to_order' : 'out_of_stock';
    }
}
