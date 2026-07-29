<?php

declare(strict_types=1);

use App\Enums\Carrier;
use App\Enums\JobState;
use App\Enums\OrderMilestone;
use App\Mail\OrderMilestoneMail;
use App\Models\Company;
use App\Models\ProductionJob;
use App\Models\Quote;
use App\Models\User;
use App\Services\QueueService;
use Illuminate\Support\Facades\Mail;

// M19: a parcel-split order builds several jobs. The buyer should get one "on
// its way" email for the order, not one per parcel.
it('sends the shipped email once per order, not per parcel', function (): void {
    Mail::fake();
    $company = Company::factory()->create();
    $buyer = User::factory()->create(['company_id' => $company->id, 'role' => 'buyer']);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'state' => 'READY', 'created_by' => $buyer->id]);
    $jobA = ProductionJob::factory()->create(['quote_id' => $quote->id, 'state' => 'IN_PRODUCTION']);
    $jobB = ProductionJob::factory()->create(['quote_id' => $quote->id, 'state' => 'IN_PRODUCTION']);

    $queue = app(QueueService::class);
    $queue->advance($jobA->fresh(), JobState::Shipped, 'SP-A', Carrier::from('SINGPOST'));
    $queue->advance($jobB->fresh(), JobState::Shipped, 'SP-B', Carrier::from('SINGPOST'));

    $shipped = Mail::queued(
        OrderMilestoneMail::class,
        fn (OrderMilestoneMail $m): bool => $m->milestone === OrderMilestone::Shipped,
    );
    expect($shipped)->toHaveCount(1);
});
