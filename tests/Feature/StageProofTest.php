<?php

declare(strict_types=1);

use App\Enums\ProofState;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Quote;
use App\Services\QuoteService;
use Illuminate\Support\Facades\Mail;

beforeEach(fn () => Mail::fake());

function line(Quote $q): LineItem
{
    return LineItem::factory()->create([
        'quote_id' => $q->id, 'product_id' => Product::factory()->create()->id,
        'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/a.png'], 'line_state' => 'PENDING',
    ]);
}

it('stages a draft proof for a line and emails nobody', function (): void {
    $quote = Quote::factory()->create(['state' => 'ACCEPTED']);
    $l = line($quote);

    $proof = app(QuoteService::class)->stageProof($quote, $l, 'artwork/v1.png');

    expect($proof->state)->toBe(ProofState::Draft)
        ->and($proof->line_item_id)->toBe($l->id)
        ->and($proof->version)->toBe(1);
    Mail::assertNothingQueued();
});

it('replaces the artwork on a still-staged draft instead of versioning up', function (): void {
    $quote = Quote::factory()->create(['state' => 'ACCEPTED']);
    $l = line($quote);
    $svc = app(QuoteService::class);

    $svc->stageProof($quote, $l, 'artwork/v1.png');
    $second = $svc->stageProof($quote, $l, 'artwork/v2.png');

    expect($second->version)->toBe(1)
        ->and($second->artwork_version_ref)->toBe('artwork/v2.png')
        ->and($l->proofs()->count())->toBe(1);
});
