<?php

declare(strict_types=1);

namespace App\Services\Courier;

use App\Enums\Carrier;
use App\Exceptions\CourierException;
use App\Services\Courier\Contracts\CourierClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Live NinjaVan client: OAuth2 client-credentials token (cached via expires_in),
 * then a v4.1 create-order call. Body shape reconciled against NinjaVan's public
 * v4.1 Orders API. The merchant supplies requested_tracking_number (NinjaVan does
 * NOT generate it) - the caller passes it on the shipment and we return it back
 * as the tracking ref (no need to parse the response). delivery_start_date is
 * required and is supplied by the caller. A 401/403 on the order call forgets the
 * cached token, re-auths, and retries once (a server-rotated token can't wedge
 * dispatches until the TTL expires). NOTE (confirm on a live sandbox before
 * production): parcel_job.dimensions.weight and delivery_timeslot defaults come
 * from config and may need tuning to your account's service config.
 */
final class HttpNinjaVanClient implements CourierClient
{
    public function createShipment(CourierShipment $shipment): CourierShipmentResult
    {
        try {
            $base = rtrim((string) config('services.ninjavan.base_url'), '/');
            $token = $this->accessToken($base);

            $resp = $this->postOrder($base, $token, $shipment);

            // A cached token that NinjaVan rotated/revoked server-side reads as a
            // 401/403 here: drop it, re-auth, and retry the order once so a stale
            // token can't wedge every dispatch until the cache TTL expires.
            if (in_array($resp->status(), [401, 403], true)) {
                $token = $this->accessToken($base, forceRefresh: true);
                $resp = $this->postOrder($base, $token, $shipment);
            }

            if ($resp->failed()) {
                Log::error('NinjaVan create-order failed.', [
                    'reference' => $shipment->reference,
                    'status' => $resp->status(),
                ]);

                throw new CourierException('NinjaVan order failed: HTTP '.$resp->status());
            }

            // The merchant number we sent IS the tracking number - no need to
            // parse it back from the response.
            return new CourierShipmentResult($shipment->requestedTrackingNumber, Carrier::NinjaVan->value, $resp->json('label_url'));
        } catch (CourierException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new CourierException('NinjaVan request error: '.$e->getMessage(), previous: $e);
        }
    }

    private function postOrder(string $base, string $token, CourierShipment $shipment): \Illuminate\Http\Client\Response
    {
        $pickup = (array) config('services.ninjavan.pickup');

        return Http::withToken($token)
            ->connectTimeout(5)->timeout(20)
            ->retry(2, 500, function (Throwable $e): bool {
                // Retry only transient faults - never a 4xx like a rejected
                // address/400 (pointless and slow).
                return $e instanceof ConnectionException
                    || ($e instanceof RequestException && (bool) $e->response->serverError());
            }, throw: false)
            ->post($base.'/4.1/orders', [
                'service_type' => (string) config('services.ninjavan.service_type', 'Parcel'),
                'service_level' => (string) config('services.ninjavan.service_level', 'Standard'),
                'requested_tracking_number' => $shipment->requestedTrackingNumber,
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
                    'delivery_start_date' => $shipment->deliveryStartDate,
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
    }

    private function accessToken(string $base, bool $forceRefresh = false): string
    {
        // Cache the token until ~5 min before expiry (keyed by base + creds so a
        // credential change or a different account never reuses a stale token).
        $key = 'ninjavan:oauth:'.md5($base.'|'.(string) config('services.ninjavan.client_id').'|'.(string) config('services.ninjavan.client_secret'));

        // A 401/403 from the order call means the cached token is stale: forget it
        // so we re-auth below instead of handing back the wedged value.
        if ($forceRefresh) {
            Cache::forget($key);
        }

        $cached = Cache::get($key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

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

        $expiresIn = (int) ($resp->json('expires_in') ?? 3600);
        Cache::put($key, $token, now()->addSeconds(max(60, $expiresIn - 300)));

        return $token;
    }
}
