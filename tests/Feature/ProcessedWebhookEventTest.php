<?php

declare(strict_types=1);

use App\Models\ProcessedWebhookEvent;

/**
 * The event-level idempotency ledger backing the Stripe (L7) and NinjaVan
 * (L10/L11) webhook dedup. A (source, event_key) is processed() once recorded,
 * and record() is safe to call twice (a concurrent duplicate).
 */
it('reports an event as processed only after it is recorded', function (): void {
    expect(ProcessedWebhookEvent::processed('stripe', 'evt_123'))->toBeFalse();

    ProcessedWebhookEvent::record('stripe', 'evt_123');

    expect(ProcessedWebhookEvent::processed('stripe', 'evt_123'))->toBeTrue();
});

it('scopes the key by source', function (): void {
    ProcessedWebhookEvent::record('stripe', 'shared-key');

    expect(ProcessedWebhookEvent::processed('stripe', 'shared-key'))->toBeTrue()
        ->and(ProcessedWebhookEvent::processed('ninjavan', 'shared-key'))->toBeFalse();
});

it('is a harmless no-op when the same event is recorded twice (concurrent duplicate)', function (): void {
    ProcessedWebhookEvent::record('ninjavan', 'dup-key');
    ProcessedWebhookEvent::record('ninjavan', 'dup-key');

    expect(ProcessedWebhookEvent::where('source', 'ninjavan')->where('event_key', 'dup-key')->count())->toBe(1);
});
