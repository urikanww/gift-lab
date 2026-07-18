<?php

declare(strict_types=1);

namespace App\Services\Courier;

/**
 * Merchant-supplied NinjaVan tracking number. NinjaVan requires the merchant to
 * pass requested_tracking_number (1-9 chars, alphanumeric + dash, WITHOUT the
 * account prefix which NinjaVan prepends). We derive it deterministically from
 * the quote id so the same order can never book two different consignments and
 * the value doubles as the idempotency key + the stored consignment_ref.
 */
final class NinjaVanTrackingNumber
{
    public static function forQuote(int $quoteId): string
    {
        $prefix = strtoupper((string) config('services.ninjavan.tracking_prefix', 'GL'));
        $max = 9;
        $body = strtoupper(base_convert((string) $quoteId, 10, 36));
        $candidate = $prefix.$body;

        if (strlen($candidate) <= $max) {
            return $candidate;
        }

        // Quote id too large to base36-encode within 9 chars: fall back to a
        // deterministic hash slice that still fits the length/charset.
        $room = max(1, $max - strlen($prefix));

        $candidate = $prefix.substr(strtoupper(base_convert(substr(md5((string) $quoteId), 0, 12), 16, 36)), 0, $room);

        // Final clamp: a misconfigured tracking_prefix (>=9 chars) must never push
        // the value past NinjaVan's 9-char limit.
        return substr($candidate, 0, $max);
    }
}
