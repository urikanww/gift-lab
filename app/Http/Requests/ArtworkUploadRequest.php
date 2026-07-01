<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Designer artwork upload (public — the designer is account-free until Request
 * Quote, spec 6.1). Validates type + size so only real images are stored; the
 * returned ref becomes the line's customization artwork_ref and, once a proof is
 * approved, the production print file.
 */
class ArtworkUploadRequest extends FormRequest
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
            'artwork' => ['required', 'file', 'mimes:png,jpg,jpeg,svg,webp', 'max:10240'],
        ];
    }
}
