<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Quote;
use App\Models\User;
use App\Services\PricingService;
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
    // Subtotal matches the line's real total (4 x 10, no fee config seeded in
    // this file) rather than the factory's zero default - amend() now adjusts
    // the stored subtotal by DELTA instead of unconditionally rebuilding it
    // from scratch, so a fixture whose subtotal doesn't reflect its lines
    // would carry that drift through every assertion below.
    $quote = Quote::factory()->create([
        'company_id' => test()->company->id,
        'state' => 'DRAFT',
        'subtotal' => 40,
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

// Money bug (3 defects, one amend() method): amend() used to rebuild the
// subtotal as a bare sum of unit_price * qty across every line, which (a)
// silently dropped the quote-level setup fee and each customized line's flat/
// per-unit decoration fees that create() bakes into the subtotal via
// PricingService::quoteTotals (they are never stored per-line), (b) resummed
// DROPPED/CANCELLED lines back in (a line drop is a line_state, not a
// soft-delete, so those rows are still present), and (c) recomputed even when
// only a non-line field changed. amend() now adjusts the subtotal by DELTA,
// re-deriving only the fee overlay of a line that was actually touched -
// mirroring the DELTA discipline retotalAfterReconfirm() already used.

it('keeps the setup fee and per-line customization fee in the subtotal when amend touches only quantity', function (): void {
    seedPricing(); // fee.setup_fee 25.00, fee.customization_flat 8.00, size M 0.40/unit
    Sanctum::actingAs($this->staff);
    $product = Product::factory()->create(['base_cost' => 10, 'print_method' => 'UV']);

    $quote = app(QuoteService::class)->create(
        $this->company->id,
        [[
            'product_id' => $product->id,
            'variant_id' => null,
            'qty' => 2,
            'customization' => ['logo_size' => 'M'],
        ]],
        null,
    );
    $line = $quote->lineItems->first();

    // Sanity: the frozen subtotal is already well above the bare unit*qty
    // figure - the setup + customization fees are baked in from create().
    $bare = round((float) $line->unit_price * 2, 2);
    expect((float) $quote->subtotal)->toBeGreaterThan($bare + 30); // +8 flat +0.80 size +25 setup, roughly

    // Staff bumps the quantity, keeping the same catalogue unit price.
    app(QuoteService::class)->amend(
        $quote,
        [['id' => $line->id, 'unit_price' => (float) $line->unit_price, 'qty' => 5]],
        null,
        null,
        [],
        null,
        'Buyer asked for more units.',
    );

    // The fee-inclusive total the canonical pricer produces for the SAME
    // amended line configuration - not a bare unit_price * qty resum, which
    // would silently strip the setup fee and the customization fees.
    $expected = app(PricingService::class)->quoteTotals([[
        'product' => $product,
        'variant' => null,
        'qty' => 5,
        'has_customization' => true,
        'logo_size' => 'M',
        'has_text' => false,
    ]])['subtotal'];

    expect((float) $quote->fresh()->subtotal)->toBe($expected);
});

it('does not re-add a dropped lines amount to the subtotal, even if the payload still names it', function (): void {
    seedPricing();
    Sanctum::actingAs($this->staff);
    $quote = Quote::factory()->create([
        'company_id' => $this->company->id,
        'state' => 'DRAFT',
        // Only the active line's bare total - the dropped line already
        // contributes nothing, exactly as it would after a real procurement
        // drop (QuoteService::retotalAfterReconfirm already excludes it).
        'subtotal' => 50.00,
        'delivery' => 10.00,
        'total' => 60.00,
    ]);
    $active = LineItem::factory()->create([
        'quote_id' => $quote->id,
        'product_id' => $this->product->id,
        'unit_price' => 25.00,
        'qty' => 2,
        'customization' => null,
    ]);
    $dropped = LineItem::factory()->create([
        'quote_id' => $quote->id,
        'product_id' => $this->product->id,
        'unit_price' => 40.00,
        'qty' => 3,
        'customization' => null,
        'line_state' => 'DROPPED',
    ]);

    app(QuoteService::class)->amend(
        $quote,
        [
            ['id' => $active->id, 'unit_price' => 30.00, 'qty' => 2],
            // The dropped line is still named in the payload (e.g. a stale
            // UI form) - it must stay at zero, not resurrect its 40 x 3.
            ['id' => $dropped->id, 'unit_price' => 40.00, 'qty' => 3],
        ],
        null,
        null,
        [],
        null,
        'Repricing the active line only.',
    );

    // Active line delta: (30 x 2) - (25 x 2) = +10.00. If the dropped line
    // were resummed at 40 x 3 = 120.00, the subtotal would wrongly land at
    // 170.00 instead.
    expect((float) $quote->fresh()->subtotal)->toBe(60.00);
});

it('leaves the fee-inclusive subtotal unchanged when an amend touches only a non-line field', function (): void {
    seedPricing();
    Sanctum::actingAs($this->staff);
    $product = Product::factory()->create(['base_cost' => 10, 'print_method' => 'UV']);

    $quote = app(QuoteService::class)->create(
        $this->company->id,
        [[
            'product_id' => $product->id,
            'variant_id' => null,
            'qty' => 3,
            'customization' => ['logo_size' => 'L'],
        ]],
        null,
    );
    $before = (float) $quote->fresh()->subtotal;

    // No line amendments, no removals, no additions - only notes change.
    app(QuoteService::class)->amend(
        $quote,
        [],
        null,
        'Buyer asked for a delivery note.',
        [],
        null,
        'Note only, no price change.',
    );

    expect((float) $quote->fresh()->subtotal)->toBe($before)
        ->and($quote->fresh()->notes)->toBe('Buyer asked for a delivery note.');
});
