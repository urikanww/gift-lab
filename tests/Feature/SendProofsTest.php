<?php

declare(strict_types=1);

use App\Enums\ProofState;
use App\Enums\QuoteState;
use App\Exceptions\DomainRuleException;
use App\Mail\QuoteReadyMail;
use App\Models\Company;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Proof;
use App\Models\Quote;
use App\Models\User;
use App\Services\QuoteService;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    Mail::fake();
    $this->company = Company::factory()->create();
    $this->buyer = User::factory()->create(['company_id' => $this->company->id, 'role' => 'buyer']);
});

function draftFor(Quote $q, string $ref): Proof
{
    $l = LineItem::factory()->create([
        'quote_id' => $q->id, 'product_id' => Product::factory()->create()->id,
        'customization' => ['mode' => 'designer', 'artwork_ref' => $ref], 'line_state' => 'PENDING',
    ]);

    return Proof::factory()->forLine($l)->create(['artwork_version_ref' => $ref, 'state' => 'DRAFT']);
}

it('sends every staged proof in one email and moves the order to proofing', function (): void {
    $quote = Quote::factory()->create(['company_id' => $this->company->id, 'created_by' => $this->buyer->id, 'state' => 'ACCEPTED', 'accepted_at' => now()]);
    draftFor($quote, 'artwork/a.png');
    draftFor($quote, 'artwork/b.png');

    app(QuoteService::class)->sendProofs($quote);

    expect($quote->fresh()->state)->toBe(QuoteState::Proofing)
        ->and($quote->proofs()->where('state', ProofState::Sent->value)->count())->toBe(2);
    Mail::assertQueued(QuoteReadyMail::class, 1);
});

it('refuses to send when nothing is staged', function (): void {
    $quote = Quote::factory()->create(['state' => 'ACCEPTED']);

    expect(fn () => app(QuoteService::class)->sendProofs($quote))
        ->toThrow(DomainRuleException::class);
});
