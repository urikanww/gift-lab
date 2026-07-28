<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Staff manually confirm a shipped parcel was delivered (QueueService::
 * markDelivered) - the fallback for when NinjaVan's delivery webhook doesn't
 * arrive. Validation only; authorization is the controller's manageProduction
 * gate (staff/superadmin), same pattern as ResolveReturnRequest.
 */
class MarkDeliveredRequest extends FormRequest
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
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
