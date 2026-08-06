<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreVariantsBulkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isStaff() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'variants' => ['required', 'array', 'min:1', 'max:200'],
            'variants.*.option' => ['required', 'string', 'max:100'],
            'variants.*.price_delta' => ['nullable', 'numeric'],
        ];
    }
}
