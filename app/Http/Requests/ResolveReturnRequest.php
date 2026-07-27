<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Staff resolve a returned/failed NinjaVan parcel (QueueService::resolveReturn).
 * Validation only - authorization is the controller's manageProduction gate
 * (staff/superadmin), same pattern as CancelQuoteRequest.
 */
class ResolveReturnRequest extends FormRequest
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
            'disposition' => ['required', 'string', 'in:close,reship,cancel_credit'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
