<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PricingConfig;
use App\Services\AuditLogger;
use App\Support\CourierConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The pickup (sender) address and collection time window NinjaVan uses on every
 * shipment. Stored in the shared PricingConfig store under the "courier" group
 * (two JSON rows: pickup + timeslot), read back through CourierConfig - which
 * falls back to config('services.ninjavan.*') for any field left blank, so the
 * screen can be part-filled without breaking a booking.
 *
 * Separate from the pricing-config editor for the same reason notification
 * settings are: this is an operational address staff reason about, not a raw
 * key/value a superadmin already knows the meaning of.
 */
class CourierConfigController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isStaff(), 403);

        // Effective values (stored merged over env), so the form always shows
        // what NinjaVan will actually receive - never a blank that is really a
        // live env fallback. `options` drives the dependent time dropdowns + the
        // weekday picker so the UI never offers an unbookable combination.
        return response()->json([
            'pickup' => CourierConfig::pickup(),
            'timeslot' => CourierConfig::timeslot(),
            'schedule' => CourierConfig::schedule(),
            'options' => [
                'start_times' => CourierConfig::startTimes(),
                'ends_for_start' => collect(CourierConfig::startTimes())
                    ->mapWithKeys(fn (string $s): array => [$s => CourierConfig::endsForStart($s)]),
                'weekdays' => array_keys(CourierConfig::WEEKDAYS),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isStaff(), 403);

        $validator = validator($request->all(), [
            'pickup' => ['required', 'array'],
            'pickup.name' => ['required', 'string', 'max:255'],
            'pickup.phone' => ['required', 'string', 'max:32'],
            'pickup.email' => ['nullable', 'email', 'max:255'],
            'pickup.address1' => ['required', 'string', 'max:255'],
            'pickup.city' => ['nullable', 'string', 'max:120'],
            'pickup.state' => ['nullable', 'string', 'max:120'],
            'pickup.postcode' => ['required', 'string', 'max:20'],
            'pickup.country' => ['required', 'string', 'size:2'],
            'timeslot' => ['required', 'array'],
            'timeslot.start' => ['required', 'string', 'date_format:H:i'],
            'timeslot.end' => ['required', 'string', 'date_format:H:i'],
            'timeslot.timezone' => ['required', 'string', 'timezone', 'max:64'],
            'schedule' => ['required', 'array'],
            // 'any' = earliest available; otherwise a Mon-Fri collection weekday.
            'schedule.weekday' => ['required', 'string', 'in:any,'.implode(',', array_keys(CourierConfig::WEEKDAYS))],
            // Non-collection dates (public holidays) staff maintain.
            'schedule.blackout_dates' => ['present', 'array', 'max:60'],
            'schedule.blackout_dates.*' => ['string', 'date_format:Y-m-d'],
        ]);

        // NinjaVan only accepts a fixed set of windows - reject anything else up
        // front rather than letting the booking 400 later with "Invalid timeslot".
        $validator->after(function ($v) use ($request): void {
            $slot = (array) $request->input('timeslot');
            if (! CourierConfig::isValidTimeslot($slot['start'] ?? null, $slot['end'] ?? null)) {
                $v->errors()->add('timeslot.end', 'Pick one of the supported collection windows.');
            }
        });

        $validated = $validator->validate();

        // Normalise the country to uppercase (NinjaVan expects an ISO-2 code).
        $pickup = $validated['pickup'];
        $pickup['country'] = strtoupper($pickup['country']);

        // Dedupe + sort the blackout dates so the stored list stays tidy.
        $schedule = $validated['schedule'];
        $schedule['blackout_dates'] = collect($schedule['blackout_dates'])->unique()->sort()->values()->all();

        foreach ([['pickup', $pickup], ['timeslot', $validated['timeslot']], ['schedule', $schedule]] as [$key, $value]) {
            $before = PricingConfig::value(CourierConfig::GROUP, $key);

            $config = PricingConfig::updateOrCreate(
                ['group' => CourierConfig::GROUP, 'key' => $key],
                ['value' => $value, 'updated_by' => $request->user()->id],
            );

            $this->audit->log($config, 'courier_config.updated', ['value' => $before], ['value' => $value]);
        }

        return response()->json([
            'pickup' => CourierConfig::pickup(),
            'timeslot' => CourierConfig::timeslot(),
            'schedule' => CourierConfig::schedule(),
        ]);
    }
}
