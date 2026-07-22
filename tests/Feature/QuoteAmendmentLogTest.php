<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Quote;
use App\Models\User;
use App\Services\QuoteService;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->staff = User::factory()->staffAdmin()->create(['name' => 'Ada Ops']);
    $this->buyer = User::factory()->create(['company_id' => $this->company->id, 'role' => 'buyer']);
    $this->product = Product::factory()->create(['name' => 'Enamel Mug', 'base_cost' => 1]);
});

function draftWithLine(): array
{
    $quote = Quote::factory()->create([
        'company_id' => test()->company->id,
        'state' => 'DRAFT',
        'delivery' => 5,
    ]);
    $line = LineItem::factory()->create([
        'quote_id' => $quote->id,
        'product_id' => test()->product->id,
        'unit_price' => 10,
        'qty' => 4,
    ]);

    return [$quote, $line];
}

it('records a line edit with who, what, when, product name and a batch id', function (): void {
    Sanctum::actingAs($this->staff);
    [$quote, $line] = draftWithLine();

    app(QuoteService::class)->amend(
        $quote,
        [['id' => $line->id, 'unit_price' => 12.5, 'qty' => 6]],
        null,
        null,
    );

    $log = $quote->fresh()->amendment_log;
    expect($log)->toHaveCount(1);

    $edit = $log[0];
    expect($edit['action'])->toBe('edited')
        ->and($edit['by'])->toBe($this->staff->id)
        // Name is SNAPSHOTTED, not just the id - survives the account's deletion.
        ->and($edit['by_name'])->toBe('Ada Ops')
        ->and($edit['product_name'])->toBe('Enamel Mug')
        ->and($edit['from'])->toMatchArray(['qty' => 4])
        ->and($edit['to'])->toMatchArray(['unit_price' => 12.5, 'qty' => 6])
        ->and($edit['at'])->not->toBeNull()
        ->and($edit['batch'])->not->toBeNull();
});

it('groups every change from one save under a single batch id', function (): void {
    Sanctum::actingAs($this->staff);
    [$quote, $line] = draftWithLine();

    // One save: edit a line, change delivery, change notes -> three entries that
    // must share a batch so the UI can render them as one grouped amendment.
    app(QuoteService::class)->amend(
        $quote,
        [['id' => $line->id, 'unit_price' => 12.5, 'qty' => 6]],
        20.0,
        'Rush order.',
    );

    $log = $quote->fresh()->amendment_log;
    $actions = array_column($log, 'action');
    expect($actions)->toContain('edited')->toContain('delivery')->toContain('notes');

    $batches = array_unique(array_column($log, 'batch'));
    expect($batches)->toHaveCount(1);

    $delivery = collect($log)->firstWhere('action', 'delivery');
    expect($delivery['from'])->toMatchArray(['delivery' => 5.0])
        ->and($delivery['to'])->toMatchArray(['delivery' => 20.0]);
});

it('starts a fresh batch on each separate save', function (): void {
    Sanctum::actingAs($this->staff);
    [$quote, $line] = draftWithLine();
    $svc = app(QuoteService::class);

    $svc->amend($quote, [['id' => $line->id, 'unit_price' => 11, 'qty' => 4]], null, null);
    $svc->amend($quote->fresh(), [['id' => $line->id, 'unit_price' => 13, 'qty' => 4]], null, null);

    $batches = array_unique(array_column($quote->fresh()->amendment_log, 'batch'));
    expect($batches)->toHaveCount(2);
});

it('does not log delivery or notes when they are unchanged', function (): void {
    Sanctum::actingAs($this->staff);
    [$quote, $line] = draftWithLine();

    // delivery passed equal to the current value, notes left null.
    app(QuoteService::class)->amend(
        $quote,
        [['id' => $line->id, 'unit_price' => 12.5, 'qty' => 6]],
        5.0,
        null,
    );

    $actions = array_column($quote->fresh()->amendment_log, 'action');
    expect($actions)->not->toContain('delivery')->not->toContain('notes');
});

it('exposes the amendment log to staff', function (): void {
    [$quote] = draftWithLine();
    $quote->update([
        'amendment_log' => [[
            'batch' => 'b1', 'action' => 'edited', 'by' => $this->staff->id,
            'by_name' => 'Ada Ops', 'at' => '2026-07-21T10:00:00+00:00',
            'product_name' => 'Enamel Mug',
            'from' => ['unit_price' => 10, 'qty' => 4],
            'to' => ['unit_price' => 12.5, 'qty' => 6],
        ]],
    ]);

    Sanctum::actingAs($this->staff);
    $res = getJson("/api/quotes/{$quote->reference}");

    $res->assertOk()->assertJsonPath('data.amendment_log.0.by_name', 'Ada Ops');
});

it('never exposes the amendment log to a buyer', function (): void {
    [$quote] = draftWithLine();
    $quote->update([
        'amendment_log' => [[
            'batch' => 'b1', 'action' => 'edited', 'by' => $this->staff->id,
            'by_name' => 'Ada Ops', 'at' => '2026-07-21T10:00:00+00:00',
            'from' => ['unit_price' => 10, 'qty' => 4], 'to' => ['unit_price' => 12.5, 'qty' => 6],
        ]],
    ]);

    Sanctum::actingAs($this->buyer);
    $res = getJson("/api/quotes/{$quote->reference}");

    // Internal prices and margins must never reach a buyer payload.
    $res->assertOk()->assertJsonMissingPath('data.amendment_log');
});

it('folds signed adjustments into the total, after delivery', function (): void {
    Sanctum::actingAs($this->staff);
    [$quote, $line] = draftWithLine(); // 4 × 10 = 40 subtotal, delivery 5

    app(QuoteService::class)->amend(
        $quote,
        [],
        null,
        null,
        [],
        [
            ['label' => 'Loyalty discount', 'amount' => -6],
            ['label' => 'GST 9%', 'amount' => 3.51],
        ],
    );

    $fresh = $quote->fresh();
    // 40 + 5 + (-6 + 3.51) = 42.51
    expect((float) $fresh->total)->toBe(42.51)
        ->and($fresh->adjustments)->toHaveCount(2)
        ->and($fresh->adjustments[0]['label'])->toBe('Loyalty discount')
        // JSON has no int/float distinction, so -6 decodes as int; compare by value.
        ->and((float) $fresh->adjustments[0]['amount'])->toBe(-6.0);
});

it('leaves adjustments untouched when the amend does not send them', function (): void {
    Sanctum::actingAs($this->staff);
    [$quote, $line] = draftWithLine();
    $quote->update(['adjustments' => [['label' => 'Fee', 'amount' => 10]]]);

    // null adjustments arg => leave the set alone.
    app(QuoteService::class)->amend($quote, [['id' => $line->id, 'unit_price' => 12, 'qty' => 4]], null, null);

    expect($quote->fresh()->adjustments)->toHaveCount(1);
});

it('clears adjustments when an empty set is sent', function (): void {
    Sanctum::actingAs($this->staff);
    [$quote, $line] = draftWithLine();
    $quote->update(['adjustments' => [['label' => 'Fee', 'amount' => 10]], 'total' => 55]);

    app(QuoteService::class)->amend($quote, [], null, null, [], []);

    $fresh = $quote->fresh();
    expect($fresh->adjustments)->toBe([])
        // 40 + 5 + 0
        ->and((float) $fresh->total)->toBe(45.0);
});

it('logs an adjustments change in the edit trail', function (): void {
    Sanctum::actingAs($this->staff);
    [$quote, $line] = draftWithLine();

    app(QuoteService::class)->amend($quote, [], null, null, [], [['label' => 'Discount', 'amount' => -5]]);

    $adj = collect($quote->fresh()->amendment_log)->firstWhere('action', 'adjustments');
    expect($adj)->not->toBeNull()
        ->and($adj['to'])->toMatchArray(['total' => -5.0]);
});

it('exposes adjustments to a buyer, since they change what is owed', function (): void {
    [$quote] = draftWithLine();
    $quote->update(['adjustments' => [['label' => 'GST', 'amount' => 3.5]]]);

    Sanctum::actingAs($this->buyer);
    $res = getJson("/api/quotes/{$quote->reference}");

    $res->assertOk()->assertJsonPath('data.adjustments.0.label', 'GST');
});

it('rejects an edit submitted without a remark', function (): void {
    Sanctum::actingAs($this->staff);
    [$quote, $line] = draftWithLine();

    $this->patchJson("/api/quotes/{$quote->id}/amend", [
        'lines' => [['id' => $line->id, 'unit_price' => 12, 'qty' => 4]],
    ])->assertStatus(422)->assertJsonValidationErrors('remark');
});

it('rejects a remark of 10 characters or fewer', function (): void {
    Sanctum::actingAs($this->staff);
    [$quote, $line] = draftWithLine();

    $this->patchJson("/api/quotes/{$quote->id}/amend", [
        'lines' => [['id' => $line->id, 'unit_price' => 12, 'qty' => 4]],
        'remark' => 'too short', // 9 chars
    ])->assertStatus(422)->assertJsonValidationErrors('remark');
});

it('records the remark on the edit trail when the edit goes through', function (): void {
    Sanctum::actingAs($this->staff);
    [$quote, $line] = draftWithLine();

    $this->patchJson("/api/quotes/{$quote->id}/amend", [
        'lines' => [['id' => $line->id, 'unit_price' => 12, 'qty' => 4]],
        'remark' => 'Repriced after supplier quote.',
    ])->assertOk();

    $log = $quote->fresh()->amendment_log;
    expect($log[0]['remark'])->toBe('Repriced after supplier quote.');
});
