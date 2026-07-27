<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Exceptions\PaymentGatewayException;
use App\Models\Quote;
use App\Services\Payment\Contracts\PaymentGateway;
use Illuminate\Support\Facades\Log;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiErrorException;
use Stripe\HttpClient\CurlClient;
use Stripe\StripeClient;

/**
 * Stripe Checkout gateway (used when STRIPE_SECRET is set). Creates a hosted
 * Checkout Session in SGD for the quote total; capture is confirmed
 * asynchronously via the Stripe webhook (see StripeWebhookController).
 *
 * Guarded the same way HttpNinjaVanClient guards its outbound calls: bounded
 * connect/read timeouts instead of the SDK's generous defaults (30s connect /
 * 80s read) and a small number of automatic retries for transient network
 * faults. The Stripe SDK rejects unknown constructor config keys, so the
 * timeouts are set on a dedicated CurlClient wired in via
 * ApiRequestor::setHttpClient() - the SDK's documented mechanism for
 * per-request timeout control - while max_network_retries is a real
 * constructor option.
 */
final class StripePaymentGateway implements PaymentGateway
{
    private static bool $httpClientConfigured = false;

    private StripeClient $stripe;

    public function __construct()
    {
        self::configureHttpClient();

        $this->stripe = new StripeClient([
            'api_key' => (string) config('services.stripe.secret'),
            'max_network_retries' => (int) config('services.stripe.max_network_retries', 2),
        ]);
    }

    /**
     * @return array{id: string, url: string}
     */
    public function createCheckout(Quote $quote): array
    {
        try {
            $session = $this->stripe->checkout->sessions->create([
                'mode' => 'payment',
                'client_reference_id' => (string) $quote->id,
                'metadata' => ['quote_id' => (string) $quote->id],
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => strtolower($quote->currency),
                        'unit_amount' => (int) round((float) $quote->total * 100), // cents
                        'product_data' => ['name' => "Gift Lab order #{$quote->id}"],
                    ],
                ]],
                'success_url' => rtrim((string) config('app.url'), '/')."/orders/{$quote->id}?paid=1",
                'cancel_url' => rtrim((string) config('app.url'), '/')."/orders/{$quote->id}?paid=0",
            ], [
                // Deterministic per quote-state key: a retry (client timeout,
                // network blip) with the SAME total replays safely instead of
                // double-charging, while an amended total (GST-inclusive,
                // already final by the time it reaches here) mints a fresh
                // key so Stripe doesn't 400 on "same params, different
                // amount" for what is legitimately a different checkout.
                'idempotency_key' => self::idempotencyKey($quote),
            ]);
        } catch (ApiErrorException $e) {
            Log::error('Stripe checkout session creation failed.', [
                'quote_id' => $quote->id,
                'amount' => $quote->total,
                'stripe_error' => $e->getMessage(),
            ]);

            throw PaymentGatewayException::checkoutFailed($e);
        }

        return ['id' => $session->id, 'url' => (string) $session->url];
    }

    public function confirmsImmediately(): bool
    {
        return false;
    }

    /**
     * "paynow_{quote_id}_{hash(total|currency)}" - stable across identical
     * retries (so a client timeout that actually succeeded upstream doesn't
     * double-charge), but changes whenever the total or currency changes (so
     * an amended quote isn't blocked by Stripe replaying the old amount
     * against a reused key). $quote->total is already GST-inclusive by the
     * time it reaches this gateway - no GST re-derivation happens here.
     */
    private static function idempotencyKey(Quote $quote): string
    {
        $fingerprint = substr(
            hash('sha256', $quote->total.'|'.strtolower($quote->currency)),
            0,
            32,
        );

        return "paynow_{$quote->id}_{$fingerprint}";
    }

    /**
     * Wires a CurlClient with bounded connect/read timeouts into the Stripe
     * SDK's global HTTP client slot. ApiRequestor::setHttpClient() is
     * process-global (the SDK has no per-StripeClient HTTP client override),
     * so this configures it once per process rather than reconstructing a
     * CurlClient on every gateway instantiation.
     */
    private static function configureHttpClient(): void
    {
        if (self::$httpClientConfigured) {
            return;
        }

        $curlClient = new CurlClient();
        $curlClient->setConnectTimeout((int) config('services.stripe.connect_timeout', 5));
        $curlClient->setTimeout((int) config('services.stripe.read_timeout', 20));

        ApiRequestor::setHttpClient($curlClient);

        self::$httpClientConfigured = true;
    }
}
