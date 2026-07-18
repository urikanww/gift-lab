<?php

declare(strict_types=1);

use App\Services\Courier\CourierShipment;
use App\Services\Courier\HttpNinjaVanClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => Cache::flush());

it('creates an order and returns the merchant tracking number', function (): void {
    Http::fake([
        '*/2.0/oauth/access_token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        '*/4.1/orders' => Http::response(['requested_tracking_number' => 'GL1AB']),
    ]);

    config()->set('services.ninjavan.client_id', 'id');
    config()->set('services.ninjavan.client_secret', 'secret');
    config()->set('services.ninjavan.base_url', 'https://api-sandbox.ninjavan.co/sg');
    config()->set('services.ninjavan.pickup', ['name' => 'Gift Lab', 'phone' => '+6560000000', 'email' => 'ops@giftlab.test', 'address1' => '1 Depot Rd', 'postcode' => '109679', 'country' => 'SG']);

    $client = app(HttpNinjaVanClient::class);
    $result = $client->createShipment(new CourierShipment(
        reference: 'GL-2041', recipientName: 'Rachel Tan', phone: '+6591234567', email: null,
        line1: '1 Marina Blvd', line2: null, city: 'Singapore', state: null,
        postalCode: '018989', country: 'SG', notes: null, parcelCount: 1,
        requestedTrackingNumber: 'GL1AB', deliveryStartDate: '2026-07-20',
    ));

    expect($result->trackingRef)->toBe('GL1AB')->and($result->carrier)->toBe('NINJAVAN');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/2.0/oauth/access_token')
        && data_get($request->data(), 'grant_type') === 'client_credentials');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/4.1/orders')
        && $request->hasHeader('Authorization', 'Bearer tok')
        && data_get($request->data(), 'requested_tracking_number') === 'GL1AB'
        && data_get($request->data(), 'reference.merchant_order_number') === 'GL-2041'
        && data_get($request->data(), 'parcel_job.delivery_start_date') === '2026-07-20'
        && data_get($request->data(), 'to.address.postcode') === '018989');
});

it('throws a CourierException when auth fails', function (): void {
    Http::fake([
        '*/2.0/oauth/access_token' => Http::response(['error' => 'invalid_client'], 401),
    ]);
    config()->set('services.ninjavan.client_id', 'id');
    config()->set('services.ninjavan.client_secret', 'bad');
    config()->set('services.ninjavan.base_url', 'https://api-sandbox.ninjavan.co/sg');
    config()->set('services.ninjavan.pickup', ['name' => 'Gift Lab', 'phone' => '+6560000000', 'email' => 'ops@giftlab.test', 'address1' => '1 Depot Rd', 'postcode' => '109679', 'country' => 'SG']);

    $client = app(HttpNinjaVanClient::class);
    expect(fn () => $client->createShipment(new CourierShipment(
        reference: 'GL-2041', recipientName: 'R', phone: '1', email: null,
        line1: 'X', line2: null, city: null, state: null,
        postalCode: '1', country: 'SG', notes: null, parcelCount: 1,
        requestedTrackingNumber: 'GL1AB', deliveryStartDate: '2026-07-20',
    )))->toThrow(\App\Exceptions\CourierException::class);
});

it('throws a CourierException when the order call fails', function (): void {
    Http::fake([
        '*/2.0/oauth/access_token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        '*/4.1/orders' => Http::response(['message' => 'bad request'], 400),
    ]);
    config()->set('services.ninjavan.client_id', 'id');
    config()->set('services.ninjavan.client_secret', 'secret');
    config()->set('services.ninjavan.base_url', 'https://api-sandbox.ninjavan.co/sg');
    config()->set('services.ninjavan.pickup', ['name' => 'Gift Lab', 'phone' => '+6560000000', 'email' => 'ops@giftlab.test', 'address1' => '1 Depot Rd', 'postcode' => '109679', 'country' => 'SG']);

    $client = app(HttpNinjaVanClient::class);
    expect(fn () => $client->createShipment(new CourierShipment(
        reference: 'GL-2041', recipientName: 'R', phone: '1', email: null,
        line1: 'X', line2: null, city: null, state: null,
        postalCode: '1', country: 'SG', notes: null, parcelCount: 1,
        requestedTrackingNumber: 'GL1AB', deliveryStartDate: '2026-07-20',
    )))->toThrow(\App\Exceptions\CourierException::class);
});

it('re-auths and retries once when a stale token yields a 401 on the order call', function (): void {
    $oauthHits = 0;
    $orderHits = 0;
    Http::fake([
        '*/2.0/oauth/access_token' => function () use (&$oauthHits) {
            $oauthHits++;

            return Http::response(['access_token' => 'tok'.$oauthHits, 'expires_in' => 3600]);
        },
        '*/4.1/orders' => function () use (&$orderHits) {
            $orderHits++;

            // First attempt: stale/rotated token => 401. Second: fresh token => 200.
            return $orderHits === 1
                ? Http::response(['error' => 'unauthorized'], 401)
                : Http::response(['requested_tracking_number' => 'GL1AB']);
        },
    ]);
    config()->set('services.ninjavan.client_id', 'id');
    config()->set('services.ninjavan.client_secret', 'secret');
    config()->set('services.ninjavan.base_url', 'https://api-sandbox.ninjavan.co/sg');
    config()->set('services.ninjavan.pickup', ['name' => 'Gift Lab', 'phone' => '+6560000000', 'email' => 'ops@giftlab.test', 'address1' => '1 Depot Rd', 'postcode' => '109679', 'country' => 'SG']);

    $client = app(HttpNinjaVanClient::class);
    $result = $client->createShipment(new CourierShipment(
        reference: 'GL-2041', recipientName: 'R', phone: '1', email: null,
        line1: 'X', line2: null, city: null, state: null,
        postalCode: '1', country: 'SG', notes: null, parcelCount: 1,
        requestedTrackingNumber: 'GL1AB', deliveryStartDate: '2026-07-20',
    ));

    expect($result->trackingRef)->toBe('GL1AB')
        ->and($oauthHits)->toBe(2)
        ->and($orderHits)->toBe(2);
});

it('throws a CourierException when the order call stays 401 after the re-auth retry', function (): void {
    Http::fake([
        '*/2.0/oauth/access_token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        '*/4.1/orders' => Http::response(['error' => 'unauthorized'], 401),
    ]);
    config()->set('services.ninjavan.client_id', 'id');
    config()->set('services.ninjavan.client_secret', 'secret');
    config()->set('services.ninjavan.base_url', 'https://api-sandbox.ninjavan.co/sg');
    config()->set('services.ninjavan.pickup', ['name' => 'Gift Lab', 'phone' => '+6560000000', 'email' => 'ops@giftlab.test', 'address1' => '1 Depot Rd', 'postcode' => '109679', 'country' => 'SG']);

    $client = app(HttpNinjaVanClient::class);
    expect(fn () => $client->createShipment(new CourierShipment(
        reference: 'GL-2041', recipientName: 'R', phone: '1', email: null,
        line1: 'X', line2: null, city: null, state: null,
        postalCode: '1', country: 'SG', notes: null, parcelCount: 1,
        requestedTrackingNumber: 'GL1AB', deliveryStartDate: '2026-07-20',
    )))->toThrow(\App\Exceptions\CourierException::class);
});

it('caches the OAuth token across shipments', function (): void {
    $oauthHits = 0;
    Http::fake([
        '*/2.0/oauth/access_token' => function () use (&$oauthHits) {
            $oauthHits++;

            return Http::response(['access_token' => 'tok', 'expires_in' => 3600]);
        },
        '*/4.1/orders' => Http::response(['requested_tracking_number' => 'GL1AB']),
    ]);
    config()->set('services.ninjavan.client_id', 'id');
    config()->set('services.ninjavan.client_secret', 'secret');
    config()->set('services.ninjavan.base_url', 'https://api-sandbox.ninjavan.co/sg');
    config()->set('services.ninjavan.pickup', ['name' => 'Gift Lab', 'phone' => '+6560000000', 'email' => 'ops@giftlab.test', 'address1' => '1 Depot Rd', 'postcode' => '109679', 'country' => 'SG']);

    $client = app(HttpNinjaVanClient::class);
    $mk = fn (string $tn) => new CourierShipment(
        reference: 'GL-2041', recipientName: 'R', phone: '1', email: null,
        line1: 'X', line2: null, city: null, state: null,
        postalCode: '1', country: 'SG', notes: null, parcelCount: 1,
        requestedTrackingNumber: $tn, deliveryStartDate: '2026-07-20',
    );
    $client->createShipment($mk('GL1AB'));
    $client->createShipment($mk('GL1AC'));

    expect($oauthHits)->toBe(1);
});
