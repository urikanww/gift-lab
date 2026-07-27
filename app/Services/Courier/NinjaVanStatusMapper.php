<?php

declare(strict_types=1);

namespace App\Services\Courier;

/**
 * Maps NinjaVan's raw webhook status wording to the buyer/staff-facing label
 * stored in production_jobs.last_courier_status, and decides whether the
 * event is the terminal "delivered" signal.
 *
 * Case-insensitive and tolerant of NinjaVan's wording variants (their status
 * strings differ slightly across API versions/docs) - matching is done on a
 * lowercased, trimmed copy of the raw string. Anything not recognised below
 * is NOT dropped: the raw string is kept as the label (known: false) so
 * staff still see it, and the caller logs a warning rather than crashing.
 */
final class NinjaVanStatusMapper
{
    public static function map(string $rawStatus): NinjaVanStatusMapping
    {
        $normalized = strtolower(trim($rawStatus));

        return match (true) {
            $normalized === 'pending pickup' => new NinjaVanStatusMapping('Shipped', deliver: false, known: true),

            in_array($normalized, ['picked up', 'van en-route', 'successful pickup'], true)
                => new NinjaVanStatusMapping('Picked up', deliver: false, known: true),

            in_array($normalized, [
                'in transit',
                'on vehicle for delivery(transit)',
                'transferred',
                'arrived at sorting hub',
            ], true) => new NinjaVanStatusMapping('In transit', deliver: false, known: true),

            in_array($normalized, ['out for delivery', 'on vehicle for delivery(final)'], true)
                => new NinjaVanStatusMapping('Out for delivery', deliver: false, known: true),

            in_array($normalized, ['completed', 'delivered', 'successful delivery'], true)
                => new NinjaVanStatusMapping('Delivered', deliver: true, known: true),

            // "First Attempt Failed", "Second Attempt Failed", "Third Attempt
            // Failed", ... - match the common suffix rather than enumerating
            // every ordinal NinjaVan might send.
            str_ends_with($normalized, 'attempt failed') || $normalized === 'pending reschedule'
                => new NinjaVanStatusMapping('Delivery attempt failed', deliver: false, known: true),

            in_array($normalized, ['returned to sender', 'returned', 'cancelled'], true)
                => new NinjaVanStatusMapping('Delivery unsuccessful — returned', deliver: false, known: true),

            default => new NinjaVanStatusMapping($rawStatus, deliver: false, known: false),
        };
    }
}
