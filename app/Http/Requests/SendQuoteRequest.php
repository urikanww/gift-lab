<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Plain quote send (DRAFT -> SENT). Authorization is the controller's
 * manageProduction gate. Proofs now go through per-line stage -> send, so this
 * request carries no artwork payload.
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
        return [];
    }
}
