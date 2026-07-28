<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\PricingConfig;
use App\Models\User;
use App\Services\Courier\CourierShipment;
use App\Services\Courier\HttpNinjaVanClient;
use App\Support\CourierConfig;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    Cache::flush();
    // Env fallback the resolver falls back to when a stored field is blank.
    config()->set('services.ninjavan.pickup', [
        'name' => 'Env Warehouse', 'phone' => '+6500000000', 'email' => 'env@giftlab.test',
        'address1' => 'Env Address', 'city' => 'Envcity', 'state' => 'Envstate',
        'postcode' => '000000', 'country' => 'SG',
    ]);
    config()->set('services.ninjavan.timeslot_start', '08:00');
    config()->set('services.ninjavan.timeslot_end', '17:00');
    config()->set('services.ninjavan.timezone', 'Asia/Singapore');
});

it('resolves stored pickup over the env fallback, keeping env for blank fields', function (): void {
    PricingConfig::create(['group' => 'courier', 'key' => 'pickup', 'value' => [
        'name' => 'Stored HQ', 'address1' => '10 Stored Way', 'postcode' => '123456',
        // phone left blank on purpose -> should fall back to env.
        'phone' => '', 'country' => 'sg',
    ]]);

    $pickup = CourierConfig::pickup();
    expect($pickup['name'])->toBe('Stored HQ')
        ->and($pickup['address1'])->toBe('10 Stored Way')
        ->and($pickup['postcode'])->toBe('123456')
        ->and($pickup['phone'])->toBe('+6500000000'); // env fallback for the blank field
});

it('resolves stored timeslot over the env fallback', function (): void {
    PricingConfig::create(['group' => 'courier', 'key' => 'timeslot', 'value' => [
        'start' => '10:00', 'end' => '16:00', 'timezone' => 'Asia/Singapore',
    ]]);

    expect(CourierConfig::timeslot())->toMatchArray(['start' => '10:00', 'end' => '16:00']);
});

it('sends the stored pickup address + collection window to NinjaVan, not the env values', function (): void {
    PricingConfig::create(['group' => 'courier', 'key' => 'pickup', 'value' => [
        'name' => 'Stored HQ', 'phone' => '+6512345678', 'email' => 'hq@giftlab.test',
        'address1' => '10 Stored Way', 'city' => 'Singapore', 'state' => 'SG',
        'postcode' => '654321', 'country' => 'SG',
    ]]);
    PricingConfig::create(['group' => 'courier', 'key' => 'timeslot', 'value' => [
        'start' => '11:00', 'end' => '15:00', 'timezone' => 'Asia/Singapore',
    ]]);

    Http::fake([
        '*/2.0/oauth/access_token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        '*/4.1/orders' => Http::response(['requested_tracking_number' => 'GL1AB']),
    ]);
    config()->set('services.ninjavan.client_id', 'id');
    config()->set('services.ninjavan.client_secret', 'secret');
    config()->set('services.ninjavan.base_url', 'https://api-sandbox.ninjavan.co/sg');

    app(HttpNinjaVanClient::class)->createShipment(new CourierShipment(
        reference: 'GL-1', recipientName: 'Rachel', phone: '+6591234567', email: null,
        line1: '1 Marina Blvd', line2: null, city: 'Singapore', state: null,
        postalCode: '018989', country: 'SG', notes: null, parcelCount: 1,
        requestedTrackingNumber: 'GL1AB', deliveryStartDate: '2026-07-20',
    ));

    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/4.1/orders')
        && data_get($r->data(), 'from.name') === 'Stored HQ'
        && data_get($r->data(), 'from.address.postcode') === '654321'
        && data_get($r->data(), 'parcel_job.delivery_timeslot.start_time') === '11:00'
        && data_get($r->data(), 'parcel_job.delivery_timeslot.end_time') === '15:00');
});

it('lets staff read and update the pickup config, and audits it', function (): void {
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->getJson('/api/admin/courier-config')
        ->assertOk()
        ->assertJsonPath('pickup.name', 'Env Warehouse'); // effective = env until saved

    $this->patchJson('/api/admin/courier-config', [
        'pickup' => [
            'name' => 'New Depot', 'phone' => '+6560001111', 'email' => 'depot@giftlab.test',
            'address1' => '5 New Road', 'city' => 'Singapore', 'state' => 'SG',
            'postcode' => '445566', 'country' => 'sg',
        ],
        'timeslot' => ['start' => '10:00', 'end' => '14:00', 'timezone' => 'Asia/Singapore'],
    ])->assertOk()->assertJsonPath('pickup.name', 'New Depot')->assertJsonPath('pickup.country', 'SG');

    expect(CourierConfig::pickup()['name'])->toBe('New Depot')
        ->and(CourierConfig::timeslot()['end'])->toBe('14:00')
        ->and(\App\Models\AuditLog::where('event', 'courier_config.updated')->count())->toBeGreaterThan(0);
});

it('rejects an invalid time window (end before start) and a bad country code', function (): void {
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->patchJson('/api/admin/courier-config', [
        'pickup' => [
            'name' => 'X', 'phone' => '+65', 'address1' => 'X', 'postcode' => '1', 'country' => 'SGP',
        ],
        'timeslot' => ['start' => '18:00', 'end' => '09:00', 'timezone' => 'Asia/Singapore'],
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['timeslot.end', 'pickup.country']);
});

it('blocks a non-staff buyer from reading or editing the courier config', function (): void {
    $company = Company::factory()->create();
    Sanctum::actingAs(User::factory()->create(['company_id' => $company->id, 'role' => 'buyer']));

    $this->getJson('/api/admin/courier-config')->assertForbidden();
    $this->patchJson('/api/admin/courier-config', [])->assertForbidden();
});
