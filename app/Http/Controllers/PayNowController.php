<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Quote;
use App\Services\Payment\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Starts a B2C pay-now checkout for a proof-approved quote. Returns the checkout
 * URL; with the fixture gateway the payment is captured immediately and the
 * response reports paid=true (quote already advancing into production).
 */
class PayNowController extends Controller
{
    public function __construct(private readonly PaymentService $payments)
    {
    }

    public function pay(Request $request, Quote $quote): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isStaff() || $user->company_id === $quote->company_id, 403);

        $result = $this->payments->payNow($quote);

        return response()->json([
            'checkout_url' => $result['checkout']['url'],
            'session_id' => $result['checkout']['id'],
            'paid' => $result['paid'],
            'quote_state' => $quote->fresh()->state->value,
        ]);
    }
}
