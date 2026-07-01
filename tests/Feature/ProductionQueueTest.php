<?php

declare(strict_types=1);

use App\Events\ProductionQueueUpdated;
use App\Models\Company;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Proof;
use App\Models\Quote;
use App\Models\User;
use App\Services\QueueService;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->staff = User::factory()->staffAdmin()->create();
    $this->queue = app(QueueService::class);
    $this->product = Product::factory()->create(['class' => 'CORE', 'print_method' => 'UV']);
});

function readyQuoteWithProof(): Quote
{
    $quote = Quote::factory()->create(['company_id' => test()->company->id, 'state' => 'PROCURING']);
    Proof::factory()->approved()->create(['quote_id' => $quote->id]);
    LineItem::factory()->ready()->create([
        'quote_id' => $quote->id,
        'product_id' => test()->product->id,
        'qty' => 10,
    ]);

    return $quote->load('lineItems.product');
}

it('refuses to queue a quote without an approved proof (gate 1)', function (): void {
    $quote = Quote::factory()->create(['company_id' => $this->company->id, 'state' => 'PROCURING']);
    LineItem::factory()->ready()->create(['quote_id' => $quote->id, 'product_id' => $this->product->id]);

    expect(fn () => $this->queue->buildJobsForQuote($quote->load('lineItems.product')))
        ->toThrow(RuntimeException::class);
});

it('builds a job per track, sets ready_at, and moves the quote to READY', function (): void {
    Event::fake([ProductionQueueUpdated::class]);
    $quote = readyQuoteWithProof();

    $jobs = $this->queue->buildJobsForQuote($quote);

    expect($jobs)->toHaveCount(1)
        ->and($jobs->first()->track->value)->toBe('UV')
        ->and($jobs->first()->ready_at)->not->toBeNull()
        ->and($quote->fresh()->state->value)->toBe('READY');
    Event::assertDispatched(ProductionQueueUpdated::class);
});

it('orders the shared queue FCFS by readiness', function (): void {
    $late = readyQuoteWithProof();
    $this->queue->buildJobsForQuote($late);

    $early = readyQuoteWithProof();
    // Force the second quote's job to be ready earlier.
    $earlyJobs = $this->queue->buildJobsForQuote($early);
    $earlyJobs->first()->update(['ready_at' => now()->subHour()]);

    $ordered = $this->queue->queue();
    expect($ordered->first()->id)->toBe($earlyJobs->first()->id);
});

it('restricts the queue endpoint to staff', function (): void {
    $buyer = User::factory()->create(['company_id' => $this->company->id, 'role' => 'buyer']);

    Sanctum::actingAs($buyer);
    $this->getJson('/api/production-queue')->assertForbidden();

    Sanctum::actingAs($this->staff);
    $this->getJson('/api/production-queue')->assertOk();
});
