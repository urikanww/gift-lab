<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Buyer saves their company brand kit. Logo is a data URL (reloadable into the
 * canvas without CORS); colours are hex swatches.
 */
class UpdateBrandKitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->company_id !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'colors' => ['nullable', 'array', 'max:8'],
            'colors.*' => ['string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            // ~2 MB of base64. Must be an inline image data URL.
            'logo' => ['nullable', 'string', 'max:3000000', 'starts_with:data:image/'],
        ];
    }
}
