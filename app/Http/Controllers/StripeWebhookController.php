<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Quote;
use App\Services\Payment\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Webhook;

/**
 * Stripe webhook: confirms payment capture out-of-band. The signature is
 * verified with the endpoint secret; on checkout.session.completed the linked
 * quote is driven into production. Unauthenticated (Stripe calls it) but
 * authenticated by signature — never trust the body alone.
 */
class StripeWebhookController extends Controller
{
    public function handle(Request $request, PaymentService $payments): JsonResponse
    {
        $secret = (string) config('services.stripe.webhook_secret');
        if ($secret === '') {
            return response()->json(['message' => 'Webhook not configured.'], 400);
        }

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
                $secret,
            );
        } catch (\Throwable $e) {
            Log::warning('Stripe webhook signature verification failed.', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        if ($event->type === 'checkout.session.completed') {
            $session = $event->data->object;
            $quoteId = (int) ($session->metadata->quote_id ?? 0);
            $quote = Quote::find($quoteId);

            if ($quote !== null) {
                $payments->confirmPaid($quote, (string) $session->id);
            } else {
                // A verified event whose quote can't be resolved means a metadata
                // regression (missing/renamed quote_id) or a deleted quote — log
                // it so the silent no-op is visible rather than invisibly dropped.
                Log::warning('Stripe checkout.session.completed for unknown quote.', [
                    'quote_id' => $quoteId,
                    'session_id' => (string) $session->id,
                ]);
            }
        }

        return response()->json(['received' => true]);
    }
}
