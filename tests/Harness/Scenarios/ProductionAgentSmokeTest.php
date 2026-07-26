<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Proof;
use App\Models\Quote;
use App\Models\User;
use App\Services\QueueService;
use Tests\Harness\Agents\ProductionAgent;
use Tests\Harness\Support\HarnessContext;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->buyer = User::factory()->create(['company_id' => $this->company->id, 'role' => 'buyer']);
    $this->staff = User::factory()->staffAdmin()->create();
    $this->product = Product::factory()->create(['class' => 'CORE', 'print_method' => 'UV']);
    $this->ctx = new HarnessContext($this, $this->staff, $this->buyer);
});

it('ProductionAgent starts a queued job into production', function (): void {
    $quote = Quote::factory()->create(['company_id' => $this->company->id, 'state' => 'PROCURING']);
    $line = LineItem::factory()->ready()->create([
        'quote_id' => $quote->id,
        'product_id' => $this->product->id,
        'qty' => 10,
        'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/core.png'],
    ]);
    Proof::factory()->forLine($line)->approved()->create();

    $job = app(QueueService::class)->buildJobsForQuote($quote->load('lineItems.product'))->first();
    $this->ctx->quote = $quote->fresh();

    (new ProductionAgent($this->ctx))->startJob($job);

    expect($job->fresh()->state->value)->toBe('IN_PRODUCTION');
});
