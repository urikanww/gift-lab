<?php

declare(strict_types=1);

use App\Enums\ApprovalOrder;
use App\Models\Quote;

it('defaults a new quote to price_first', function (): void {
    $quote = Quote::factory()->create();

    expect($quote->approval_order)->toBe(ApprovalOrder::PriceFirst);
});

it('persists proof_first via the factory state', function (): void {
    $quote = Quote::factory()->proofFirst()->create();

    expect($quote->fresh()->approval_order)->toBe(ApprovalOrder::ProofFirst);
});
