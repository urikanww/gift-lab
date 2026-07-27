<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Staff manually reconcile a B2B invoice against real-world payment evidence
 * (bank transfer, cheque, cash) - there is no Stripe path for B2B. Validation
 * only: authorization is the controller's manageProduction gate plus the
 * quotes.edit route middleware, same split as CancelQuoteRequest.
 */
class ReconcilePaymentRequest extends FormRequest
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
            // UNPAID is the state issueInvoice() starts every invoice in, not a
            // reconciliation target - staff record what was actually observed
            // (paid in full, paid in part, or written off), never "un-pay" one.
            'payment_state' => ['required', 'string', Rule::in(['PAID', 'PARTIAL', 'VOID'])],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
