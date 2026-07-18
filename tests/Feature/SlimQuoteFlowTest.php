<?php

declare(strict_types=1);

use App\Models\Quote;
use App\Models\User;
use App\Services\QuoteService;

it('stamps accepted_at and accepted_by when a buyer accepts', function (): void {
    $buyer = User::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $buyer->company_id, 'state' => 'SENT']);

    $this->actingAs($buyer);
    app(QuoteService::class)->accept($quote);

    $quote->refresh();
    expect($quote->accepted_at)->not->toBeNull()
        ->and($quote->accepted_by)->toBe($buyer->id);
});

it('does not stamp acceptance when accept is called on an illegal state', function (): void {
    $buyer = User::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $buyer->company_id, 'state' => 'DRAFT']);

    $this->actingAs($buyer);
    expect(fn () => app(QuoteService::class)->accept($quote))
        ->toThrow(App\Exceptions\InvalidStateTransitionException::class);

    $fresh = $quote->fresh();
    expect($fresh->accepted_at)->toBeNull()
        ->and($fresh->accepted_by)->toBeNull()
        ->and($fresh->state->value)->toBe('DRAFT');
});
