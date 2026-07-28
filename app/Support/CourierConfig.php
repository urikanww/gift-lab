<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\PricingConfig;
use Carbon\CarbonImmutable;

/**
 * Staff-editable courier settings for NinjaVan: the pickup (sender) address and
 * the collection/delivery time window. Stored as two JSON rows in the shared
 * PricingConfig store (group "courier"), so staff can change the warehouse
 * address or pickup hours from the admin screen instead of a redeploy.
 *
 * Every field falls back to config('services.ninjavan.*') (env) when the stored
 * value is absent or blank, so a fresh install still ships and a partially
 * filled config never sends an empty field to NinjaVan.
 */
final class CourierConfig
{
    public const GROUP = 'courier';

    /** The eight pickup-address fields NinjaVan's `from` block needs. */
    public const PICKUP_FIELDS = ['name', 'phone', 'email', 'address1', 'city', 'state', 'postcode', 'country'];

    /**
     * The collection/delivery windows NinjaVan accepts (SG). NinjaVan validates
     * the timeslot against a fixed list - an arbitrary window (e.g. 10:00-18:00)
     * is rejected with "Invalid timeslot" and the whole booking 400s - so the
     * config is constrained to these rather than free HH:MM.
     *
     * @var list<array{start: string, end: string, label: string}>
     */
    public const VALID_TIMESLOTS = [
        ['start' => '09:00', 'end' => '12:00', 'label' => '9:00am – 12:00pm'],
        ['start' => '12:00', 'end' => '15:00', 'label' => '12:00pm – 3:00pm'],
        ['start' => '15:00', 'end' => '18:00', 'label' => '3:00pm – 6:00pm'],
        ['start' => '18:00', 'end' => '22:00', 'label' => '6:00pm – 10:00pm'],
        ['start' => '09:00', 'end' => '18:00', 'label' => '9:00am – 6:00pm (business hours)'],
        ['start' => '09:00', 'end' => '22:00', 'label' => '9:00am – 10:00pm (all day)'],
    ];

    /** Whether (start, end) is one of NinjaVan's accepted windows. */
    public static function isValidTimeslot(?string $start, ?string $end): bool
    {
        foreach (self::VALID_TIMESLOTS as $slot) {
            if ($slot['start'] === $start && $slot['end'] === $end) {
                return true;
            }
        }

        return false;
    }

    /** Distinct start times, for the "start" dropdown. @return list<string> */
    public static function startTimes(): array
    {
        return array_values(array_unique(array_map(static fn (array $s): string => $s['start'], self::VALID_TIMESLOTS)));
    }

    /** Valid end times for a given start, for the dependent "end" dropdown. @return list<string> */
    public static function endsForStart(string $start): array
    {
        return array_values(array_map(
            static fn (array $s): string => $s['end'],
            array_filter(self::VALID_TIMESLOTS, static fn (array $s): bool => $s['start'] === $start),
        ));
    }

    /** Preferred-weekday options (Mon-Fri; weekends are never collection days) -> ISO day number. */
    public const WEEKDAYS = [
        'monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5,
    ];

    /**
     * The pickup-day schedule: the preferred collection weekday ('any' = earliest
     * available) and the non-collection dates (public holidays) staff maintain.
     *
     * @return array{weekday: string, blackout_dates: list<string>}
     */
    public static function schedule(): array
    {
        $stored = PricingConfig::value(self::GROUP, 'schedule', []);

        $weekday = 'any';
        if (is_array($stored) && isset($stored['weekday']) && array_key_exists($stored['weekday'], self::WEEKDAYS)) {
            $weekday = $stored['weekday'];
        }

        $blackout = [];
        if (is_array($stored) && isset($stored['blackout_dates']) && is_array($stored['blackout_dates'])) {
            $blackout = array_values(array_filter(
                $stored['blackout_dates'],
                static fn ($d): bool => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1,
            ));
        }

        return ['weekday' => $weekday, 'blackout_dates' => $blackout];
    }

    /**
     * The dispatch date NinjaVan collects AND delivers on. Both legs share one
     * day, so this single date fills pickup_date and delivery_start_date.
     *
     * Starts from the order's requested date (never today or the past - a
     * same-day pickup can't be booked), snaps to the configured preferred weekday
     * when set, then rolls forward over any day the courier can't work: weekends
     * and the staff-maintained public-holiday (blackout) list. "Selected day not
     * available -> next day", exactly.
     */
    public static function resolveDispatchDate(string $requestedDate): string
    {
        $schedule = self::schedule();

        $base = CarbonImmutable::parse($requestedDate)->startOfDay();

        // Never today or in the past.
        $earliest = CarbonImmutable::now()->startOfDay()->addDay();
        if ($base->lessThan($earliest)) {
            $base = $earliest;
        }

        // Snap to the preferred weekday's next occurrence on/after base.
        if ($schedule['weekday'] !== 'any') {
            $target = self::WEEKDAYS[$schedule['weekday']];
            for ($i = 0; $i < 7 && $base->dayOfWeekIso !== $target; $i++) {
                $base = $base->addDay();
            }
        }

        // Roll forward over weekends + blackout dates (bounded so a bad list
        // can never spin forever).
        $blackout = array_flip($schedule['blackout_dates']);
        for ($i = 0; $i < 60 && ($base->isWeekend() || isset($blackout[$base->toDateString()])); $i++) {
            $base = $base->addDay();
        }

        return $base->toDateString();
    }

    /**
     * The pickup (sender) address, stored value merged over the env fallback.
     *
     * @return array{name: string, phone: string, email: string, address1: string, city: string, state: string, postcode: string, country: string}
     */
    public static function pickup(): array
    {
        $fallback = (array) config('services.ninjavan.pickup');
        $out = [];
        foreach (self::PICKUP_FIELDS as $field) {
            $out[$field] = (string) ($fallback[$field] ?? '');
        }

        $stored = PricingConfig::value(self::GROUP, 'pickup', []);
        if (is_array($stored)) {
            foreach (self::PICKUP_FIELDS as $field) {
                $v = $stored[$field] ?? null;
                if (is_string($v) && trim($v) !== '') {
                    $out[$field] = trim($v);
                }
            }
        }

        // Country never legitimately blank - default SG like the config does.
        if ($out['country'] === '') {
            $out['country'] = 'SG';
        }

        return $out;
    }

    /**
     * The collection/delivery time window (what NinjaVan calls delivery_timeslot).
     *
     * @return array{start: string, end: string, timezone: string}
     */
    public static function timeslot(): array
    {
        $out = [
            'start' => (string) config('services.ninjavan.timeslot_start', '09:00'),
            'end' => (string) config('services.ninjavan.timeslot_end', '18:00'),
            'timezone' => (string) config('services.ninjavan.timezone', 'Asia/Singapore'),
        ];

        $stored = PricingConfig::value(self::GROUP, 'timeslot', []);
        if (is_array($stored)) {
            foreach (['start', 'end', 'timezone'] as $field) {
                $v = $stored[$field] ?? null;
                if (is_string($v) && trim($v) !== '') {
                    $out[$field] = trim($v);
                }
            }
        }

        // Never hand NinjaVan a window it rejects: if the resolved start/end is
        // not one of its accepted slots, fall back to business hours.
        if (! self::isValidTimeslot($out['start'], $out['end'])) {
            $out['start'] = '09:00';
            $out['end'] = '18:00';
        }

        return $out;
    }
}
