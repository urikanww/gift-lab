<?php

declare(strict_types=1);

use App\Models\Quote;
use App\Services\Payment\StripePaymentGateway;
use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;

/**
 * Never exercises createCheckout() itself - that hits the real Stripe API via
 * the SDK's own cURL client, which Http::fake() cannot intercept. Instead this
 * covers the two hardening pieces directly: the deterministic idempotency key
 * formula (via reflection on the private static helper) and the
 * timeout/retry configuration the constructor wires up - both fully testable
 * without a network call.
 */
function makeStripeTestQuote(int $id, float $total, string $currency = 'SGD'): Quote
{
    $quote = new Quote(['currency' => $currency, 'total' => $total]);
    $quote->id = $id;

    return $quote;
}

function callIdempotencyKey(Quote $quote): string
{
    $method = new ReflectionMethod(StripePaymentGateway::class, 'idempotencyKey');
    $method->setAccessible(true);

    return $method->invoke(null, $quote);
}

beforeEach(function (): void {
    config(['services.stripe.secret' => 'sk_test_fake']);
});

it('produces a stable idempotency key across two calls with the same total', function (): void {
    $quoteA = makeStripeTestQuote(42, 92.65);
    $quoteB = makeStripeTestQuote(42, 92.65);

    expect(callIdempotencyKey($quoteA))->toBe(callIdempotencyKey($quoteB))
        ->and(callIdempotencyKey($quoteA))->toStartWith('paynow_42_');
});

it('changes the idempotency key when the total changes (an amended quote must not replay the old amount)', function (): void {
    $before = callIdempotencyKey(makeStripeTestQuote(42, 92.65));
    $after = callIdempotencyKey(makeStripeTestQuote(42, 150.00));

    expect($before)->not->toBe($after);
});

it('changes the idempotency key when the currency changes for an otherwise identical total', function (): void {
    $sgd = callIdempotencyKey(makeStripeTestQuote(42, 92.65, 'SGD'));
    $usd = callIdempotencyKey(makeStripeTestQuote(42, 92.65, 'USD'));

    expect($sgd)->not->toBe($usd);
});

it('keeps the idempotency key scoped per quote id', function (): void {
    $quote1 = callIdempotencyKey(makeStripeTestQuote(1, 92.65));
    $quote2 = callIdempotencyKey(makeStripeTestQuote(2, 92.65));

    expect($quote1)->not->toBe($quote2)
        ->and($quote1)->toStartWith('paynow_1_')
        ->and($quote2)->toStartWith('paynow_2_');
});

it('configures bounded connect/read timeouts on the Stripe HTTP client', function (): void {
    config([
        'services.stripe.connect_timeout' => 3,
        'services.stripe.read_timeout' => 11,
    ]);

    // configureHttpClient() only runs once per process (ApiRequestor's HTTP
    // client slot is a process-global singleton, not per-StripeClient), so
    // force it to re-run here with THIS test's config rather than trusting
    // whatever ran first across the suite.
    $flag = new ReflectionProperty(StripePaymentGateway::class, 'httpClientConfigured');
    $flag->setAccessible(true);
    $flag->setValue(null, false);

    new StripePaymentGateway();

    $client = ApiRequestor::httpClient();
    expect($client)->toBeInstanceOf(CurlClient::class)
        ->and($client->getConnectTimeout())->toBe(3)
        ->and($client->getTimeout())->toBe(11);
});

it('sets max_network_retries on the Stripe client from config', function (): void {
    config(['services.stripe.max_network_retries' => 3]);

    $gateway = new StripePaymentGateway();

    $property = new ReflectionProperty(StripePaymentGateway::class, 'stripe');
    $property->setAccessible(true);
    $stripeClient = $property->getValue($gateway);

    expect($stripeClient->getMaxNetworkRetries())->toBe(3);
});
