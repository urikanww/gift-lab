<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PricingConfig;
use App\Services\Courier\CourierShipment;
use App\Services\Courier\HttpNinjaVanClient;
use App\Support\CourierConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Fire N live sandbox orders that match NinjaVan's audit "Test scenario #1"
 * (Parcel / STANDARD, single item, no collection point / insured value / COD /
 * dangerous goods / cold chain) and print the tracking number NinjaVan returns
 * for each. Those tracking numbers are what you paste into the audit link.
 *
 * Talks to the REAL sandbox host in config('services.ninjavan.base_url') using
 * the current client_id/client_secret, so run it AFTER the token fixes are
 * deployed and the credentials are rotated - orders must exist under the same
 * client ID the audit checks, and be submitted within 6 days of creation.
 *
 * Instantiates HttpNinjaVanClient directly (not via the CourierClient binding),
 * so it works regardless of the app environment's fail-closed sandbox guard.
 */
final class NinjaVanAuditOrders extends Command
{
    protected $signature = 'ninjavan:audit-orders
        {count=3 : How many test orders to create}
        {--weight=1 : Parcel weight in kg}
        {--slot-start=09:00 : delivery_timeslot start_time}
        {--slot-end=22:00 : delivery_timeslot end_time (scenario #1 wants 22:00)}
        {--pickup-slot-start=09:00 : pickup_timeslot start_time}
        {--pickup-slot-end=18:00 : pickup_timeslot end_time (scenario #1 wants 18:00)}';

    protected $description = 'Create NinjaVan sandbox test orders (audit scenario #1) and print the returned tracking numbers.';

    public function handle(): int
    {
        $count = max(1, (int) $this->argument('count'));
        $weight = (float) $this->option('weight');
        $slotStart = (string) $this->option('slot-start');
        $slotEnd = (string) $this->option('slot-end');
        $pickupStart = (string) $this->option('pickup-slot-start');
        $pickupEnd = (string) $this->option('pickup-slot-end');

        // Scenario #1 validates pickup and delivery windows SEPARATELY - pickup
        // 09:00-18:00, delivery 09:00-22:00 - so both must be valid slots.
        if (! CourierConfig::isValidTimeslot($slotStart, $slotEnd)) {
            $this->error("Delivery window {$slotStart}-{$slotEnd} is not one of NinjaVan's accepted slots.");

            return self::FAILURE;
        }
        if (! CourierConfig::isValidTimeslot($pickupStart, $pickupEnd)) {
            $this->error("Pickup window {$pickupStart}-{$pickupEnd} is not one of NinjaVan's accepted slots.");

            return self::FAILURE;
        }

        $base = (string) config('services.ninjavan.base_url');
        if (config('services.ninjavan.client_id') === null || config('services.ninjavan.client_secret') === null) {
            $this->error('NINJAVAN_CLIENT_ID / NINJAVAN_CLIENT_SECRET are not set. Configure them before running.');

            return self::FAILURE;
        }

        // The sandbox 400s an order with no pickup (sender) address, so surface a
        // clear error here instead of a cryptic remote failure per order.
        $pickup = CourierConfig::pickup();
        foreach (['name', 'phone', 'address1', 'postcode'] as $required) {
            if (($pickup[$required] ?? '') === '') {
                $this->error("Pickup address is incomplete (missing '{$required}'). Set it on Staff > Courier, or the NINJAVAN_PICKUP_* env, then retry.");

                return self::FAILURE;
            }
        }

        // CourierConfig reads the stored "courier" rows before the env fallback,
        // so a config() override won't win. Temporarily set the pickup + delivery
        // window rows to the audit windows for this run, then restore whatever
        // was there (try/finally below), so the live windows are unchanged after.
        $tz = CourierConfig::timeslot()['timezone'];
        $restores = [
            $this->overrideCourierConfig('timeslot', ['start' => $slotStart, 'end' => $slotEnd, 'timezone' => $tz]),
            $this->overrideCourierConfig('pickup_timeslot', ['start' => $pickupStart, 'end' => $pickupEnd, 'timezone' => $tz]),
        ];

        try {
            $delivery = CourierConfig::timeslot();
            $pickupWin = CourierConfig::pickupTimeslot();
            $this->line("Host:     {$base}");
            $this->line("Pickup:   {$pickup['name']} / {$pickup['postcode']} / {$pickup['country']}");
            $this->line("Pickup window:   {$pickupWin['start']}-{$pickupWin['end']} {$pickupWin['timezone']}");
            $this->line("Delivery window: {$delivery['start']}-{$delivery['end']} {$delivery['timezone']}  (both restored after run)");
            $this->newLine();

            // A near-future delivery date; the client snaps it to the configured
            // dispatch weekday and rolls past weekends/holidays.
            $deliveryStartDate = Carbon::now()->addDays(3)->toDateString();

            $client = new HttpNinjaVanClient;
            $rows = [];

            for ($i = 1; $i <= $count; $i++) {
                // Unique 8-char merchant tracking number (prefix + 6 hex, no
                // account prefix - NinjaVan prepends that and returns the
                // canonical value).
                $requested = strtoupper((string) config('services.ninjavan.tracking_prefix', 'GL').bin2hex(random_bytes(3)));
                $reference = 'AUDIT-'.Carbon::now()->format('ymd-His')."-{$i}";

                $shipment = new CourierShipment(
                    reference: $reference,
                    recipientName: 'Audit Recipient '.$i,
                    phone: '+6591234567',
                    email: 'audit@giftlab.test',
                    line1: '1 Marina Boulevard',
                    line2: null,
                    city: 'Singapore',
                    state: null,
                    postalCode: '018989',
                    country: 'SG',
                    notes: 'Integration audit test order',
                    parcelCount: 1,
                    requestedTrackingNumber: $requested,
                    deliveryStartDate: $deliveryStartDate,
                    weightKg: $weight,
                );

                try {
                    $result = $client->createShipment($shipment);
                    $rows[] = [$i, $reference, $requested, $result->trackingRef, $result->labelUrl ?? '-'];
                    $this->info("Order {$i}: {$result->trackingRef}");
                } catch (Throwable $e) {
                    $rows[] = [$i, $reference, $requested, 'FAILED', $e->getMessage()];
                    $this->error("Order {$i} failed: {$e->getMessage()}");
                }
            }
        } finally {
            // Restore both windows exactly as they were, so production is never
            // left on the audit windows.
            foreach ($restores as $restore) {
                $restore();
            }
        }

        $this->newLine();
        $this->table(
            ['#', 'Reference', 'Requested', 'Tracking number (submit this)', 'Label / error'],
            $rows,
        );

        $this->newLine();
        $this->line('Wait at least 5 minutes, then paste the tracking numbers into the audit link (within 6 days of now).');

        return self::SUCCESS;
    }

    /**
     * Temporarily set a courier PricingConfig row to $value and return a closure
     * that restores the prior state (its old value, or deletes the row if we
     * created it). Lets the run use the audit windows without permanently
     * changing the live pickup/delivery configuration.
     *
     * @param  array<string, string>  $value
     */
    private function overrideCourierConfig(string $key, array $value): \Closure
    {
        $row = PricingConfig::query()
            ->where('group', CourierConfig::GROUP)
            ->where('key', $key)
            ->first();
        $original = $row?->value;
        $had = $row !== null;

        PricingConfig::updateOrCreate(
            ['group' => CourierConfig::GROUP, 'key' => $key],
            ['value' => $value],
        );

        return function () use ($key, $original, $had): void {
            if ($had) {
                PricingConfig::updateOrCreate(
                    ['group' => CourierConfig::GROUP, 'key' => $key],
                    ['value' => $original],
                );
            } else {
                PricingConfig::query()
                    ->where('group', CourierConfig::GROUP)
                    ->where('key', $key)
                    ->delete();
            }
        };
    }
}
