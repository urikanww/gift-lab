<?php

declare(strict_types=1);

use App\Enums\Carrier;
use App\Enums\JobState;
use App\Enums\QuoteState;
use App\Events\OrderTrackingUpdated;
use App\Events\ParcelReturned;
use App\Mail\ParcelReturnedMail;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\ProductionJob;
use App\Models\Proof;
use App\Models\Quote;
use App\Models\ShippingAddress;
use App\Models\User;
use App\Services\QueueService;
use App\Services\ShipmentService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

/**
 * NinjaVan is push-only (no poll API) - this webhook is the only path
 * shipment status/delivery updates ever reach the app. Every request below
 * is a crafted payload + a locally-computed HMAC; nothing hits the NinjaVan
 * sandbox.
 */
const NINJAVAN_TEST_WEBHOOK_SECRET = 'test-ninjavan-webhook-secret';

beforeEach(function (): void {
    config()->set('services.ninjavan.webhook_secret', NINJAVAN_TEST_WEBHOOK_SECRET);
    config()->set('services.ninjavan.webhook_signature_header', 'X-Ninja-Hmac');

    $this->company = Company::factory()->create();
    $this->buyer = User::factory()->create([
        'company_id' => $this->company->id,
        'role' => 'buyer',
    ]);
});

/**
 * A job carried all the way to SHIPPED with a real (NinjaVan-prefixed)
 * consignment ref, matching how outbound booking now stores it.
 */
function ninjaVanShippedJob(string $consignmentRef = 'NVSGNEXGE000GLLEU2'): ProductionJob
{
    $product = Product::factory()->create(['class' => 'CORE', 'print_method' => 'UV']);
    $quote = Quote::factory()->create([
        'company_id' => test()->company->id,
        'state' => 'PROCURING',
        'created_by' => test()->buyer->id,
    ]);
    $line = LineItem::factory()->ready()->create([
        'quote_id' => $quote->id,
        'product_id' => $product->id,
        'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/core.png'],
    ]);
    Proof::factory()->forLine($line)->approved()->create();

    $queue = app(QueueService::class);
    $job = $queue->buildJobsForQuote($quote->load('lineItems.product'))->first();
    $queue->advance($job, JobState::InProduction);

    return $queue->advance($job, JobState::Shipped, $consignmentRef, Carrier::NinjaVan);
}

/**
 * Posts a NinjaVan webhook payload with a correctly-computed HMAC-SHA256
 * signature (hex digest) over the exact raw body sent, unless a signature
 * override or a different signing secret is supplied (for the bad-signature
 * test).
 */
function postNinjaVanWebhook(array $payload, ?string $signatureOverride = null, ?string $signWith = null): TestResponse
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $signature = $signatureOverride ?? hash_hmac('sha256', $body, $signWith ?? NINJAVAN_TEST_WEBHOOK_SECRET);

    return test()->call(
        'POST',
        '/api/ninjavan/webhook',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_X_NINJA_HMAC' => $signature],
        $body,
    );
}

it('closes the job and quote on a valid Delivered event, and is a no-op on a repeat', function (): void {
    $job = ninjaVanShippedJob('NVSGNEXGE000GLLEU2');

    postNinjaVanWebhook([
        'tracking_number' => 'NVSGNEXGE000GLLEU2',
        'status' => 'Delivered',
        'timestamp' => '2026-07-27T10:00:00+08:00',
    ])->assertOk()->assertJson(['received' => true]);

    $job->refresh();
    expect($job->state)->toBe(JobState::Closed)
        ->and($job->delivered_at)->not->toBeNull()
        ->and($job->last_courier_status)->toBe('Delivered');

    $quote = $job->quote()->first();
    expect($quote->state)->toBe(QuoteState::Closed)
        ->and($quote->trackingStage())->toBe('DELIVERED');

    // Second delivered event for the same (now CLOSED) job: still 200, no
    // error, and the job does not attempt (or fail) a second close.
    postNinjaVanWebhook([
        'tracking_number' => 'NVSGNEXGE000GLLEU2',
        'status' => 'Delivered',
        'timestamp' => '2026-07-27T11:00:00+08:00',
    ])->assertOk()->assertJson(['received' => true]);

    $job->refresh();
    expect($job->state)->toBe(JobState::Closed);
});

it('records Out for Delivery without closing the job', function (): void {
    $job = ninjaVanShippedJob('NVSGNEXGE000OFD001');

    postNinjaVanWebhook([
        'tracking_number' => 'NVSGNEXGE000OFD001',
        'status' => 'Out for Delivery',
    ])->assertOk();

    $job->refresh();
    expect($job->state)->toBe(JobState::Shipped)
        ->and($job->last_courier_status)->toBe('Out for delivery')
        ->and($job->last_courier_status_at)->not->toBeNull()
        ->and($job->delivered_at)->toBeNull();
});

it('fires OrderTrackingUpdated for the job\'s quote on an intermediate status change', function (): void {
    Event::fake([OrderTrackingUpdated::class]);

    $job = ninjaVanShippedJob('NVSGNEXGE000EVT001');
    $quoteId = $job->quote_id;

    postNinjaVanWebhook([
        'tracking_number' => 'NVSGNEXGE000EVT001',
        'status' => 'Out for Delivery',
    ])->assertOk();

    Event::assertDispatched(
        OrderTrackingUpdated::class,
        fn (OrderTrackingUpdated $event): bool => $event->quote->id === $quoteId,
    );
});

it('flags Returned to Sender for staff without regressing job state', function (): void {
    $job = ninjaVanShippedJob('NVSGNEXGE000RET001');

    postNinjaVanWebhook([
        'tracking_number' => 'NVSGNEXGE000RET001',
        'status' => 'Returned to Sender',
    ])->assertOk();

    $job->refresh();
    expect($job->state)->toBe(JobState::Shipped)
        ->and($job->last_courier_status)->toBe('Delivery unsuccessful — returned');
});

it('rejects an invalid signature with no state change', function (): void {
    $job = ninjaVanShippedJob('NVSGNEXGE000BAD001');

    postNinjaVanWebhook(
        ['tracking_number' => 'NVSGNEXGE000BAD001', 'status' => 'Delivered'],
        signatureOverride: 'not-the-real-signature',
    )->assertStatus(401);

    $job->refresh();
    expect($job->state)->toBe(JobState::Shipped)
        ->and($job->last_courier_status)->toBeNull()
        ->and($job->delivered_at)->toBeNull();
});

it('rejects a signature computed with the wrong secret', function (): void {
    $job = ninjaVanShippedJob('NVSGNEXGE000BAD002');

    postNinjaVanWebhook(
        ['tracking_number' => 'NVSGNEXGE000BAD002', 'status' => 'Delivered'],
        signWith: 'some-other-secret',
    )->assertStatus(401);

    $job->refresh();
    expect($job->state)->toBe(JobState::Shipped);
});

it('fails closed when the webhook secret is not configured', function (): void {
    config()->set('services.ninjavan.webhook_secret', null);
    $job = ninjaVanShippedJob('NVSGNEXGE000NOSEC1');

    postNinjaVanWebhook([
        'tracking_number' => 'NVSGNEXGE000NOSEC1',
        'status' => 'Delivered',
    ])->assertStatus(401);

    $job->refresh();
    expect($job->state)->toBe(JobState::Shipped);
});

it('acks an unknown tracking number with no match and no crash', function (): void {
    postNinjaVanWebhook([
        'tracking_number' => 'NVSGNEXGE000NOPE99',
        'status' => 'Delivered',
    ])->assertOk()->assertJson(['received' => true]);

    // Nothing to assert on a job (none matched) - the important behaviour is
    // that this ack'd 200 rather than erroring, so NinjaVan doesn't retry.
});

it('stores an unrecognised status string verbatim without crashing', function (): void {
    $job = ninjaVanShippedJob('NVSGNEXGE000UNK001');

    postNinjaVanWebhook([
        'tracking_number' => 'NVSGNEXGE000UNK001',
        'status' => 'Some Brand New Courier Status',
    ])->assertOk()->assertJson(['received' => true]);

    $job->refresh();
    expect($job->state)->toBe(JobState::Shipped)
        ->and($job->last_courier_status)->toBe('Some Brand New Courier Status');
});

it('books a distinct consignment_ref per job on a multi-job quote, and two Delivered webhooks close both jobs then the quote', function (): void {
    // One CORE (UV-track) line + one MODEL_3D line -> QueueService::buildJobsForQuote
    // makes two jobs for this quote (one UV job, one per 3D line). Each must book
    // under its own NinjaVan requested_tracking_number (NinjaVanTrackingNumber::forJob),
    // or the second booking collides with the first and only one job ever closes.
    $uvProduct = Product::factory()->create(['class' => 'CORE', 'print_method' => 'UV']);
    $threeDProduct = Product::factory()->create(['class' => 'MODEL_3D', 'print_method' => 'FDM']);
    $quote = Quote::factory()->create([
        'company_id' => $this->company->id,
        'state' => 'PROCURING',
        'created_by' => $this->buyer->id,
    ]);
    $uvLine = LineItem::factory()->ready()->create([
        'quote_id' => $quote->id,
        'product_id' => $uvProduct->id,
        'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/core.png'],
    ]);
    Proof::factory()->forLine($uvLine)->approved()->create();
    LineItem::factory()->ready()->create([
        'quote_id' => $quote->id,
        'product_id' => $threeDProduct->id,
        'customization' => ['mode' => 'designer', 'print_file_ref' => 'artwork/decal.png'],
    ]);
    ShippingAddress::create([
        'quote_id' => $quote->id, 'recipient_name' => 'Rachel Tan',
        'phone' => '+6591234567', 'line1' => '1 Marina Blvd',
        'postal_code' => '018989', 'country' => 'SG',
    ]);

    $queue = app(QueueService::class);
    $jobs = $queue->buildJobsForQuote($quote->load('lineItems.product'));
    expect($jobs)->toHaveCount(2);

    $shipmentService = app(ShipmentService::class);
    $shippedJobs = $jobs->map(function (ProductionJob $job) use ($queue, $shipmentService): ProductionJob {
        $queue->advance($job, JobState::InProduction);

        return $shipmentService->createForJob($job->fresh());
    });

    $refs = $shippedJobs->pluck('consignment_ref');
    expect($refs->filter())->toHaveCount(2)
        ->and($refs->unique())->toHaveCount(2);

    foreach ($shippedJobs as $job) {
        postNinjaVanWebhook([
            'tracking_number' => $job->consignment_ref,
            'status' => 'Delivered',
        ])->assertOk()->assertJson(['received' => true]);
    }

    foreach ($shippedJobs as $job) {
        expect($job->fresh()->state)->toBe(JobState::Closed);
    }

    expect($quote->fresh()->state)->toBe(QuoteState::Closed);
});

it('matches a recovered (un-prefixed) consignment_ref against NinjaVan\'s prefixed webhook tracking_number', function (): void {
    // Simulates ShipmentService::createForJob having recovered via
    // HttpNinjaVanClient's duplicate-order path, which (until NinjaVan's
    // canonical number can be resolved) persists the un-prefixed value we
    // requested rather than NinjaVan's own prefixed tracking_number. The
    // webhook must still match: NinjaVan's tracking_number ends with the
    // exact value we stored as consignment_ref.
    $job = ninjaVanShippedJob('GLJOB01');

    postNinjaVanWebhook([
        'tracking_number' => 'NVSGGLJOB01',
        'status' => 'Delivered',
    ])->assertOk()->assertJson(['received' => true]);

    $job->refresh();
    expect($job->state)->toBe(JobState::Closed)
        ->and($job->last_courier_status)->toBe('Delivered');
});

it('locks the job row so two concurrent Delivered events cannot both close it or double the audit trail', function (): void {
    $job = ninjaVanShippedJob('NVSGNEXGE000LOCK01');

    // Simulated concurrency: the handler is invoked twice in sequence. The
    // lockForUpdate + re-read-under-lock means the second call sees the job
    // already CLOSED and becomes a clean no-op instead of throwing
    // InvalidStateTransitionException (which would otherwise surface as a 500).
    postNinjaVanWebhook([
        'tracking_number' => 'NVSGNEXGE000LOCK01',
        'status' => 'Delivered',
        'timestamp' => '2026-07-27T10:00:00+08:00',
    ])->assertOk()->assertJson(['received' => true]);

    postNinjaVanWebhook([
        'tracking_number' => 'NVSGNEXGE000LOCK01',
        'status' => 'Delivered',
        'timestamp' => '2026-07-27T10:00:01+08:00',
    ])->assertOk()->assertJson(['received' => true]);

    $job->refresh();
    expect($job->state)->toBe(JobState::Closed);

    $closeAudits = AuditLog::query()
        ->where('auditable_type', ProductionJob::class)
        ->where('auditable_id', $job->id)
        ->where('event', 'production_job.advanced')
        ->get()
        ->filter(fn (AuditLog $log): bool => ($log->new_values['state'] ?? null) === JobState::Closed->value);

    expect($closeAudits)->toHaveCount(1);
});

it('does not regress last_courier_status when an older event arrives after a newer one', function (): void {
    $job = ninjaVanShippedJob('NVSGNEXGE000ORD001');

    postNinjaVanWebhook([
        'tracking_number' => 'NVSGNEXGE000ORD001',
        'status' => 'Out for Delivery',
        'timestamp' => '2026-07-27T12:00:00+08:00',
    ])->assertOk();

    $job->refresh();
    expect($job->last_courier_status)->toBe('Out for delivery');

    // A stale "In transit" event, timestamped BEFORE the "Out for Delivery"
    // event above, arrives late (retry/out-of-order delivery). It must not
    // overwrite the newer status and regress the public tracker.
    postNinjaVanWebhook([
        'tracking_number' => 'NVSGNEXGE000ORD001',
        'status' => 'In Transit',
        'timestamp' => '2026-07-27T09:00:00+08:00',
    ])->assertOk();

    $job->refresh();
    expect($job->last_courier_status)->toBe('Out for delivery');

    // A genuinely newer event still updates it.
    postNinjaVanWebhook([
        'tracking_number' => 'NVSGNEXGE000ORD001',
        'status' => 'In Transit',
        'timestamp' => '2026-07-27T13:00:00+08:00',
    ])->assertOk();

    $job->refresh();
    expect($job->last_courier_status)->toBe('In transit');
});

it('enforces a unique consignment_ref at the database level', function (): void {
    ProductionJob::factory()->create(['consignment_ref' => 'GLDUPTEST']);

    expect(fn () => ProductionJob::factory()->create(['consignment_ref' => 'GLDUPTEST']))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

/**
 * ParcelReturned is the staff alert fired when a webhook event flags a
 * SHIPPED job returned/failed (NinjaVanStatusMapper's needsAttention
 * family) - the gap this task closes: a returned job used to sit SHIPPED
 * with nothing telling staff to act on it.
 */
it('fires ParcelReturned and emails staff on a Returned to Sender event', function (): void {
    Event::fake([ParcelReturned::class]);
    Mail::fake();
    $staff = User::factory()->staffAdmin()->create();

    $job = ninjaVanShippedJob('NVSGNEXGE000ALERT1');

    postNinjaVanWebhook([
        'tracking_number' => 'NVSGNEXGE000ALERT1',
        'status' => 'Returned to Sender',
    ])->assertOk();

    Event::assertDispatched(
        ParcelReturned::class,
        fn (ParcelReturned $event): bool => $event->job->id === $job->id,
    );
    Mail::assertQueued(ParcelReturnedMail::class, fn (ParcelReturnedMail $mail): bool => $mail->hasTo($staff->email));
});

it('fires ParcelReturned on an attempt-failed event', function (): void {
    Event::fake([ParcelReturned::class]);

    $job = ninjaVanShippedJob('NVSGNEXGE000ALERT2');

    postNinjaVanWebhook([
        'tracking_number' => 'NVSGNEXGE000ALERT2',
        'status' => 'First Attempt Failed',
    ])->assertOk();

    Event::assertDispatched(
        ParcelReturned::class,
        fn (ParcelReturned $event): bool => $event->job->id === $job->id,
    );
});

it('does not fire ParcelReturned on a benign status change', function (): void {
    $job = ninjaVanShippedJob('NVSGNEXGE000ALERT3');

    Event::fake([ParcelReturned::class]);
    Mail::fake();

    postNinjaVanWebhook([
        'tracking_number' => 'NVSGNEXGE000ALERT3',
        'status' => 'Out for Delivery',
    ])->assertOk();

    Event::assertNotDispatched(ParcelReturned::class);
    Mail::assertNotQueued(ParcelReturnedMail::class);
    expect($job->fresh()->state)->toBe(JobState::Shipped);
});

it('does not fire ParcelReturned on a Delivered event', function (): void {
    Event::fake([ParcelReturned::class]);

    ninjaVanShippedJob('NVSGNEXGE000ALERT4');

    postNinjaVanWebhook([
        'tracking_number' => 'NVSGNEXGE000ALERT4',
        'status' => 'Delivered',
    ])->assertOk();

    Event::assertNotDispatched(ParcelReturned::class);
});

it('ignores events for jobs that have not shipped yet', function (): void {
    $product = Product::factory()->create(['class' => 'CORE', 'print_method' => 'UV']);
    $quote = Quote::factory()->create([
        'company_id' => $this->company->id,
        'state' => 'PROCURING',
        'created_by' => $this->buyer->id,
    ]);
    $line = LineItem::factory()->ready()->create([
        'quote_id' => $quote->id,
        'product_id' => $product->id,
        'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/core.png'],
    ]);
    Proof::factory()->forLine($line)->approved()->create();
    $queue = app(QueueService::class);
    $job = $queue->buildJobsForQuote($quote->load('lineItems.product'))->first();
    // Not yet shipped - no consignment_ref at all.

    postNinjaVanWebhook([
        'tracking_number' => 'NVSGNEXGE000NEVER1',
        'status' => 'Delivered',
    ])->assertOk();

    $job->refresh();
    expect($job->state)->toBe(JobState::Ready);
});

// M14: two shipped jobs whose refs are both trailing substrings of the incoming
// tracking number are ambiguous - the webhook must NOT guess (it could mark the
// wrong parcel delivered). It acks and leaves both jobs untouched.
it('does not act on an ambiguous suffix match', function (): void {
    $jobA = ninjaVanShippedJob('GL1');
    $jobB = ninjaVanShippedJob('ZGL1');

    postNinjaVanWebhook([
        'tracking_number' => 'ZZGL1', // ends with both "GL1" and "ZGL1"
        'status' => 'Delivered',
        'timestamp' => '2026-07-27T10:00:00+08:00',
    ])->assertOk();

    expect($jobA->fresh()->state)->toBe(JobState::Shipped)
        ->and($jobB->fresh()->state)->toBe(JobState::Shipped);
});

it('still delivers on a single unambiguous suffix match (courier-prefixed number)', function (): void {
    $job = ninjaVanShippedJob('GLLEU2');

    postNinjaVanWebhook([
        'tracking_number' => 'NVSGNEXGE000GLLEU2', // prefixed; ends with GLLEU2
        'status' => 'Delivered',
        'timestamp' => '2026-07-27T10:00:00+08:00',
    ])->assertOk();

    expect($job->fresh()->state)->toBe(JobState::Closed);
});
