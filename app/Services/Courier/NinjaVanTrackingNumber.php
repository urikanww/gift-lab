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

    /**
     * Per-JOB tracking number. A quote with multiple production jobs (one UV
     * job + one job per MODEL_3D line - see QueueService::buildJobsForQuote)
     * books one NinjaVan order PER JOB, so keying solely off the quote id (as
     * forQuote does) makes every job of that quote collide on the same
     * requested_tracking_number: NinjaVan accepts the first, rejects the rest
     * as duplicates, and every job but one never matches an inbound webhook.
     *
     * Keyed off the job id - itself a globally-unique primary key - rather
     * than a quote+job composite, so uniqueness holds without needing to fit
     * two numbers into the 9-char budget. Still fully deterministic per job
     * (same job id -> same value), which is what makes it double as both the
     * idempotency key for a retried booking and the stored consignment_ref.
     */
    public static function forJob(int $quoteId, int $jobId): string
    {
        $prefix = strtoupper((string) config('services.ninjavan.tracking_prefix', 'GL'));
        $max = 9;
        $body = strtoupper(base_convert((string) $jobId, 10, 36));
        $candidate = $prefix.$body;

        if (strlen($candidate) <= $max) {
            return $candidate;
        }

        // Job id too large to base36-encode within the length budget: fall back
        // to a deterministic hash slice (salted with the quote id too, purely to
        // spread collisions further) that still fits the length/charset.
        $room = max(1, $max - strlen($prefix));

        $candidate = $prefix.substr(strtoupper(base_convert(substr(md5($quoteId.':'.$jobId), 0, 12), 16, 36)), 0, $room);

        // Final clamp: a misconfigured tracking_prefix (>=9 chars) must never push
        // the value past NinjaVan's 9-char limit.
        return substr($candidate, 0, $max);
    }

    /**
     * Per-SHIPMENT tracking number (Stage 2a). A shipment is the entity that
     * books one NinjaVan order and owns the consignment_ref, so the merchant-
     * supplied requested_tracking_number is keyed off the shipment id - itself a
     * globally-unique primary key - so every shipment of a quote resolves to a
     * distinct value (NinjaVan rejects a duplicate requested_tracking_number).
     *
     * Same derivation as forJob (base36 of the id, hash-slice fallback past the
     * 9-char budget), just keyed on the shipment. Still fully deterministic
     * (same shipment id -> same value), which is what makes it double as both
     * the idempotency key for a retried booking and the stored consignment_ref.
     */
    public static function forShipment(int $quoteId, int $shipmentId): string
    {
        $prefix = strtoupper((string) config('services.ninjavan.tracking_prefix', 'GL'));
        $max = 9;
        $body = strtoupper(base_convert((string) $shipmentId, 10, 36));
        $candidate = $prefix.$body;

        if (strlen($candidate) <= $max) {
            return $candidate;
        }

        $room = max(1, $max - strlen($prefix));

        $candidate = $prefix.substr(strtoupper(base_convert(substr(md5($quoteId.':'.$shipmentId), 0, 12), 16, 36)), 0, $room);

        return substr($candidate, 0, $max);
    }
}
