<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Public delivery-estimate request for the no-account designer/cart. Estimate
 * only - the authoritative promise is made when the order is confirmed.
 */
class LeadTimeEstimateRequest extends FormRequest
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
        ];
    }
}
