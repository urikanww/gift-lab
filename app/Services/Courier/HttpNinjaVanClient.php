<?php

declare(strict_types=1);

namespace App\Services\Courier;

use App\Enums\Carrier;
use App\Exceptions\CourierException;
use App\Services\Courier\Contracts\CourierClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Live NinjaVan client: OAuth2 client-credentials token, then a v4.2 create-order
 * call. Body shape reconciled against NinjaVan's public v4.2 Orders API docs
 * (api-docs.ninjavan.co). NOTE (confirm on a live sandbox before production):
 * parcel_job.dimensions.weight, delivery_start_date and delivery_timeslot are
 * required by the Orders API - defaults come from config and may need tuning to
 * your account's service config; and the response tracking field name has varied
 * by version (tracking_number / requested_tracking_number / tracking_id) so we
 * read all three.
 */
final class HttpNinjaVanClient implements CourierClient
{
    public function createShipment(CourierShipment $shipment): CourierShipmentResult
    {
        try {
            $base = rtrim((string) config('services.ninjavan.base_url'), '/');
            $token = $this->accessToken($base);
            $pickup = (array) config('services.ninjavan.pickup');

            $resp = Http::withToken($token)
                ->connectTimeout(5)->timeout(20)
                ->retry(2, 500, function (Throwable $e): bool {
                    // Retry only transient faults - never a 4xx like a rejected
                    // address/400 (pointless and slow).
                    return $e instanceof ConnectionException
                        || ($e instanceof RequestException && (bool) $e->response->serverError());
                }, throw: false)
                ->post($base.'/4.2/orders', [
                    'service_type' => (string) config('services.ninjavan.service_type', 'Parcel'),
                    'service_level' => (string) config('services.ninjavan.service_level', 'Standard'),
                    'reference' => ['merchant_order_number' => $shipment->reference],
                    'from' => [
                        'name' => $pickup['name'] ?? '',
                        'phone_number' => $pickup['phone'] ?? '',
                        'email' => $pickup['email'] ?? '',
                        'address' => [
                            'address1' => $pickup['address1'] ?? '',
                            'city' => $pickup['city'] ?? '',
                            'state' => $pickup['state'] ?? '',
                            'country' => $pickup['country'] ?? 'SG',
                            'postcode' => $pickup['postcode'] ?? '',
                        ],
                    ],
                    'to' => [
                        'name' => $shipment->recipientName,
                        'phone_number' => $shipment->phone,
                        'email' => $shipment->email,
                        'address' => [
                            'address1' => $shipment->line1,
                            'address2' => $shipment->line2,
                            'city' => $shipment->city,
                            'state' => $shipment->state,
                            'country' => $shipment->country,
                            'postcode' => $shipment->postalCode,
                        ],
                    ],
                    'parcel_job' => [
                        'is_pickup_required' => true,
                        'delivery_start_date' => now()->toDateString(),
                        'delivery_timeslot' => [
                            'start_time' => (string) config('services.ninjavan.timeslot_start', '09:00'),
                            'end_time' => (string) config('services.ninjavan.timeslot_end', '18:00'),
                            'timezone' => (string) config('services.ninjavan.timezone', 'Asia/Singapore'),
                        ],
                        'dimensions' => [
                            'weight' => (float) config('services.ninjavan.default_weight_kg', 1),
                        ],
                        'delivery_instructions' => $shipment->notes,
                    ],
                ]);

            if ($resp->failed()) {
                Log::error('NinjaVan create-order failed.', [
                    'reference' => $shipment->reference,
                    'status' => $resp->status(),
                ]);

                throw new CourierException('NinjaVan order failed: HTTP '.$resp->status());
            }

            $tracking = (string) (
                $resp->json('tracking_number')
                ?? $resp->json('requested_tracking_number')
                ?? $resp->json('tracking_id')
                ?? ''
            );
            if ($tracking === '') {
                Log::error('NinjaVan create-order returned no tracking number.', [
                    'reference' => $shipment->reference,
                    'status' => $resp->status(),
                ]);

                throw new CourierException('NinjaVan returned no tracking number.');
            }

            return new CourierShipmentResult($tracking, Carrier::NinjaVan->value, $resp->json('label_url'));
        } catch (CourierException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new CourierException('NinjaVan request error: '.$e->getMessage(), previous: $e);
        }
    }

    private function accessToken(string $base): string
    {
        // Fetched fresh per dispatch (one call per staff ship action), so the
        // token is intentionally NOT cached despite NinjaVan's caching advice -
        // the extra round trip is negligible at this volume.
        $resp = Http::connectTimeout(5)->timeout(20)
            ->retry(2, 500, function (Throwable $e): bool {
                return $e instanceof ConnectionException
                    || ($e instanceof RequestException && (bool) $e->response->serverError());
            }, throw: false)
            ->post($base.'/2.0/oauth/access_token', [
                'client_id' => config('services.ninjavan.client_id'),
                'client_secret' => config('services.ninjavan.client_secret'),
                'grant_type' => 'client_credentials',
            ]);

        if ($resp->failed()) {
            Log::error('NinjaVan auth failed.', ['status' => $resp->status()]);

            throw new CourierException('NinjaVan auth failed: HTTP '.$resp->status());
        }

        $token = (string) $resp->json('access_token');
        if ($token === '') {
            Log::error('NinjaVan auth returned no access token.', ['status' => $resp->status()]);

            throw new CourierException('NinjaVan auth failed.');
        }

        return $token;
    }
}
