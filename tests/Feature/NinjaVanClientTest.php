<?php

declare(strict_types=1);

use App\Services\Courier\Contracts\CourierClient;
use App\Services\Courier\CourierShipment;
use App\Services\Courier\FixtureNinjaVanClient;
use App\Services\Courier\HttpNinjaVanClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Cache::flush();
    // Pin "now" before the fixture deliveryStartDate (2026-07-20, a Monday) so
    // CourierConfig::resolveDispatchDate leaves it unchanged - the dispatch date
    // (pickup_date + delivery_start_date) is a future, non-weekend, non-blackout
    // day, so these payload assertions stay deterministic.
    \Illuminate\Support\Carbon::setTestNow('2026-07-01');
});

afterEach(function (): void {
    // Restore the "testing" env for every other test in the suite - tests
    // below flip it to simulate a real deploy environment.
    app()->instance('env', 'testing');
    \Illuminate\Support\Carbon::setTestNow();
});

it('fails closed resolving the courier client when creds are set but base_url is still the sandbox host outside local/testing', function (): void {
    config()->set('services.ninjavan.client_id', 'id');
    config()->set('services.ninjavan.client_secret', 'secret');
    config()->set('services.ninjavan.base_url', 'https://api-sandbox.ninjavan.co/sg');
    app()->instance('env', 'production');

    expect(fn () => app(CourierClient::class))->toThrow(RuntimeException::class);
});

it('resolves the live NinjaVan client when creds are set and base_url is a production host outside local/testing', function (): void {
    config()->set('services.ninjavan.client_id', 'id');
    config()->set('services.ninjavan.client_secret', 'secret');
    config()->set('services.ninjavan.base_url', 'https://api.ninjavan.co/sg');
    app()->instance('env', 'production');

    expect(app(CourierClient::class))->toBeInstanceOf(HttpNinjaVanClient::class);
});

it('still uses the fixture in local/testing when creds are absent, regardless of the sandbox guard', function (): void {
    config()->set('services.ninjavan.client_id', null);
    config()->set('services.ninjavan.client_secret', null);
    config()->set('services.ninjavan.base_url', 'https://api-sandbox.ninjavan.co/sg');

    expect(app(CourierClient::class))->toBeInstanceOf(FixtureNinjaVanClient::class);
});

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

it('sends the pickup_* parcel_job fields NinjaVan v4.1 requires when pickup is enabled', function (): void {
    Http::fake([
        '*/2.0/oauth/access_token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        '*/4.1/orders' => Http::response(['requested_tracking_number' => 'GL1AB']),
    ]);

    config()->set('services.ninjavan.client_id', 'id');
    config()->set('services.ninjavan.client_secret', 'secret');
    config()->set('services.ninjavan.base_url', 'https://api-sandbox.ninjavan.co/sg');
    config()->set('services.ninjavan.pickup', ['name' => 'Gift Lab', 'phone' => '+6560000000', 'email' => 'ops@giftlab.test', 'address1' => '1 Depot Rd', 'postcode' => '109679', 'country' => 'SG']);
    config()->set('services.ninjavan.pickup_service_type', 'Parcel');
    config()->set('services.ninjavan.pickup_service_level', 'Standard');
    config()->set('services.ninjavan.pickup_date', null);
    // The pickup + delivery WINDOW now comes from the courier config
    // (CourierConfig::timeslot), which falls back to these delivery-timeslot
    // config keys when no "courier" row is stored - so both legs share it.
    config()->set('services.ninjavan.timeslot_start', '15:00');
    config()->set('services.ninjavan.timeslot_end', '18:00');
    config()->set('services.ninjavan.timezone', 'Asia/Singapore');

    $client = app(HttpNinjaVanClient::class);
    $client->createShipment(new CourierShipment(
        reference: 'GL-2041', recipientName: 'Rachel Tan', phone: '+6591234567', email: null,
        line1: '1 Marina Blvd', line2: null, city: 'Singapore', state: null,
        postalCode: '018989', country: 'SG', notes: null, parcelCount: 1,
        requestedTrackingNumber: 'GL1AB', deliveryStartDate: '2026-07-20',
    ));

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/4.1/orders')
        && data_get($request->data(), 'parcel_job.is_pickup_required') === true
        && data_get($request->data(), 'parcel_job.pickup_service_type') === 'Parcel'
        && data_get($request->data(), 'parcel_job.pickup_service_level') === 'Standard'
        // pickup_date falls back to the delivery_start_date when unconfigured.
        && data_get($request->data(), 'parcel_job.pickup_date') === '2026-07-20'
        // Pickup window mirrors the configured collection window (both legs).
        && data_get($request->data(), 'parcel_job.pickup_timeslot.start_time') === '15:00'
        && data_get($request->data(), 'parcel_job.pickup_timeslot.end_time') === '18:00'
        && data_get($request->data(), 'parcel_job.pickup_timeslot.timezone') === 'Asia/Singapore');
});

it('uses the configured service type/level and one dispatch day (weekday-snapped) for both legs', function (): void {
    Http::fake([
        '*/2.0/oauth/access_token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        '*/4.1/orders' => Http::response(['requested_tracking_number' => 'GL1AB']),
    ]);

    config()->set('services.ninjavan.client_id', 'id');
    config()->set('services.ninjavan.client_secret', 'secret');
    config()->set('services.ninjavan.base_url', 'https://api-sandbox.ninjavan.co/sg');
    config()->set('services.ninjavan.pickup', ['name' => 'Gift Lab', 'phone' => '+6560000000', 'email' => 'ops@giftlab.test', 'address1' => '1 Depot Rd', 'postcode' => '109679', 'country' => 'SG']);
    config()->set('services.ninjavan.pickup_service_type', 'Marketplace');
    config()->set('services.ninjavan.pickup_service_level', 'Express');
    // Preferred collection weekday: Wednesday. now() is pinned to 2026-07-01.
    App\Models\PricingConfig::create(['group' => 'courier', 'key' => 'schedule', 'value' => [
        'weekday' => 'wednesday', 'blackout_dates' => [],
    ]]);

    $client = app(HttpNinjaVanClient::class);
    $client->createShipment(new CourierShipment(
        reference: 'GL-2041', recipientName: 'Rachel Tan', phone: '+6591234567', email: null,
        line1: '1 Marina Blvd', line2: null, city: 'Singapore', state: null,
        postalCode: '018989', country: 'SG', notes: null, parcelCount: 1,
        requestedTrackingNumber: 'GL1AB', deliveryStartDate: '2026-07-20', // Monday
    ));

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/4.1/orders')
        && data_get($request->data(), 'parcel_job.pickup_service_type') === 'Marketplace'
        && data_get($request->data(), 'parcel_job.pickup_service_level') === 'Express'
        // Both legs snap to the next preferred weekday (Mon 20th -> Wed 22nd).
        && data_get($request->data(), 'parcel_job.pickup_date') === '2026-07-22'
        && data_get($request->data(), 'parcel_job.delivery_start_date') === '2026-07-22');
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

it('persists NinjaVan\'s own tracking_number from the response, not the requested one, when they differ', function (): void {
    // NinjaVan prepends/rewrites the merchant-supplied requested_tracking_number
    // into its own account-prefixed value - the response's tracking_number is
    // the ONLY value the inbound webhook will ever correlate on, so it must win
    // over what we sent whenever the two differ.
    Http::fake([
        '*/2.0/oauth/access_token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        '*/4.1/orders' => Http::response(['tracking_number' => 'NVSGGL1AB9', 'requested_tracking_number' => 'GL1AB']),
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

    expect($result->trackingRef)->toBe('NVSGGL1AB9')
        ->and($result->trackingRef)->not->toBe('GL1AB');
});

it('falls back to the requested tracking number when the response omits tracking_number', function (): void {
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

    expect($result->trackingRef)->toBe('GL1AB');
});

it('persists the label_url from the order response', function (): void {
    Http::fake([
        '*/2.0/oauth/access_token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        '*/4.1/orders' => Http::response(['tracking_number' => 'GL1AB', 'label_url' => 'https://ninjavan.test/labels/GL1AB.pdf']),
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

    expect($result->labelUrl)->toBe('https://ninjavan.test/labels/GL1AB.pdf');
});

it('sends the shipment weightKg when supplied, instead of the config default', function (): void {
    Http::fake([
        '*/2.0/oauth/access_token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        '*/4.1/orders' => Http::response(['tracking_number' => 'GL1AB']),
    ]);

    config()->set('services.ninjavan.client_id', 'id');
    config()->set('services.ninjavan.client_secret', 'secret');
    config()->set('services.ninjavan.base_url', 'https://api-sandbox.ninjavan.co/sg');
    config()->set('services.ninjavan.default_weight_kg', 1);
    config()->set('services.ninjavan.pickup', ['name' => 'Gift Lab', 'phone' => '+6560000000', 'email' => 'ops@giftlab.test', 'address1' => '1 Depot Rd', 'postcode' => '109679', 'country' => 'SG']);

    $client = app(HttpNinjaVanClient::class);
    $client->createShipment(new CourierShipment(
        reference: 'GL-2041', recipientName: 'Rachel Tan', phone: '+6591234567', email: null,
        line1: '1 Marina Blvd', line2: null, city: 'Singapore', state: null,
        postalCode: '018989', country: 'SG', notes: null, parcelCount: 1,
        requestedTrackingNumber: 'GL1AB', deliveryStartDate: '2026-07-20',
        weightKg: 2.75,
    ));

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/4.1/orders')
        && data_get($request->data(), 'parcel_job.dimensions.weight') === 2.75);
});

it('falls back to the config default weight when the shipment has no weightKg', function (): void {
    Http::fake([
        '*/2.0/oauth/access_token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        '*/4.1/orders' => Http::response(['tracking_number' => 'GL1AB']),
    ]);

    config()->set('services.ninjavan.client_id', 'id');
    config()->set('services.ninjavan.client_secret', 'secret');
    config()->set('services.ninjavan.base_url', 'https://api-sandbox.ninjavan.co/sg');
    config()->set('services.ninjavan.default_weight_kg', 3);
    config()->set('services.ninjavan.pickup', ['name' => 'Gift Lab', 'phone' => '+6560000000', 'email' => 'ops@giftlab.test', 'address1' => '1 Depot Rd', 'postcode' => '109679', 'country' => 'SG']);

    $client = app(HttpNinjaVanClient::class);
    $client->createShipment(new CourierShipment(
        reference: 'GL-2041', recipientName: 'Rachel Tan', phone: '+6591234567', email: null,
        line1: '1 Marina Blvd', line2: null, city: 'Singapore', state: null,
        postalCode: '018989', country: 'SG', notes: null, parcelCount: 1,
        requestedTrackingNumber: 'GL1AB', deliveryStartDate: '2026-07-20',
    ));

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/4.1/orders')
        && data_get($request->data(), 'parcel_job.dimensions.weight') === 3.0);
});

it('recovers on retry when NinjaVan reports the requested tracking number already exists, instead of throwing', function (): void {
    // Simulates: a prior attempt booked successfully with NinjaVan but our local
    // DB write failed afterward, leaving the job un-shipped. A retry re-sends
    // the SAME deterministic requested_tracking_number; NinjaVan now rejects it
    // as a duplicate. That must be treated as "already booked", not a failure,
    // so the retry can complete the SHIPPED transition instead of piling up 502s.
    Http::fake([
        '*/2.0/oauth/access_token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        '*/4.1/orders' => Http::response(['message' => 'Duplicate requested_tracking_number'], 400),
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

    expect($result->trackingRef)->toBe('GL1AB')
        ->and($result->carrier)->toBe('NINJAVAN');
});

it('still throws a CourierException on a 400 that is not a duplicate-order response', function (): void {
    Http::fake([
        '*/2.0/oauth/access_token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        '*/4.1/orders' => Http::response(['message' => 'Invalid postcode'], 400),
    ]);

    config()->set('services.ninjavan.client_id', 'id');
    config()->set('services.ninjavan.client_secret', 'secret');
    config()->set('services.ninjavan.base_url', 'https://api-sandbox.ninjavan.co/sg');
    config()->set('services.ninjavan.pickup', ['name' => 'Gift Lab', 'phone' => '+6560000000', 'email' => 'ops@giftlab.test', 'address1' => '1 Depot Rd', 'postcode' => '109679', 'country' => 'SG']);

    $client = app(HttpNinjaVanClient::class);
    expect(fn () => $client->createShipment(new CourierShipment(
        reference: 'GL-2041', recipientName: 'Rachel Tan', phone: '+6591234567', email: null,
        line1: '1 Marina Blvd', line2: null, city: 'Singapore', state: null,
        postalCode: '018989', country: 'SG', notes: null, parcelCount: 1,
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
