<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\JobState;
use App\Events\OrderTrackingUpdated;
use App\Models\ProductionJob;
use App\Services\Courier\NinjaVanStatusMapper;
use App\Services\QueueService;
use App\Support\Broadcasting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * NinjaVan inbound status webhook: NinjaVan is push-only (no poll API), so
 * this is the only path shipment status/delivery updates ever reach the app.
 * Unauthenticated route (external caller) but FAIL CLOSED on signature - an
 * unset webhook secret or a bad signature means 401 and NO state change,
 * mirroring StripeWebhookController's fail-closed pattern: this endpoint can
 * flip an order to Delivered, so forgery protection is mandatory.
 *
 * Once verified: look the job up by consignment_ref (NinjaVan's authoritative
 * tracking number, stored at booking time - see NinjaVanTrackingNumber /
 * ShipmentService). An unknown tracking number or a job not currently SHIPPED
 * is acknowledged with 200 and logged rather than erroring, both because
 * NinjaVan retries non-2xx responses and because "not SHIPPED" legitimately
 * covers a duplicate delivered event on an already-CLOSED job (idempotency)
 * and an event that races ahead of our own booking.
 */
class NinjaVanWebhookController extends Controller
{
    public function handle(Request $request, QueueService $queue): JsonResponse
    {
        $secret = (string) config('services.ninjavan.webhook_secret');

        if ($secret === '') {
            Log::warning('NinjaVan webhook received but NINJAVAN_WEBHOOK_SECRET is not configured; rejecting.');

            return response()->json(['message' => 'Webhook not configured.'], 401);
        }

        $headerName = (string) config('services.ninjavan.webhook_signature_header', 'X-Ninja-Hmac');
        $signature = (string) $request->header($headerName);
        $body = $request->getContent();

        if ($signature === '' || ! $this->signatureValid($body, $signature, $secret)) {
            Log::warning('NinjaVan webhook signature verification failed.', ['header' => $headerName]);

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $payload = json_decode($body, true);
        if (! is_array($payload)) {
            Log::warning('NinjaVan webhook payload is not valid JSON.');

            return response()->json(['received' => true]);
        }

        // NinjaVan's field names vary by API version/docs - tolerate the
        // common aliases rather than binding to one exact shape.
        $trackingNumber = $this->firstString($payload, ['tracking_number', 'tracking_id']);
        $rawStatus = $this->firstString($payload, ['status', 'shipper_order_status', 'shipment_status']);
        $eventTime = $this->firstString($payload, ['timestamp', 'event_time', 'updated_at', 'status_time']);

        if ($trackingNumber === null || $rawStatus === null) {
            Log::warning('NinjaVan webhook payload missing tracking number or status.', ['payload' => $payload]);

            return response()->json(['received' => true]);
        }

        $job = ProductionJob::query()->where('consignment_ref', $trackingNumber)->first();

        if ($job === null) {
            Log::info('NinjaVan webhook for unknown tracking number.', [
                'tracking_number' => $trackingNumber,
                'status' => $rawStatus,
            ]);

            return response()->json(['received' => true]);
        }

        // Only an already-SHIPPED job may move: a job that hasn't shipped yet
        // has no business receiving courier events, and a job already CLOSED
        // means this is a duplicate/late delivered webhook - either way,
        // never regress production state.
        if ($job->state !== JobState::Shipped) {
            return response()->json(['received' => true]);
        }

        $mapping = NinjaVanStatusMapper::map($rawStatus);

        if (! $mapping->known) {
            Log::warning('NinjaVan webhook: unrecognised courier status.', [
                'tracking_number' => $trackingNumber,
                'status' => $rawStatus,
            ]);
        }

        $statusAt = $this->parseEventTime($eventTime);

        $job->last_courier_status = $mapping->label;
        $job->last_courier_status_at = $statusAt;

        if ($mapping->deliver) {
            // Idempotency guard: canTransitionTo is false once the job is no
            // longer SHIPPED, but we've already checked that above - this
            // second check is the belt-and-braces one the spec calls for, so
            // a duplicate Delivered event in the same request window (e.g. a
            // stale in-memory $job) still can't double-close.
            if ($job->state->canTransitionTo(JobState::Closed)) {
                $job->delivered_at = $statusAt;
                // advance() saves the model (state + whatever's dirty, i.e.
                // last_courier_status/_at and delivered_at above) in one go,
                // then drives the quote close + OrderMilestone + broadcast.
                $queue->advance($job, JobState::Closed);
            }

            return response()->json(['received' => true]);
        }

        $job->save();

        // Job stays SHIPPED for an intermediate status (out-for-delivery,
        // returned, etc.) - QueueService::advance's own broadcast only fires
        // on a state transition, so without this the public tracker would sit
        // stale until the job is later closed. Mirrors the QuoteStateChanged
        // listener's fire-and-forget broadcast pattern.
        $job->loadMissing('quote');
        if ($job->quote !== null) {
            Broadcasting::dispatch(fn () => OrderTrackingUpdated::dispatch($job->quote));
        }

        return response()->json(['received' => true]);
    }

    private function signatureValid(string $body, string $signature, string $secret): bool
    {
        $expected = hash_hmac('sha256', $body, $secret);

        return hash_equals($expected, strtolower(trim($signature)));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $keys
     */
    private function firstString(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function parseEventTime(?string $eventTime): Carbon
    {
        if ($eventTime === null) {
            return now();
        }

        try {
            return Carbon::parse($eventTime);
        } catch (\Throwable) {
            return now();
        }
    }
}
