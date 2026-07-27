<?php

declare(strict_types=1);

use App\Exceptions\CourierException;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\ProductionJob;
use App\Models\Quote;
use App\Models\ShippingAddress;
use App\Models\User;
use App\Services\Courier\Contracts\CourierClient;
use App\Services\Courier\CourierShipment;
use App\Services\Courier\CourierShipmentResult;
use App\Services\Courier\NinjaVanTrackingNumber;
use Laravel\Sanctum\Sanctum;

it('creates a NinjaVan shipment and marks the job shipped', function (): void {
    $quote = Quote::factory()->create();
    ShippingAddress::create([
        'quote_id' => $quote->id, 'recipient_name' => 'Rachel Tan',
        'phone' => '+6591234567', 'line1' => '1 Marina Blvd',
        'postal_code' => '018989', 'country' => 'SG',
    ]);
    $job = ProductionJob::factory()->create(['quote_id' => $quote->id, 'state' => 'IN_PRODUCTION']);

    Sanctum::actingAs(User::factory()->staffAdmin()->create());
    $this->postJson("/api/production-jobs/{$job->id}/create-shipment")
        ->assertOk();

    $job->refresh();
    expect($job->state->value)->toBe('SHIPPED')
        ->and($job->carrier->value)->toBe('NINJAVAN')
        ->and($job->consignment_ref)->not->toBeNull()
        ->and($job->consignment_ref)->toBe(NinjaVanTrackingNumber::forJob($quote->id, $job->id));
});

it('refuses to re-ship a job that already has a consignment', function (): void {
    // A courier spy that blows up if reached: the 422 must come from the
    // idempotency guard, proving the (billable) courier was never invoked.
    app()->bind(CourierClient::class, fn () => new class implements CourierClient
    {
        public function createShipment(CourierShipment $s): CourierShipmentResult
        {
            throw new \RuntimeException('courier must not be called when the job already has a consignment');
        }
    });

    $quote = Quote::factory()->create();
    ShippingAddress::create([
        'quote_id' => $quote->id, 'recipient_name' => 'Rachel Tan',
        'phone' => '+6591234567', 'line1' => '1 Marina Blvd',
        'postal_code' => '018989', 'country' => 'SG',
    ]);
    $job = ProductionJob::factory()->create(['quote_id' => $quote->id, 'state' => 'IN_PRODUCTION']);
    $job->update(['consignment_ref' => 'GLEXISTING']);
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->postJson("/api/production-jobs/{$job->id}/create-shipment")
        ->assertStatus(422);

    // The idempotency guard fires before the courier call: ref unchanged.
    expect($job->fresh()->consignment_ref)->toBe('GLEXISTING');
});

it('refuses to ship without a shipping address', function (): void {
    $quote = Quote::factory()->create();
    $job = ProductionJob::factory()->create(['quote_id' => $quote->id, 'state' => 'IN_PRODUCTION']);
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->postJson("/api/production-jobs/{$job->id}/create-shipment")
        ->assertStatus(422);
});

it('forbids a buyer from creating a shipment', function (): void {
    $quote = Quote::factory()->create();
    $job = ProductionJob::factory()->create(['quote_id' => $quote->id, 'state' => 'IN_PRODUCTION']);
    Sanctum::actingAs(User::factory()->create());

    $this->postJson("/api/production-jobs/{$job->id}/create-shipment")
        ->assertStatus(403);
});

it('refuses to ship a job that cannot reach SHIPPED from its current state', function (): void {
    $quote = Quote::factory()->create();
    ShippingAddress::create([
        'quote_id' => $quote->id, 'recipient_name' => 'Rachel Tan',
        'phone' => '+6591234567', 'line1' => '1 Marina Blvd',
        'postal_code' => '018989', 'country' => 'SG',
    ]);
    $job = ProductionJob::factory()->create(['quote_id' => $quote->id, 'state' => 'READY']);
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->postJson("/api/production-jobs/{$job->id}/create-shipment")
        ->assertStatus(422);

    // The guard fires before the courier call: no transition, no consignment.
    expect($job->fresh()->state->value)->toBe('READY');
});

it('persists the label_url returned by the courier', function (): void {
    app()->bind(CourierClient::class, fn () => new class implements CourierClient
    {
        public function createShipment(CourierShipment $shipment): CourierShipmentResult
        {
            return new CourierShipmentResult(
                trackingRef: $shipment->requestedTrackingNumber,
                carrier: 'NINJAVAN',
                labelUrl: 'https://ninjavan.test/labels/'.$shipment->requestedTrackingNumber.'.pdf',
            );
        }
    });

    $quote = Quote::factory()->create();
    ShippingAddress::create([
        'quote_id' => $quote->id, 'recipient_name' => 'Rachel Tan',
        'phone' => '+6591234567', 'line1' => '1 Marina Blvd',
        'postal_code' => '018989', 'country' => 'SG',
    ]);
    $job = ProductionJob::factory()->create(['quote_id' => $quote->id, 'state' => 'IN_PRODUCTION']);
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->postJson("/api/production-jobs/{$job->id}/create-shipment")
        ->assertOk()
        ->assertJsonPath('data.label_url', 'https://ninjavan.test/labels/'.NinjaVanTrackingNumber::forJob($quote->id, $job->id).'.pdf');

    expect($job->fresh()->label_url)
        ->toBe('https://ninjavan.test/labels/'.NinjaVanTrackingNumber::forJob($quote->id, $job->id).'.pdf');
});

it('computes and sends the real parcel weight from the job line items', function (): void {
    $captured = null;
    app()->bind(CourierClient::class, function () use (&$captured) {
        return new class($captured) implements CourierClient
        {
            public function __construct(private mixed &$captured) {}

            public function createShipment(CourierShipment $shipment): CourierShipmentResult
            {
                $this->captured = $shipment;

                return new CourierShipmentResult($shipment->requestedTrackingNumber, 'NINJAVAN', null);
            }
        };
    });

    $quote = Quote::factory()->create();
    ShippingAddress::create([
        'quote_id' => $quote->id, 'recipient_name' => 'Rachel Tan',
        'phone' => '+6591234567', 'line1' => '1 Marina Blvd',
        'postal_code' => '018989', 'country' => 'SG',
    ]);
    $job = ProductionJob::factory()->create(['quote_id' => $quote->id, 'state' => 'IN_PRODUCTION']);

    // 250g x 2 + 100g x 3 = 800g = 0.8kg.
    $productA = Product::factory()->create(['weight' => 250]);
    $productB = Product::factory()->create(['weight' => 100]);
    LineItem::factory()->create(['quote_id' => $quote->id, 'job_id' => $job->id, 'product_id' => $productA->id, 'qty' => 2]);
    LineItem::factory()->create(['quote_id' => $quote->id, 'job_id' => $job->id, 'product_id' => $productB->id, 'qty' => 3]);

    Sanctum::actingAs(User::factory()->staffAdmin()->create());
    $this->postJson("/api/production-jobs/{$job->id}/create-shipment")->assertOk();

    expect($captured)->not->toBeNull()
        ->and($captured->weightKg)->toBe(0.8);
});

it('falls back to the config default weight when a line item product has no recorded weight', function (): void {
    $captured = null;
    app()->bind(CourierClient::class, function () use (&$captured) {
        return new class($captured) implements CourierClient
        {
            public function __construct(private mixed &$captured) {}

            public function createShipment(CourierShipment $shipment): CourierShipmentResult
            {
                $this->captured = $shipment;

                return new CourierShipmentResult($shipment->requestedTrackingNumber, 'NINJAVAN', null);
            }
        };
    });

    config()->set('services.ninjavan.default_weight_kg', 1.5);

    $quote = Quote::factory()->create();
    ShippingAddress::create([
        'quote_id' => $quote->id, 'recipient_name' => 'Rachel Tan',
        'phone' => '+6591234567', 'line1' => '1 Marina Blvd',
        'postal_code' => '018989', 'country' => 'SG',
    ]);
    $job = ProductionJob::factory()->create(['quote_id' => $quote->id, 'state' => 'IN_PRODUCTION']);

    $productNoWeight = Product::factory()->create(['weight' => null]);
    LineItem::factory()->create(['quote_id' => $quote->id, 'job_id' => $job->id, 'product_id' => $productNoWeight->id, 'qty' => 2]);

    Sanctum::actingAs(User::factory()->staffAdmin()->create());
    $this->postJson("/api/production-jobs/{$job->id}/create-shipment")->assertOk();

    expect($captured)->not->toBeNull()
        ->and($captured->weightKg)->toBe(1.5);
});

it('rejects a shipment with a blank required ship-to field with a 422, not a 502', function (): void {
    // The courier must never be reached for an incomplete address - the guard
    // has to fire first, so a spy that blows up if called proves the 422 came
    // from validation, not from NinjaVan rejecting the request.
    app()->bind(CourierClient::class, fn () => new class implements CourierClient
    {
        public function createShipment(CourierShipment $s): CourierShipmentResult
        {
            throw new \RuntimeException('courier must not be called for an incomplete ship-to address');
        }
    });

    $quote = Quote::factory()->create();
    ShippingAddress::create([
        'quote_id' => $quote->id, 'recipient_name' => '',
        'phone' => '+6591234567', 'line1' => '1 Marina Blvd',
        'postal_code' => '018989', 'country' => 'SG',
    ]);
    $job = ProductionJob::factory()->create(['quote_id' => $quote->id, 'state' => 'IN_PRODUCTION']);
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->postJson("/api/production-jobs/{$job->id}/create-shipment")
        ->assertStatus(422);

    expect($job->fresh()->consignment_ref)->toBeNull();
});

it('clamps a past delivery_start_date up to today', function (): void {
    $captured = null;
    app()->bind(CourierClient::class, function () use (&$captured) {
        return new class($captured) implements CourierClient
        {
            public function __construct(private mixed &$captured) {}

            public function createShipment(CourierShipment $shipment): CourierShipmentResult
            {
                $this->captured = $shipment;

                return new CourierShipmentResult($shipment->requestedTrackingNumber, 'NINJAVAN', null);
            }
        };
    });

    $quote = Quote::factory()->create(['needed_by' => now()->subDays(5)]);
    ShippingAddress::create([
        'quote_id' => $quote->id, 'recipient_name' => 'Rachel Tan',
        'phone' => '+6591234567', 'line1' => '1 Marina Blvd',
        'postal_code' => '018989', 'country' => 'SG',
    ]);
    $job = ProductionJob::factory()->create(['quote_id' => $quote->id, 'state' => 'IN_PRODUCTION']);
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->postJson("/api/production-jobs/{$job->id}/create-shipment")->assertOk();

    expect($captured)->not->toBeNull()
        ->and($captured->deliveryStartDate)->toBe(now()->startOfDay()->toDateString());
});

it('returns 502 when the courier fails', function (): void {
    app()->bind(CourierClient::class, fn () => new class implements CourierClient
    {
        public function createShipment(CourierShipment $shipment): CourierShipmentResult
        {
            throw new CourierException('boom');
        }
    });
    $quote = Quote::factory()->create();
    ShippingAddress::create(['quote_id' => $quote->id, 'recipient_name' => 'R', 'phone' => '1', 'line1' => 'X', 'postal_code' => '1', 'country' => 'SG']);
    $job = ProductionJob::factory()->create(['quote_id' => $quote->id, 'state' => 'IN_PRODUCTION']);
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->postJson("/api/production-jobs/{$job->id}/create-shipment")->assertStatus(502);
    expect($job->fresh()->state->value)->toBe('IN_PRODUCTION');
});
