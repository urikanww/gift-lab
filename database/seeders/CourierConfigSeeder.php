<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\PricingConfig;
use App\Support\CourierConfig;
use Illuminate\Database\Seeder;

/**
 * Default NinjaVan pickup address + collection window, so a fresh install can
 * book a shipment out of the box and the admin screen opens on real values.
 *
 * Placeholder warehouse - staff edit it under Courier settings (or here). Only
 * inserts when the row is absent, so a re-seed never clobbers an admin's edits.
 */
class CourierConfigSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            'pickup' => [
                'name' => 'Gift Lab',
                'phone' => '+6560000000',
                'email' => 'ops@nexgen.com.sg',
                'address1' => '71 Ayer Rajah Crescent, #01-01',
                'city' => 'Singapore',
                'state' => 'Singapore',
                'postcode' => '139951',
                'country' => 'SG',
            ],
            'timeslot' => [
                'start' => '09:00',
                'end' => '18:00',
                'timezone' => 'Asia/Singapore',
            ],
            // 'any' = collect on the earliest available day. blackout_dates are
            // the fixed-date SG public holidays (the lunar/Islamic ones move each
            // year, so staff add those on the Courier screen). Weekends are
            // skipped automatically and are not listed here.
            'schedule' => [
                'weekday' => 'any',
                'blackout_dates' => [
                    '2026-01-01', '2026-05-01', '2026-08-09', '2026-12-25',
                    '2027-01-01', '2027-05-01', '2027-08-09', '2027-12-25',
                ],
            ],
        ];

        $labels = [
            'pickup' => 'NinjaVan pickup address',
            'timeslot' => 'NinjaVan collection window',
            'schedule' => 'NinjaVan pickup day + non-collection dates',
        ];

        foreach ($defaults as $key => $value) {
            PricingConfig::firstOrCreate(
                ['group' => CourierConfig::GROUP, 'key' => $key],
                ['value' => $value, 'label' => $labels[$key] ?? $key],
            );
        }
    }
}
