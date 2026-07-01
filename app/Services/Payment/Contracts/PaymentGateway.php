<?php

declare(strict_types=1);

namespace App\Services\Payment\Contracts;

use App\Models\Quote;

/**
 * B2C "pay now" gateway (spec: deferred, feeds the same queue). A concrete
 * gateway creates a checkout for a proof-approved quote; capture is confirmed
 * either immediately (fixture/dev) or via webhook (Stripe).
 */
interface PaymentGateway
{
    /**
     * @return array{id: string, url: string}
     */
    public function createCheckout(Quote $quote): array;

    /**
     * Whether payment is captured synchronously (fixture) vs via async webhook
     * (Stripe). Lets the service auto-confirm in dev/test without a webhook.
     */
    public function confirmsImmediately(): bool;
}
