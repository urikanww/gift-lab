<?php

declare(strict_types=1);

use App\Exceptions\CourierException;
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
        ->and($job->consignment_ref)->toBe(NinjaVanTrackingNumber::forQuote($quote->id));
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
