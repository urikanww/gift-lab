<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\Quote;
use App\Services\Payment\Contracts\PaymentGateway;

/**
 * Default gateway when no Stripe secret is configured. Returns a synthetic
 * checkout and reports immediate capture, so the B2C pay-now flow runs
 * end-to-end in dev/test without live Stripe credentials.
 */
final class FixturePaymentGateway implements PaymentGateway
{
    /**
     * @return array{id: string, url: string}
     */
    public function createCheckout(Quote $quote): array
    {
        return [
            'id' => 'fixture_'.$quote->id,
            'url' => "https://checkout.local/fixture/{$quote->id}",
        ];
    }

    public function confirmsImmediately(): bool
    {
        return true;
    }
}
