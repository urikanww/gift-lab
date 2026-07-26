<?php

declare(strict_types=1);

use App\Enums\JobState;
use App\Enums\OrderMilestone;
use App\Exceptions\InvalidStateTransitionException;
use App\Mail\OrderMilestoneMail;
use App\Models\Company;
use App\Models\LineItem;
use App\Models\PricingConfig;
use App\Models\Product;
use App\Models\ProductionJob;
use App\Models\Proof;
use App\Models\Quote;
use App\Models\User;
use App\Services\QueueService;
use Illuminate\Support\Facades\Mail;

/**
 * OrderMilestone::Shipped has full email copy, defaults ON, and is advertised
 * in the notification settings screen - but until now nothing ever called
 * OrderNotifier for it. QueueService::advance() is the one place a job
 * actually reaches JobState::Shipped (both the manual /advance endpoint and
 * ShipmentService's courier flow route through it), so that is the send site.
 *
 * Note: buildJobsForQuote() legitimately queues its own OrderMilestoneMail
 * too (QuoteState::Ready -> OrderMilestone::InProduction, via
 * Quote::transitionTo()), so assertions below filter by milestone rather than
 * counting OrderMilestoneMail overall.
 */
beforeEach(function (): void {
    Mail::fake();
    $this->company = Company::factory()->create();
    $this->buyer = User::factory()->create([
        'company_id' => $this->company->id,
        'role' => 'buyer',
    ]);
    $this->queue = app(QueueService::class);
});

function shippableJob(): ProductionJob
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

    $job = test()->queue->buildJobsForQuote($quote->load('lineItems.product'))->first();

    return test()->queue->advance($job, JobState::InProduction);
}

function shippedMilestoneMailCount(): int
{
    return Mail::queued(
        OrderMilestoneMail::class,
        fn (OrderMilestoneMail $mail): bool => $mail->milestone === OrderMilestone::Shipped,
    )->count();
}

it('emails the buyer the Shipped milestone with tracking when a job ships', function (): void {
    $job = shippableJob();

    $this->queue->advance($job, JobState::Shipped, consignmentRef: 'SP123456789SG');

    Mail::assertQueued(
        OrderMilestoneMail::class,
        fn (OrderMilestoneMail $mail): bool => $mail->milestone === OrderMilestone::Shipped
            && $mail->hasTo($this->buyer->email),
    );
});

it('does not double-send the Shipped email on a second advance to SHIPPED', function (): void {
    $job = shippableJob();

    $this->queue->advance($job, JobState::Shipped, consignmentRef: 'SP123456789SG');
    expect(shippedMilestoneMailCount())->toBe(1);

    // The state machine itself is the guard: Shipped -> Shipped is not a legal
    // transition, so a second attempt throws before any second send happens.
    expect(fn () => $this->queue->advance($job->fresh(), JobState::Shipped, consignmentRef: 'SP999999999SG'))
        ->toThrow(InvalidStateTransitionException::class);

    expect(shippedMilestoneMailCount())->toBe(1);
});

it('honours the Shipped milestone being switched off', function (): void {
    PricingConfig::updateOrCreate(
        ['group' => 'notifications', 'key' => OrderMilestone::Shipped->value],
        ['value' => false],
    );
    $job = shippableJob();

    $this->queue->advance($job, JobState::Shipped, consignmentRef: 'SP123456789SG');

    expect(shippedMilestoneMailCount())->toBe(0);
});
