<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Idempotency / replay ledger for inbound webhooks (L7, L10, L11). One row per
 * (source, event_key) that has been successfully handled. The controllers check
 * processed() before acting and call record() after, so a retried or replayed
 * delivery is recognised and skipped rather than re-running side effects.
 *
 * Record-on-success (not claim-before): a handler that throws never records, so
 * the courier/Stripe retry re-processes it. The rare concurrent-duplicate race
 * is still covered by the per-row locks the handlers already hold (the webhook's
 * lockForUpdate, confirmPaid's TOCTOU guard); record()'s unique-violation catch
 * makes the second writer a harmless no-op.
 */
class ProcessedWebhookEvent extends Model
{
    protected $fillable = ['source', 'event_key'];

    public static function processed(string $source, string $eventKey): bool
    {
        return static::query()
            ->where('source', $source)
            ->where('event_key', $eventKey)
            ->exists();
    }

    public static function record(string $source, string $eventKey): void
    {
        try {
            static::query()->create(['source' => $source, 'event_key' => $eventKey]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent duplicate delivery already recorded it - harmless.
        }
    }
}
