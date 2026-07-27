<?php

declare(strict_types=1);

namespace App\Services\Courier;

/**
 * Result of mapping a raw NinjaVan status string to an app-facing action.
 * `deliver` is the only status family that advances JobState (SHIPPED ->
 * CLOSED, via QueueService::advance); every other known status is a
 * flag-only label written to production_jobs.last_courier_status - product
 * decision: failed/returned parcels get surfaced to staff, not a new
 * JobState. `known` is false only for a status string NinjaVanStatusMapper
 * has never seen before; the raw string is still stored (never dropped) and
 * a warning is logged so an unmapped wording doesn't silently disappear.
 */
final class NinjaVanStatusMapping
{
    public function __construct(
        public readonly string $label,
        public readonly bool $deliver,
        public readonly bool $known,
    ) {}
}
