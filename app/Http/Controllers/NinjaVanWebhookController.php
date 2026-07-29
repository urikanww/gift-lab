<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\JobState;
use App\Events\OrderTrackingUpdated;
use App\Models\ProcessedWebhookEvent;
use App\Models\ProductionJob;
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
 * Once verified: look the job up by consignment_ref (NinjaVan's authoritative
 * tracking number, stored at booking time - see NinjaVanTrackingNumber /
 * ShipmentService). An unknown tracking number or a job not currently SHIPPED
 * is acknowledged with 200 and logged rather than erroring, both because
 * NinjaVan retries non-2xx responses and because "not SHIPPED" legitimately
 * covers a duplicate delivered event on an already-CLOSED job (idempotency)
 * and an event that races ahead of our own booking.
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
 * a job whose stored consignment_ref is an exact match, or a trailing
 * substring, of the incoming tracking_number still resolves. That keeps the
 * fix confined to code this test suite can exercise directly (no sandbox
 * dependency) while guaranteeing every booked job - recovered or not - can
 * still be correlated when NinjaVan reports it delivered.
 *
 * The lookup->check->advance sequence for closing a job runs inside a
 * DB::transaction with lockForUpdate on the matched row (mirrors
 * QuoteService::issueInvoice's TOCTOU guard): two Delivered events for the
 * same job serialize on the lock, and the second re-reads CLOSED under the
 * lock and no-ops instead of throwing InvalidStateTransitionException.
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

        $job = $this->findJobForTrackingNumber($trackingNumber);

        if ($job === null) {
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

        // Lock the job row for the duration of the check-then-advance so two
        // concurrent Delivered events for the same job (courier webhook
        // senders commonly retry) can't both observe SHIPPED and both attempt
        // the SHIPPED->CLOSED transition - the second blocks here until the
        // first commits, then re-reads the now-CLOSED row under the lock and
        // takes the "not SHIPPED" no-op path below instead of throwing
        // InvalidStateTransitionException (mirrors QuoteService::issueInvoice's
        // lockForUpdate TOCTOU guard).
        // Set inside the transaction below when a SHIPPED job actually gets
        // processed under a needsAttention status (returned/attempt-failed) -
        // read afterwards, once the write is safely committed, to decide
        // whether to fire the staff alert.
        $needsAttentionAlert = false;

        $outcome = DB::transaction(function () use ($job, $mapping, $statusAt, $queue, &$needsAttentionAlert): string {
            $locked = ProductionJob::query()->whereKey($job->getKey())->lockForUpdate()->firstOrFail();

            // Only an already-SHIPPED job may move: a job that hasn't shipped
            // yet has no business receiving courier events, and a job already
            // CLOSED means this is a duplicate/late delivered webhook - either
            // way, never regress production state.
            if ($locked->state !== JobState::Shipped) {
                return 'ignored';
            }

            // Monotonic guard: only overwrite last_courier_status/_at when
            // this event is at least as new as what's already stored, so a
            // stale/out-of-order event (e.g. a retried "In transit" arriving
            // after "Out for delivery") can't regress the public tracker.
            if ($locked->last_courier_status_at === null || $statusAt->greaterThanOrEqualTo($locked->last_courier_status_at)) {
                $locked->last_courier_status = $mapping->label;
                $locked->last_courier_status_at = $statusAt;
            }

            if ($mapping->deliver) {
                $locked->delivered_at = $statusAt;
                // advance() saves the model (state + whatever's dirty, i.e.
                // last_courier_status/_at and delivered_at above) in one go,
                // then drives the quote close + OrderMilestone + broadcast.
                $queue->advance($locked, JobState::Closed);

                return 'closed';
            }

            $locked->save();

            // Returned/attempt-failed leaves the job stuck SHIPPED with no
            // JobState of its own - flag it for the staff alert fired below,
            // once this transaction has safely committed.
            if ($mapping->needsAttention) {
                $needsAttentionAlert = true;
            }

            return 'updated';
        });

        // Job stays SHIPPED for an intermediate status (out-for-delivery,
        // returned, etc.) - QueueService::advance's own broadcast only fires
        // on a state transition (handled inside the transaction above for the
        // 'closed' outcome), so without this the public tracker would sit
        // stale until the job is later closed. Mirrors the QuoteStateChanged
        // listener's fire-and-forget broadcast pattern.
        if ($outcome === 'updated') {
            $job->loadMissing('quote');
            if ($job->quote !== null) {
                Broadcasting::dispatch(fn () => OrderTrackingUpdated::dispatch($job->quote));
            }
        }

        // Fired after the transaction commits (never inside it): staff must
        // never be alerted about a write that could still roll back.
        // StaffNotifier::parcelReturned() never throws, so no try/catch is
        // needed at this call site (mirrors StaffNotifier::proofChangesRequested's
        // call sites).
        if ($needsAttentionAlert) {
            $staffNotifier->parcelReturned($job->fresh() ?? $job);
        }

        // Record only now that side effects have committed, so a duplicate/replay
        // of this exact body is recognised and skipped next time (L10/L11).
        ProcessedWebhookEvent::record('ninjavan', $eventKey);

        return response()->json(['received' => true]);
    }

    /**
     * Resolve the job a webhook event belongs to. Exact match on
     * consignment_ref is the common/fast path (indexed, unique). When that
     * misses, fall back to a prefix/suffix-tolerant match: a job recovered
     * via HttpNinjaVanClient's duplicate-order path may have persisted the
     * un-prefixed requested_tracking_number instead of NinjaVan's canonical
     * one, so the webhook's tracking_number can legitimately just be that
     * stored value with NinjaVan's own prefix in front of it. The fallback
     * scan is bounded to SHIPPED jobs - only a job still awaiting a courier
     * event can possibly be the match - so it stays small regardless of how
     * many historical jobs the table holds.
     */
    private function findJobForTrackingNumber(string $trackingNumber): ?ProductionJob
    {
        $job = ProductionJob::query()->where('consignment_ref', $trackingNumber)->first();
        if ($job !== null) {
            return $job;
        }

        $candidates = ProductionJob::query()
            ->where('state', JobState::Shipped->value)
            ->whereNotNull('consignment_ref')
            ->where('consignment_ref', '!=', '')
            ->get()
            ->filter(fn (ProductionJob $candidate): bool => str_ends_with($trackingNumber, (string) $candidate->consignment_ref));

        // Only accept an UNAMBIGUOUS suffix match. If two shipped jobs' refs are
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
