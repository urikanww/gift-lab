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

function batchReadyUvJob(): App\Models\ProductionJob
{
    $company = Company::factory()->create();
    $product = Product::factory()->create(['class' => 'CORE', 'print_method' => 'UV']);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'state' => 'PROCURING']);
    Proof::factory()->approved()->create(['quote_id' => $quote->id]);
    LineItem::factory()->ready()->create(['quote_id' => $quote->id, 'product_id' => $product->id, 'qty' => 2]);

    return app(QueueService::class)->buildJobsForQuote($quote->load('lineItems.product'))->first();
}

it('starts several ready jobs in one batch and reports skips', function (): void {
    $a = batchReadyUvJob();
    $b = batchReadyUvJob();
    // c is already shipped -> cannot go to IN_PRODUCTION -> skipped.
    $c = batchReadyUvJob();
    app(QueueService::class)->advance($c, JobState::InProduction);
    app(QueueService::class)->advance($c->fresh(), JobState::Shipped, 'REF');

    Sanctum::actingAs(User::factory()->staffAdmin()->create());
    $res = $this->postJson('/api/production-jobs/advance-batch', [
        'job_ids' => [$a->id, $b->id, $c->id],
        'state' => 'IN_PRODUCTION',
    ])->assertOk();

    expect($res->json('advanced'))->toEqualCanonicalizing([$a->id, $b->id])
        ->and($res->json('skipped'))->toBe([$c->id])
        ->and($a->fresh()->state->value)->toBe('IN_PRODUCTION');
});

it('rejects SHIPPED as a batch target (needs a per-parcel ref)', function (): void {
    $a = batchReadyUvJob();

    Sanctum::actingAs(User::factory()->staffAdmin()->create());
    $this->postJson('/api/production-jobs/advance-batch', [
        'job_ids' => [$a->id],
        'state' => 'SHIPPED',
    ])->assertStatus(422)->assertJsonValidationErrors(['state']);
});

it('forbids a buyer from batch-advancing', function (): void {
    $a = batchReadyUvJob();
    $buyer = User::factory()->create(['role' => 'buyer']);

    Sanctum::actingAs($buyer);
    $this->postJson('/api/production-jobs/advance-batch', [
        'job_ids' => [$a->id],
        'state' => 'IN_PRODUCTION',
    ])->assertForbidden();
});
