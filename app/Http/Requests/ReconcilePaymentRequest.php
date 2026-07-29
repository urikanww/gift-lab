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
            // H3/M21: PARTIAL must carry the amount actually collected so a later
            // cancel/refund credits only that, and staff can see the balance
            // owed. Must be positive; the upper bound (< invoice total, which
            // would be PAID) is enforced in the service where the invoice is in
            // hand. Ignored for PAID (stamped to the full amount) and VOID.
            'amount_paid' => ['required_if:payment_state,PARTIAL', 'nullable', 'numeric', 'gt:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
