<?php

declare(strict_types=1);

use App\Events\ProductionQueueUpdated;
use App\Events\ProofStatusChanged;
use App\Models\Company;
use App\Models\LineItem;
use App\Models\ProductionJob;
use App\Models\Proof;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->staff = User::factory()->staffAdmin()->create();
});

/**
 * Run the production-queue endpoint and return how many queries it took.
 */
function queueQueryCount(): int
{
    $count = 0;
    DB::listen(function () use (&$count): void {
        $count++;
    });

    test()->getJson('/api/production-queue')->assertOk();

    return $count;
}

/**
 * Run the quote-show endpoint and return how many queries it took.
 */
function quoteShowQueryCount(Quote $quote): int
{
    $count = 0;
    DB::listen(function () use (&$count): void {
        $count++;
    });

    test()->getJson("/api/quotes/{$quote->reference}")->assertOk();

    return $count;
}

it('exposes quote_reference alongside the surviving quote_id on the queue', function (): void {
    $quote = Quote::factory()->create(['company_id' => $this->company->id]);
    ProductionJob::factory()->create(['quote_id' => $quote->id]);

    Sanctum::actingAs($this->staff);
    $this->getJson('/api/production-queue')
        ->assertOk()
        // quote_id must survive: the realtime stores join incoming broadcasts
        // against on-screen rows by it.
        ->assertJsonPath('data.0.quote_id', $quote->id)
        ->assertJsonPath('data.0.quote_reference', $quote->reference);
});

it('keeps the queue query count flat as jobs are added', function (): void {
    Sanctum::actingAs($this->staff);

    ProductionJob::factory()->count(3)->create([
        'quote_id' => Quote::factory()->create(['company_id' => $this->company->id])->id,
    ]);
    $small = queueQueryCount();

    ProductionJob::factory()->count(7)->create([
        'quote_id' => Quote::factory()->create(['company_id' => $this->company->id])->id,
    ]);
    $large = queueQueryCount();

    // Without eager-loading the quote relation this grows by one query per job.
    expect($large)->toBeLessThanOrEqual($small + 1);
});

it('keeps the quote-show query count flat as line items and proofs are added', function (): void {
    $quote = Quote::factory()->create(['company_id' => $this->company->id]);
    Sanctum::actingAs($this->staff);

    LineItem::factory()->count(2)->create(['quote_id' => $quote->id]);
    Proof::factory()->count(2)->sequence(fn ($s) => ['version' => $s->index + 1])
        ->create(['quote_id' => $quote->id]);
    $small = quoteShowQueryCount($quote);

    LineItem::factory()->count(6)->create(['quote_id' => $quote->id]);
    Proof::factory()->count(6)->sequence(fn ($s) => ['version' => $s->index + 3])
        ->create(['quote_id' => $quote->id]);
    $large = quoteShowQueryCount($quote);

    // QuoteResource hands each child the parent quote via setRelation, so no
    // child re-fetches it. Simplify that back to a plain whenLoaded and this
    // climbs by one query per line item AND one per proof - output stays
    // correct (lazy loading fills it in), only the query count regresses.
    expect($large)->toBeLessThanOrEqual($small + 1);
});

it('exposes quote_reference on a line item payload', function (): void {
    $quote = Quote::factory()->create(['company_id' => $this->company->id]);
    $line = LineItem::factory()->awaitingReconfirm()->create([
        'quote_id' => $quote->id,
        'qty' => 10,
        'procured_qty' => 4,
    ]);

    Sanctum::actingAs($this->staff);
    $this->postJson("/api/line-items/{$line->id}/reconfirm", ['action' => 'approve'])
        ->assertOk()
        ->assertJsonPath('data.quote_id', $quote->id)
        ->assertJsonPath('data.quote_reference', $quote->reference);
});

it('broadcasts quote_reference with the production queue update', function (): void {
    $quote = Quote::factory()->create(['company_id' => $this->company->id]);
    $job = ProductionJob::factory()->create(['quote_id' => $quote->id]);

    $payload = (new ProductionQueueUpdated($job, 'queued'))->broadcastWith();

    expect($payload['quote_id'])->toBe($quote->id)
        ->and($payload['quote_reference'])->toBe($quote->reference);
});

it('broadcasts quote_reference with a proof status change', function (): void {
    $quote = Quote::factory()->create(['company_id' => $this->company->id]);
    $proof = Proof::factory()->create(['quote_id' => $quote->id]);

    $payload = (new ProofStatusChanged($proof, $this->company->id))->broadcastWith();

    expect($payload['quote_id'])->toBe($quote->id)
        ->and($payload['quote_reference'])->toBe($quote->reference);
});
