<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Staff issues the PO/invoice for a proof-approved quote. Payment is reconciled
 * manually in the B2B launch (no Stripe), so payment_state starts UNPAID.
 */
class IssuePurchaseOrderRequest extends FormRequest
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
            'po_ref' => ['required', 'string', 'max:64', 'unique:invoices,po_ref'],
            'invoice_ref' => ['nullable', 'string', 'max:64', 'unique:invoices,invoice_ref'],
            'terms' => ['nullable', 'string', 'max:255'],
        ];
    }
}
