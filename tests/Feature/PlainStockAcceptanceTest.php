<?php

declare(strict_types=1);

use App\Enums\QuoteState;
use App\Models\Company;
use App\Models\LineItem;
use App\Models\Quote;
use App\Services\QuoteService;

// H5: a plain-stock order (no proof-needing lines) used to dead-end at ACCEPTED
// - its only forward exit was PROOFING, which has nothing to send. Accepting
// such an order now advances straight to PROOF_APPROVED so it can be invoiced.

it('advances a plain-stock order straight to proof-approved on acceptance', function (): void {
    $company = Company::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $company->id, 'state' => 'SENT']);
    // Factory default customization carries no mode/artwork_ref, so the line
    // needs no proof.
    LineItem::factory()->create(['quote_id' => $quote->id]);

    app(QuoteService::class)->accept($quote);

    expect($quote->fresh()->state)->toBe(QuoteState::ProofApproved);
});

it('still lands a customized order in ACCEPTED so it goes through proofing', function (): void {
    $company = Company::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $company->id, 'state' => 'SENT']);
    LineItem::factory()->create([
        'quote_id' => $quote->id,
        'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/x.png'],
    ]);

    app(QuoteService::class)->accept($quote);

    expect($quote->fresh()->state)->toBe(QuoteState::Accepted);
});

it('lets staff invoice a plain-stock order after acceptance (no dead-end end-to-end)', function (): void {
    $company = Company::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $company->id, 'state' => 'SENT']);
    LineItem::factory()->create(['quote_id' => $quote->id]);

    app(QuoteService::class)->accept($quote);
    expect($quote->fresh()->state)->toBe(QuoteState::ProofApproved);

    app(QuoteService::class)->issueInvoice($quote->fresh(), 'PO-1', null, null);

    expect($quote->fresh()->state)->toBe(QuoteState::Confirmed);
});
