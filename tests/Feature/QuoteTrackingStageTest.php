<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Quote;

/**
 * The old REVIEW bucket lumped six states together, including four where the
 * BUYER must act (Sent/Proofing/ArtworkApproved/ChangesRequested) alongside
 * two where staff/the system are the ones moving things along (Draft/
 * Accepted). Splitting ACTION_REQUIRED out gives buyers a clear "we need your
 * approval" signal instead of a passive "in review" that hides whose turn it is.
 */
it('maps buyer-actionable states to ACTION_REQUIRED', function (string $state): void {
    $company = Company::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $company->id, 'state' => $state]);

    expect($quote->trackingStage())->toBe('ACTION_REQUIRED')
        ->and($quote->trackingStageLabel())->toBe('Awaiting your approval');
})->with(['SENT', 'PROOFING', 'ARTWORK_APPROVED', 'CHANGES_REQUESTED']);

it('keeps passive review states as REVIEW / "In review"', function (string $state): void {
    $company = Company::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $company->id, 'state' => $state]);

    expect($quote->trackingStage())->toBe('REVIEW')
        ->and($quote->trackingStageLabel())->toBe('In review');
})->with(['DRAFT', 'ACCEPTED']);

it('leaves the confirmed/production/shipped/delivered/cancelled stages unchanged', function (): void {
    $company = Company::factory()->create();

    expect(Quote::factory()->create(['company_id' => $company->id, 'state' => 'PROOF_APPROVED'])->trackingStage())->toBe('CONFIRMED')
        ->and(Quote::factory()->create(['company_id' => $company->id, 'state' => 'INVOICED'])->trackingStage())->toBe('CONFIRMED')
        ->and(Quote::factory()->create(['company_id' => $company->id, 'state' => 'CONFIRMED'])->trackingStage())->toBe('CONFIRMED')
        ->and(Quote::factory()->create(['company_id' => $company->id, 'state' => 'PROCURING'])->trackingStage())->toBe('CONFIRMED')
        ->and(Quote::factory()->create(['company_id' => $company->id, 'state' => 'CANCELLED'])->trackingStage())->toBe('CANCELLED')
        ->and(Quote::factory()->create(['company_id' => $company->id, 'state' => 'CLOSED'])->trackingStage())->toBe('DELIVERED');
});

it('adds ACTION_REQUIRED to the public stage list, positioned before CONFIRMED', function (): void {
    $labels = Quote::TRACKING_STAGE_LABELS;
    $codes = array_keys($labels);

    expect($labels['ACTION_REQUIRED'])->toBe('Awaiting your approval')
        ->and(array_search('ACTION_REQUIRED', $codes, true))->toBeLessThan(array_search('CONFIRMED', $codes, true))
        ->and($codes)->not->toContain('BUYER_ACTION') // internal enum names must stay out of the payload
        ->and($labels)->toMatchArray([
            'REVIEW' => 'In review',
            'ACTION_REQUIRED' => 'Awaiting your approval',
            'CONFIRMED' => 'Confirmed',
            'IN_PRODUCTION' => 'In production',
            'SHIPPED' => 'Shipped',
            'DELIVERED' => 'Delivered',
        ]);
});
