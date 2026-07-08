<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Floor operator advances a production job's state (spec 5.4). Only forward
 * transitions are accepted; the model guards legality.
 */
class AdvanceJobRequest extends FormRequest
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
            'state' => ['required', 'string', 'in:IN_PRODUCTION,SHIPPED,CLOSED'],
            // Shipping fires the buyer's "on the way" signal - require a real
            // consignment/tracking reference so it is a deliberate handover.
            'consignment_ref' => ['nullable', 'string', 'max:128', 'required_if:state,SHIPPED'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'consignment_ref.required_if' => 'A consignment/tracking reference is required to mark a job shipped.',
        ];
    }
}
