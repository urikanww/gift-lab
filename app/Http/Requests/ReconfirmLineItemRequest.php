<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Staff resolves a line item stuck in AWAITING_RECONFIRM after a failed stock/
 * price re-check (spec 5.2): amend (re-procure at new qty/price), approve the
 * jumped price, or drop the line. One failed line never kills the others.
 */
class ReconfirmLineItemRequest extends FormRequest
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
            'action' => ['required', 'string', 'in:amend,approve,drop'],
            'qty' => ['nullable', 'integer', 'min:1', 'max:100000', 'required_if:action,amend'],
            'unit_price' => ['nullable', 'numeric', 'min:0', 'required_if:action,amend'],
        ];
    }
}
