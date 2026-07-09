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
            // Per-line saved customization + the product's model/zone, so the floor
            // can inspect what to make and visualize the decorated final product.
            'line_items' => $this->whenLoaded('lineItems', fn () => $this->lineItems->map(function ($item): array {
                $product = $item->product;

                return [
                    'id' => $item->id,
                    'qty' => $item->qty,
                    'product' => $product ? [
                        'id' => $product->id,
                        'name' => $product->name,
                        'slug' => $product->slug,
                        'class' => $product->class?->value,
                        'has_model' => $product->model_file_ref !== null
                            && ! str_starts_with((string) $product->model_file_ref, 'http'),
                        'print_zone' => $product->print_zone,
                        // Every printable part of a multi-part figure, so the floor
                        // can download and print each piece (head/body/limbs), not
                        // just the primary mesh. Empty for single-mesh products.
                        'model_parts' => $product->relationLoaded('modelParts')
                            ? $product->modelParts->map(fn ($part): array => [
                                'id' => $part->id,
                                'label' => $part->label,
                                'triangle_count' => $part->triangle_count,
                                'is_primary' => (bool) $part->is_primary,
                                'sort' => $part->sort,
                            ])->values()
                            : [],
                    ] : null,
                    // Raw saved customization (filament_color, text, logo_size,
                    // artwork_ref, print_file_ref, layout, mode, placement_notes).
                    'customization' => $item->customization,
                ];
            })->values()),
        ];
    }
}
