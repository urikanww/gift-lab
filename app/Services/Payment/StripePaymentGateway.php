<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\Quote;
use App\Services\Payment\Contracts\PaymentGateway;
use Stripe\StripeClient;

/**
 * Stripe Checkout gateway (used when STRIPE_SECRET is set). Creates a hosted
 * Checkout Session in SGD for the quote total; capture is confirmed
 * asynchronously via the Stripe webhook (see StripeWebhookController).
 */
final class StripePaymentGateway implements PaymentGateway
{
    private StripeClient $stripe;

    public function __construct()
    {
        $this->stripe = new StripeClient((string) config('services.stripe.secret'));
    }

    /**
     * @return array{id: string, url: string}
     */
    public function createCheckout(Quote $quote): array
    {
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
        ]);

        return ['id' => $session->id, 'url' => (string) $session->url];
    }

    public function confirmsImmediately(): bool
    {
        return false;
    }
}
