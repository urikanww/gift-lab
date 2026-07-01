<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Designer artwork upload (public — the designer is account-free until Request
 * Quote, spec 6.1). Validates type + size so only real images are stored; the
 * returned ref becomes the line's customization artwork_ref and, once a proof is
 * approved, the production print file.
 *
 * SVG is deliberately excluded: SVG is an XML document that can carry inline
 * <script>/onload handlers and would execute in the victim's origin if the
 * stored file were ever served inline (stored XSS, OWASP A03). Raster formats
 * carry no active content, so we accept only those.
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
            'artwork' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:10240'],
        ];
    }
}
