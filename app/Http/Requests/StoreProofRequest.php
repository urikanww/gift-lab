<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Staff issues a formal proof to the buyer for sign-off. artwork_version_ref is
 * the object-store key of the production-grade artwork (spec 7): once approved
 * it IS the print file, so no re-processing step exists.
 */
class StoreProofRequest extends FormRequest
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
            'artwork_version_ref' => ['required', 'string', 'max:2048'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
