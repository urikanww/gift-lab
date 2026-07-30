<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\ProductionJob;
use App\Models\Quote;
use App\Models\Shipment;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

// LT13: after a parcel ships, staff must be able to see the shipment and confirm
// delivery from the order page - the resource now carries a staff-only
// `shipments` view of the order's production jobs.

it('exposes shipments with carrier and consignment to staff on the order page', function (): void {
    $company = Company::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $company->id, 'state' => 'READY']);
    $job = ProductionJob::factory()->create([
        'quote_id' => $quote->id,
        'state' => 'SHIPPED',
    ]);
    $shipment = Shipment::factory()->create([
        'quote_id' => $quote->id,
        'carrier' => 'SINGPOST',
        'consignment_ref' => 'SP123456789SG',
    ]);
    $job->shipment()->associate($shipment)->save();

    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->getJson("/api/quotes/{$quote->reference}")
        ->assertOk()
        ->assertJsonPath('data.shipments.0.state', 'SHIPPED')
        ->assertJsonPath('data.shipments.0.carrier', 'SINGPOST')
        ->assertJsonPath('data.shipments.0.consignment_ref', 'SP123456789SG');
});

it('does not expose shipments to the buyer', function (): void {
    $company = Company::factory()->create();
    $buyer = User::factory()->create(['company_id' => $company->id, 'role' => 'buyer']);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'state' => 'READY']);
    $job = ProductionJob::factory()->create([
        'quote_id' => $quote->id,
        'state' => 'SHIPPED',
    ]);
    $shipment = Shipment::factory()->create([
        'quote_id' => $quote->id,
        'consignment_ref' => 'SP123456789SG',
    ]);
    $job->shipment()->associate($shipment)->save();

    Sanctum::actingAs($buyer);

    $this->getJson("/api/quotes/{$quote->reference}")
        ->assertOk()
        ->assertJsonMissingPath('data.shipments');
});
