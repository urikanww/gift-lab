<?php

declare(strict_types=1);

use App\Models\Quote;

it('cancels only DRAFT quotes past the grace window', function (): void {
    // Untouched draft older than 14 days → expires.
    $stale = Quote::factory()->create(['state' => 'DRAFT']);
    $stale->forceFill(['updated_at' => now()->subDays(20)])->saveQuietly();

    // Recent draft → kept.
    $fresh = Quote::factory()->create(['state' => 'DRAFT']);
    $fresh->forceFill(['updated_at' => now()->subDays(2)])->saveQuietly();

    // Progressed order, even if old → kept (never auto-cancel real work).
    $sent = Quote::factory()->create(['state' => 'SENT']);
    $sent->forceFill(['updated_at' => now()->subDays(30)])->saveQuietly();

    $this->artisan('quotes:expire-drafts')->assertSuccessful();

    expect($stale->fresh()->state->value)->toBe('CANCELLED')
        ->and($fresh->fresh()->state->value)->toBe('DRAFT')
        ->and($sent->fresh()->state->value)->toBe('SENT');
});

it('respects a custom grace window', function (): void {
    $draft = Quote::factory()->create(['state' => 'DRAFT']);
    $draft->forceFill(['updated_at' => now()->subDays(5)])->saveQuietly();

    $this->artisan('quotes:expire-drafts --days=3')->assertSuccessful();

    expect($draft->fresh()->state->value)->toBe('CANCELLED');
});
