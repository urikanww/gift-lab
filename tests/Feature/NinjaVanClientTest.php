<?php

declare(strict_types=1);

use App\Services\Courier\CourierShipment;
use App\Services\Courier\HttpNinjaVanClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('creates an order and returns the tracking number', function (): void {
    Http::fake([
        '*/2.0/oauth/access_token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        '*/4.2/orders' => Http::response(['tracking_number' => 'NVSGX123', 'requested_tracking_number' => 'NVSGX123']),
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
    ));

    expect($result->trackingRef)->toBe('NVSGX123')->and($result->carrier)->toBe('NINJAVAN');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/2.0/oauth/access_token')
        && data_get($request->data(), 'grant_type') === 'client_credentials');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/4.2/orders')
        && $request->hasHeader('Authorization', 'Bearer tok')
        && data_get($request->data(), 'reference.merchant_order_number') === 'GL-2041'
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
    )))->toThrow(\App\Exceptions\CourierException::class);
});

it('throws a CourierException when the order response carries no tracking number', function (): void {
    Http::fake([
        '*/2.0/oauth/access_token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        '*/4.2/orders' => Http::response([]),
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
    )))->toThrow(\App\Exceptions\CourierException::class);
});

it('throws a CourierException when the order call fails', function (): void {
    Http::fake([
        '*/2.0/oauth/access_token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        '*/4.2/orders' => Http::response(['message' => 'bad request'], 400),
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
    )))->toThrow(\App\Exceptions\CourierException::class);
});
