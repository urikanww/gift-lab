<?php

declare(strict_types=1);

use App\Enums\ProofState;
use App\Enums\QuoteState;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Proof;
use App\Models\Quote;
use App\Services\QuoteService;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    Mail::fake();
    $this->svc = app(QuoteService::class);
});

function artworkLine(Quote $quote): LineItem
{
    return LineItem::factory()->create([
        'quote_id' => $quote->id,
        'product_id' => Product::factory()->create()->id,
        'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/a.png'],
        'line_state' => 'PENDING',
    ]);
}

it('approving one line proof leaves the order in proofing while another is open', function (): void {
    $quote = Quote::factory()->create(['state' => 'PROOFING', 'accepted_at' => null]);
    $pa = Proof::factory()->create(['quote_id' => $quote->id, 'line_item_id' => artworkLine($quote)->id, 'state' => 'SENT']);
    Proof::factory()->create(['quote_id' => $quote->id, 'line_item_id' => artworkLine($quote)->id, 'state' => 'SENT']);

    $this->svc->approveProof($pa->fresh());

    expect($pa->fresh()->state)->toBe(ProofState::Approved)
        ->and($quote->fresh()->state)->toBe(QuoteState::Proofing);
});

it('approving the last open line proof advances the order to artwork-approved', function (): void {
    $quote = Quote::factory()->create(['state' => 'PROOFING', 'accepted_at' => null]);
    $only = Proof::factory()->create(['quote_id' => $quote->id, 'line_item_id' => artworkLine($quote)->id, 'state' => 'SENT']);

    $this->svc->approveProof($only->fresh());

    expect($quote->fresh()->state)->toBe(QuoteState::ArtworkApproved);
});

it('requesting changes on one line rolls the order to changes-requested', function (): void {
    $quote = Quote::factory()->create(['state' => 'PROOFING', 'accepted_at' => null]);
    Proof::factory()->create(['quote_id' => $quote->id, 'line_item_id' => artworkLine($quote)->id, 'state' => 'APPROVED']);
    $b = Proof::factory()->create(['quote_id' => $quote->id, 'line_item_id' => artworkLine($quote)->id, 'state' => 'SENT']);

    $this->svc->requestProofChanges($b->fresh(), 'fix logo', []);

    expect($quote->fresh()->state)->toBe(QuoteState::ChangesRequested);
});
