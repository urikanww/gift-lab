<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Optional proof-with-quote payload. Authorization is the controller's
 * manageProduction gate. When artwork_version_ref is present the quote takes
 * the slim path (DRAFT -> PROOFING) with a v1 proof attached.
 */
class SendQuoteRequest extends FormRequest
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
            'artwork_version_ref' => ['nullable', 'string', 'max:2048'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
