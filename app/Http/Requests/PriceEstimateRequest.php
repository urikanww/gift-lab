<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Public live price estimate for the no-account designer (spec 6.1). Estimate
 * only — the authoritative price is frozen at quote time.
 */
class PriceEstimateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'line_items' => ['required', 'array', 'min:1', 'max:100'],
            'line_items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'line_items.*.variant_id' => ['nullable', 'integer', 'exists:variants,id'],
            'line_items.*.qty' => ['required', 'integer', 'min:1', 'max:100000'],
            'line_items.*.has_customization' => ['nullable', 'boolean'],
            'line_items.*.logo_size' => ['nullable', 'string', 'in:S,M,L'],
            // Name/text personalisation present on the line (audit D9) — adds
            // the per-unit text fee to the live estimate.
            'line_items.*.has_text' => ['nullable', 'boolean'],
        ];
    }
}
