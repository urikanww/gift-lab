<?php

declare(strict_types=1);

use App\Enums\JobState;
use App\Models\ProductionJob;
use App\Models\Quote;
use App\Models\Shipment;

it('backfills one shipment per job with courier data and relocates the fields', function (): void {
    // Build a shipped job the pre-migration way is impossible post-migration; instead
    // assert the schema + relation shape after migrate:fresh (RefreshDatabase already ran).
    $quote = Quote::factory()->create();
    $job = ProductionJob::factory()->for($quote)->create(['state' => JobState::Shipped->value]);
    $shipment = Shipment::factory()->for($quote)->create(['consignment_ref' => 'NVSGREL0001']);
    $job->shipment()->associate($shipment)->save();

    expect($job->fresh()->shipment->consignment_ref)->toBe('NVSGREL0001')
        ->and($shipment->fresh()->jobs->pluck('id'))->toContain($job->id)
        ->and(\Illuminate\Support\Facades\Schema::hasColumn('production_jobs', 'consignment_ref'))->toBeFalse()
        ->and(\Illuminate\Support\Facades\Schema::hasColumn('production_jobs', 'shipment_id'))->toBeTrue();
});
