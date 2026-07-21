<?php

declare(strict_types=1);

use App\Enums\OrderMilestone;
use App\Enums\QuoteState;
use App\Mail\OrderMilestoneMail;
use App\Models\Company;
use App\Models\PricingConfig;
use App\Models\Quote;
use App\Models\User;
use App\Services\OrderNotifier;
use Illuminate\Support\Facades\Mail;

/**
 * Wave 4: the application sent two emails in total, so every other milestone
 * was a phone call somebody had to remember to make.
 *
 * Notification hangs off Quote::transitionTo(), the choke point every state
 * change passes through, rather than off the twelve call sites — a milestone
 * cannot then be missed because a new code path forgot to announce itself.
 */
beforeEach(function (): void {
    Mail::fake();
    $this->company = Company::factory()->create();
    $this->buyer = User::factory()->create([
        'company_id' => $this->company->id,
        'role' => 'buyer',
    ]);
});

function quoteIn(QuoteState $state): Quote
{
    return Quote::factory()->create([
        'company_id' => test()->company->id,
        'state' => $state->value,
        'created_by' => test()->buyer->id,
    ]);
}

it('emails the buyer when their order is confirmed', function (): void {
    $quote = quoteIn(QuoteState::Invoiced);

    $quote->transitionTo(QuoteState::Confirmed);

    Mail::assertQueued(
        OrderMilestoneMail::class,
        fn (OrderMilestoneMail $mail): bool => $mail->milestone === OrderMilestone::Committed
            && $mail->hasTo(test()->buyer->email),
    );
});

it('emails the buyer when the order goes to the floor', function (): void {
    $quote = quoteIn(QuoteState::Procuring);

    $quote->transitionTo(QuoteState::Ready);

    Mail::assertQueued(
        OrderMilestoneMail::class,
        fn (OrderMilestoneMail $mail): bool => $mail->milestone === OrderMilestone::InProduction,
    );
});

it('emails the buyer when the order is cancelled', function (): void {
    $quote = quoteIn(QuoteState::Accepted);

    $quote->transitionTo(QuoteState::Cancelled);

    Mail::assertQueued(
        OrderMilestoneMail::class,
        fn (OrderMilestoneMail $mail): bool => $mail->milestone === OrderMilestone::Cancelled,
    );
});

// Artwork-first route: approving artwork leaves the price still to agree, and
// the email has to say so or the buyer thinks they are finished.
it('tells the buyer there is a step left after artwork approval', function (): void {
    $quote = quoteIn(QuoteState::Proofing);

    $quote->transitionTo(QuoteState::ArtworkApproved);

    Mail::assertQueued(
        OrderMilestoneMail::class,
        fn (OrderMilestoneMail $mail): bool => $mail->milestone === OrderMilestone::ArtworkApproved,
    );
    expect(OrderMilestone::ArtworkApproved->body())->toContain('accept the pricing');
});

// INVOICED is never observable — it becomes CONFIRMED in the same transaction —
// and PROCURING means nothing to a buyer. Silence is correct for both.
it('stays silent on states that mean nothing to a buyer', function (): void {
    $quote = quoteIn(QuoteState::ProofApproved);

    $quote->transitionTo(QuoteState::Invoiced);

    Mail::assertNothingQueued();
});

it('honours a milestone being switched off', function (): void {
    PricingConfig::updateOrCreate(
        ['group' => 'notifications', 'key' => OrderMilestone::Committed->value],
        ['value' => false],
    );
    $quote = quoteIn(QuoteState::Invoiced);

    $quote->transitionTo(QuoteState::Confirmed);

    Mail::assertNothingQueued();
});

// Staff contact the client themselves about a dropped or re-priced item — that
// conversation needs a person, so the notice exists but ships switched off.
it('leaves the line-change notice off unless it is switched on', function (): void {
    $quote = quoteIn(QuoteState::Procuring);

    app(OrderNotifier::class)->send($quote, OrderMilestone::LineChanged);
    Mail::assertNothingQueued();

    PricingConfig::updateOrCreate(
        ['group' => 'notifications', 'key' => OrderMilestone::LineChanged->value],
        ['value' => true],
    );

    app(OrderNotifier::class)->send($quote, OrderMilestone::LineChanged);
    Mail::assertQueued(OrderMilestoneMail::class);
});

it('falls back to the company buyer when the creator is not one', function (): void {
    $staff = User::factory()->staffAdmin()->create();
    $quote = Quote::factory()->create([
        'company_id' => $this->company->id,
        'state' => QuoteState::Invoiced->value,
        'created_by' => $staff->id,
    ]);

    $quote->transitionTo(QuoteState::Confirmed);

    Mail::assertQueued(
        OrderMilestoneMail::class,
        fn (OrderMilestoneMail $mail): bool => $mail->hasTo(test()->buyer->email),
    );
});

it('does not fall over when a company has nobody to write to', function (): void {
    $orphan = Company::factory()->create();
    $quote = Quote::factory()->create([
        'company_id' => $orphan->id,
        'state' => QuoteState::Invoiced->value,
        'created_by' => null,
    ]);

    $quote->transitionTo(QuoteState::Confirmed);

    // The transition still happened; only the email had nowhere to go.
    expect($quote->fresh()->state)->toBe(QuoteState::Confirmed);
    Mail::assertNothingQueued();
});

// A mail failure must not roll back the transition that prompted it. An order
// that advanced but failed to notify is recoverable; an order that failed to
// advance because an SMTP host was down is not.
it('advances the order even when notifying blows up', function (): void {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp down'));
    $quote = quoteIn(QuoteState::Invoiced);

    $quote->transitionTo(QuoteState::Confirmed);

    expect($quote->fresh()->state)->toBe(QuoteState::Confirmed);
});

it('replies to a monitored address rather than no-reply', function (): void {
    config(['mail.support_address' => 'help@giftlab.test']);
    $quote = quoteIn(QuoteState::Invoiced);

    $mail = new OrderMilestoneMail($quote, OrderMilestone::Committed);

    expect($mail->envelope()->replyTo[0]->address)->toBe('help@giftlab.test');
});

// P1-4: issueProof only emailed when the quote ENTERED proofing, so v2 and
// later notified nobody. The buyer sat waiting on a proof already in front of
// them, and staff phoned every time. A revision does not change state, so this
// cannot ride on transitionTo().
it('emails the buyer on every revised proof, not just the first', function (): void {
    $staff = User::factory()->staffAdmin()->create();
    $this->actingAs($staff);
    $quote = quoteIn(QuoteState::Accepted);
    $service = app(App\Services\QuoteService::class);

    // v1 takes the quote into PROOFING and sends the richer quote-and-proof mail.
    $service->issueProof($quote, 'proofs/v1.pdf', null);
    Mail::assertQueued(App\Mail\QuoteReadyMail::class);
    Mail::assertNotQueued(OrderMilestoneMail::class);

    // v2 is where the silence used to be.
    $service->issueProof($quote->fresh(), 'proofs/v2.pdf', null);

    Mail::assertQueued(
        OrderMilestoneMail::class,
        fn (OrderMilestoneMail $mail): bool => $mail->milestone === OrderMilestone::ProofIssued,
    );
});
