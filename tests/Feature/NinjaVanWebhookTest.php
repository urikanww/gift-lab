<?php

declare(strict_types=1);

use App\Enums\Carrier;
use App\Enums\JobState;
use App\Enums\QuoteState;
use App\Events\OrderTrackingUpdated;
use App\Models\Company;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\ProductionJob;
use App\Models\Proof;
use App\Models\Quote;
use App\Models\User;
use App\Services\QueueService;
use Illuminate\Support\Facades\Event;
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
