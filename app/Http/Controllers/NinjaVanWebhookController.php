<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\JobState;
use App\Events\OrderTrackingUpdated;
use App\Models\ProcessedWebhookEvent;
use App\Models\Shipment;
use App\Services\Courier\NinjaVanStatusMapper;
use App\Services\QueueService;
use App\Services\StaffNotifier;
use App\Support\Broadcasting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * NinjaVan inbound status webhook: NinjaVan is push-only (no poll API), so
 * this is the only path shipment status/delivery updates ever reach the app.
 * Unauthenticated route (external caller) but FAIL CLOSED on signature - an
 * unset webhook secret or a bad signature means 401 and NO state change,
 * mirroring StripeWebhookController's fail-closed pattern: this endpoint can
 * flip an order to Delivered, so forgery protection is mandatory.
 *
 * Once verified: look the SHIPMENT up by consignment_ref (NinjaVan's
 * authoritative tracking number, stored at booking time - see
 * NinjaVanTrackingNumber / ShipmentService). An unknown tracking number or a
 * shipment with no currently-SHIPPED member job is acknowledged with 200 and
 * logged rather than erroring, both because NinjaVan retries non-2xx responses
 * and because "no SHIPPED member" legitimately covers a duplicate delivered
 * event on an already-CLOSED job (idempotency) and an event that races ahead of
 * our own booking.
 *
 * Matching is prefix/suffix-tolerant, not a strict equality-only lookup:
 * ShipmentService normally stores NinjaVan's own canonical (possibly
 * account-prefixed) tracking_number, so the common case is an exact match.
 * But HttpNinjaVanClient's duplicate-order recovery path (a retry after a
 * booking that succeeded remotely but failed to persist locally) can only
 * fall back to the un-prefixed value we requested - NinjaVan doesn't hand
 * the canonical number back on that error path. Rather than add a second,
 * unconfirmed NinjaVan API call (an order-lookup-by-reference endpoint) to
 * resolve it up front, this webhook tolerates the mismatch on the read side:
 * a shipment whose stored consignment_ref is an exact match, or a trailing
 * substring, of the incoming tracking_number still resolves. That keeps the
 * fix confined to code this test suite can exercise directly (no sandbox
 * dependency) while guaranteeing every booked shipment - recovered or not -
 * can still be correlated when NinjaVan reports it delivered.
 *
 * The lookup->check->advance sequence for closing a job runs inside a
 * DB::transaction with lockForUpdate on the matched shipment row (mirrors
 * QuoteService::issueInvoice's TOCTOU guard): two Delivered events for the
 * same shipment serialize on the lock, and the second re-reads its member jobs
 * as CLOSED under the lock and no-ops instead of throwing
 * InvalidStateTransitionException.
 *
 * last_courier_status/_at is written under a monotonic guard: an incoming
 * event only overwrites it when the event's own timestamp is >= what's
 * already stored, so a stale/out-of-order retry (e.g. "In transit" arriving
 * after "Out for delivery") can't regress the public tracker. NinjaVan's
 * payload doesn't reliably carry a trustworthy timestamp field, so when one
 * is absent this falls back to "now" (received-order) - a real limitation:
 * two events with no timestamp are compared by arrival order only.
 */
class NinjaVanWebhookController extends Controller
{
    public function handle(Request $request, QueueService $queue, StaffNotifier $staffNotifier): JsonResponse
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

        // L10/L11: event-level idempotency + replay guard. NinjaVan carries no
        // reliable event id, so the key is a hash of the (already signature-
        // verified) body: a duplicate retry or a replayed capture hashes the
        // same and is skipped, so returned/failed events don't re-fire staff
        // alerts or re-broadcast, and a captured body can't be replayed to
        // repeat side effects. Recorded only after successful handling below, so
        // a genuine failure still gets retried.
        $eventKey = hash('sha256', $body);
        if (ProcessedWebhookEvent::processed('ninjavan', $eventKey)) {
            Log::info('NinjaVan webhook duplicate/replay ignored.');

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

        $shipment = $this->findShipmentForTrackingNumber($trackingNumber);

        if ($shipment === null) {
            Log::info('NinjaVan webhook for unknown tracking number.', [
                'tracking_number' => $trackingNumber,
                'status' => $rawStatus,
            ]);

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

        // Lock the shipment row for the duration of the check-then-advance so
        // two concurrent Delivered events for the same shipment (courier webhook
        // senders commonly retry) can't both observe a SHIPPED member job and
        // both attempt the SHIPPED->CLOSED transition - the second blocks here
        // until the first commits, then re-reads the now-CLOSED member jobs
        // under the lock and takes the "no SHIPPED member" no-op path below
        // instead of throwing InvalidStateTransitionException (mirrors
        // QuoteService::issueInvoice's lockForUpdate TOCTOU guard).
        // Set inside the transaction below when a needsAttention status
        // (returned/attempt-failed) actually gets processed - read afterwards,
        // once the write is safely committed, to decide whether to fire the
        // staff alert.
        $needsAttentionAlert = false;

        $outcome = DB::transaction(function () use ($shipment, $mapping, $statusAt, $queue, &$needsAttentionAlert): string {
            $lockedShipment = Shipment::query()->whereKey($shipment->getKey())->lockForUpdate()->firstOrFail();

            // Close each member job that is still SHIPPED. In Stage 2a a
            // shipment has exactly one job (1:1), so this is a loop of one -
            // behaviour identical - but writing it as a loop makes Phase 2b
            // (one shipment, many jobs) a no-op here. Locked per row to keep
            // the per-job TOCTOU guard.
            $shippedJobs = $lockedShipment->jobs()
                ->where('state', JobState::Shipped->value)
                ->lockForUpdate()
                ->get();

            // No SHIPPED member: either the shipment never shipped, or a
            // duplicate/late delivered webhook for one already CLOSED - either
            // way, never regress production state and never touch the status.
            if ($shippedJobs->isEmpty()) {
                return 'ignored';
            }

            // Monotonic guard: only overwrite last_courier_status/_at when
            // this event is at least as new as what's already stored, so a
            // stale/out-of-order event (e.g. a retried "In transit" arriving
            // after "Out for delivery") can't regress the public tracker.
            if ($lockedShipment->last_courier_status_at === null || $statusAt->greaterThanOrEqualTo($lockedShipment->last_courier_status_at)) {
                $lockedShipment->last_courier_status = $mapping->label;
                $lockedShipment->last_courier_status_at = $statusAt;
            }

            if ($mapping->deliver) {
                $lockedShipment->delivered_at = $statusAt;
                $lockedShipment->save();

                // advance() drives each member job's quote close +
                // OrderMilestone + broadcast. The courier footprint already
                // committed on the shipment above.
                foreach ($shippedJobs as $memberJob) {
                    $queue->advance($memberJob, JobState::Closed);
                }

                return 'closed';
            }

            $lockedShipment->save();

            // Returned/attempt-failed leaves the member job(s) stuck SHIPPED
            // with no JobState of their own - flag it for the staff alert fired
            // below, once this transaction has safely committed.
            if ($mapping->needsAttention) {
                $needsAttentionAlert = true;
            }

            return 'updated';
        });

        // Shipment's member job(s) stay SHIPPED for an intermediate status
        // (out-for-delivery, returned, etc.) - QueueService::advance's own
        // broadcast only fires on a state transition (handled inside the
        // transaction above for the 'closed' outcome), so without this the
        // public tracker would sit stale until the job is later closed. Mirrors
        // the QuoteStateChanged listener's fire-and-forget broadcast pattern.
        if ($outcome === 'updated') {
            $shipment->loadMissing('quote');
            if ($shipment->quote !== null) {
                Broadcasting::dispatch(fn () => OrderTrackingUpdated::dispatch($shipment->quote));
            }
        }

        // Fired after the transaction commits (never inside it): staff must
        // never be alerted about a write that could still roll back.
        // StaffNotifier::parcelReturned() never throws, so no try/catch is
        // needed at this call site (mirrors StaffNotifier::proofChangesRequested's
        // call sites). Pass a representative still-SHIPPED member job (1:1 today).
        if ($needsAttentionAlert) {
            $representativeJob = $shipment->jobs()->where('state', JobState::Shipped->value)->first();
            if ($representativeJob !== null) {
                $staffNotifier->parcelReturned($representativeJob);
            }
        }

        // Record only now that side effects have committed, so a duplicate/replay
        // of this exact body is recognised and skipped next time (L10/L11).
        ProcessedWebhookEvent::record('ninjavan', $eventKey);

        return response()->json(['received' => true]);
    }

    /**
     * Resolve the shipment a webhook event belongs to. Exact match on
     * consignment_ref is the common/fast path (indexed, unique). When that
     * misses, fall back to a prefix/suffix-tolerant match: a shipment recovered
     * via HttpNinjaVanClient's duplicate-order path may have persisted the
     * un-prefixed requested_tracking_number instead of NinjaVan's canonical
     * one, so the webhook's tracking_number can legitimately just be that
     * stored value with NinjaVan's own prefix in front of it. The fallback
     * scan is bounded to shipments with at least one SHIPPED member job - only
     * a shipment still awaiting a courier event can possibly be the match - so
     * it stays small regardless of how many historical shipments the table
     * holds.
     */
    private function findShipmentForTrackingNumber(string $trackingNumber): ?Shipment
    {
        $shipment = Shipment::query()->where('consignment_ref', $trackingNumber)->first();
        if ($shipment !== null) {
            return $shipment;
        }

        $candidates = Shipment::query()
            ->whereNotNull('consignment_ref')
            ->where('consignment_ref', '!=', '')
            ->whereHas('jobs', fn ($q) => $q->where('state', JobState::Shipped->value))
            ->get()
            ->filter(fn (Shipment $candidate): bool => str_ends_with($trackingNumber, (string) $candidate->consignment_ref));

        // Only accept an UNAMBIGUOUS suffix match. If two shipments' refs are
        // both trailing substrings of the incoming number (e.g. "GL1" and
        // "XGL1"), the unordered ->first() used to pick either - risking marking
        // the wrong parcel delivered/returned. When it's ambiguous, treat the
        // event as unknown (the caller acks without acting) rather than guess. M14.
        return $candidates->count() === 1 ? $candidates->first() : null;
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

    /**
     * Normalises to UTC (not just parses) because Eloquent's datetime cast
     * does not convert a Carbon instance's offset before storing it - it
     * formats whatever wall-clock/offset the instance currently holds, so a
     * '+08:00' timestamp saved as-is round-trips back as that same wall-clock
     * time misread as UTC (8 hours off). Converting here, before the value is
     * ever compared or persisted, keeps every stored/compared instant
     * unambiguous regardless of what offset NinjaVan sends.
     */
    private function parseEventTime(?string $eventTime): Carbon
    {
        if ($eventTime === null) {
            return now();
        }

        try {
            return Carbon::parse($eventTime)->utc();
        } catch (\Throwable) {
            return now();
        }
    }
}
