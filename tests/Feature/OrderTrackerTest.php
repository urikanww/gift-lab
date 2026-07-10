<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Quote;
use App\Services\OrderTracker;

it('builds a PII-free payload for a quote', function (): void {
    $company = Company::factory()->create(['billing_email' => 'buyer@acme.com']);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'state' => 'SENT']);

    $payload = app(OrderTracker::class)->payload($quote->fresh());

    expect($payload['reference'])->toBe($quote->tracking_code)
        ->and($payload['stage'])->toBe('REVIEW')
        ->and($payload['stage_label'])->toBe('In review')
        ->and($payload)->not->toHaveKeys(['total', 'subtotal', 'notes']);
});
