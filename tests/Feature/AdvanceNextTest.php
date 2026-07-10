<?php

declare(strict_types=1);

use App\Enums\JobState;
use App\Models\Company;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Proof;
use App\Models\Quote;
use App\Models\User;
use App\Services\QueueService;
use Laravel\Sanctum\Sanctum;

function scanReadyJob(): App\Models\ProductionJob
{
    $company = Company::factory()->create();
    $product = Product::factory()->create(['class' => 'CORE', 'print_method' => 'UV']);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'state' => 'PROCURING']);
    Proof::factory()->approved()->create(['quote_id' => $quote->id]);
    LineItem::factory()->ready()->create(['quote_id' => $quote->id, 'product_id' => $product->id, 'qty' => 2]);

    return app(QueueService::class)->buildJobsForQuote($quote->load('lineItems.product'))->first();
}

it('advances a READY job to its next state on scan', function (): void {
    $job = scanReadyJob();

    Sanctum::actingAs(User::factory()->staffAdmin()->create());
    $this->postJson("/api/production-jobs/{$job->id}/advance-next")
        ->assertOk()
        ->assertJsonPath('data.state', 'IN_PRODUCTION');
});

it('refuses to scan-advance into SHIPPED (needs the ref dialog)', function (): void {
    $job = scanReadyJob();
    app(QueueService::class)->advance($job, JobState::InProduction);

    Sanctum::actingAs(User::factory()->staffAdmin()->create());
    $this->postJson("/api/production-jobs/{$job->id}/advance-next")
        ->assertStatus(422)
        ->assertJson(['message' => 'Marking a job shipped needs a consignment reference. Use the ship action.']);

    expect($job->fresh()->state->value)->toBe('IN_PRODUCTION');
});

it('closes a shipped job on scan', function (): void {
    $job = scanReadyJob();
    app(QueueService::class)->advance($job, JobState::InProduction);
    app(QueueService::class)->advance($job->fresh(), JobState::Shipped, 'REF9');

    Sanctum::actingAs(User::factory()->staffAdmin()->create());
    $this->postJson("/api/production-jobs/{$job->id}/advance-next")
        ->assertOk()
        ->assertJsonPath('data.state', 'CLOSED');
});
