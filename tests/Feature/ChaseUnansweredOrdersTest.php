<?php

declare(strict_types=1);

use App\Enums\OrderMilestone;
use App\Mail\OrderMilestoneMail;
use App\Models\Company;
use App\Models\Proof;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * Nothing chased anything before this. A SENT quote or an unanswered proof sat
 * forever with no nudge to either side, so staff carried it by memory.
 */
beforeEach(function (): void {
    Mail::fake();
    $this->company = Company::factory()->create();
    $this->buyer = User::factory()->create(['company_id' => $this->company->id, 'role' => 'buyer']);
});

function sentQuoteWaiting(int $days): Quote
{
    return Quote::factory()->create([
        'company_id' => test()->company->id,
        'state' => 'SENT',
        'created_by' => test()->buyer->id,
        'price_snapshot_at' => now()->subDays($days),
    ]);
}

it('leaves a quote alone before the first rung of the ladder', function (): void {
    sentQuoteWaiting(1);

    $this->artisan('quotes:chase')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('chases an unanswered quote once it has waited long enough', function (): void {
    $quote = sentQuoteWaiting(3);

    $this->artisan('quotes:chase')->assertSuccessful();

    Mail::assertQueued(
        OrderMilestoneMail::class,
        fn (OrderMilestoneMail $mail): bool => $mail->milestone === OrderMilestone::ReminderPrice,
    );
    expect($quote->fresh()->reminders_sent)->toBe(1);
});

// The whole point of tracking the count: a nightly command must not send the
// same reminder every night.
it('does not chase the same order twice in a row', function (): void {
    $quote = sentQuoteWaiting(3);

    $this->artisan('quotes:chase')->assertSuccessful();
    Mail::assertQueuedCount(1);

    $this->artisan('quotes:chase')->assertSuccessful();
    Mail::assertQueuedCount(1);

    expect($quote->fresh()->reminders_sent)->toBe(1);
});

it('climbs the ladder as the wait goes on', function (): void {
    $quote = sentQuoteWaiting(8); // past rungs at 3 and 7

    $this->artisan('quotes:chase')->assertSuccessful();
    expect($quote->fresh()->reminders_sent)->toBe(1);

    $this->artisan('quotes:chase')->assertSuccessful();
    expect($quote->fresh()->reminders_sent)->toBe(2);
});

// The ladder ends on purpose. A buyer who ignored three emails will ignore the
// fourth, and continuing to send them is how a sender lands in spam.
it('stops writing and flags for staff after the last rung', function (): void {
    $quote = sentQuoteWaiting(30);

    $this->artisan('quotes:chase')->assertSuccessful();
    $this->artisan('quotes:chase')->assertSuccessful();
    $this->artisan('quotes:chase')->assertSuccessful();
    expect($quote->fresh()->reminders_sent)->toBe(3);

    $this->assertDatabaseHas('audit_logs', [
        'event' => 'quote.chase_exhausted',
        'auditable_id' => $quote->id,
    ]);

    // A fourth night sends nothing further.
    Mail::fake();
    $this->artisan('quotes:chase')->assertSuccessful();
    Mail::assertNothingQueued();
});

it('chases an unapproved proof on the faster ladder', function (): void {
    $quote = Quote::factory()->create([
        'company_id' => $this->company->id,
        'state' => 'PROOFING',
        'created_by' => $this->buyer->id,
    ]);
    $proof = Proof::factory()->create(['quote_id' => $quote->id, 'state' => 'SENT']);
    $proof->forceFill(['created_at' => now()->subDays(2)])->saveQuietly();

    $this->artisan('quotes:chase')->assertSuccessful();

    Mail::assertQueued(
        OrderMilestoneMail::class,
        fn (OrderMilestoneMail $mail): bool => $mail->milestone === OrderMilestone::ReminderProof,
    );
});

// A quote in PROOFING whose proofs are all decided is waiting on STAFF, not the
// buyer. Chasing the buyer for it would be both useless and rude.
it('does not chase a buyer for a proof that is already decided', function (): void {
    $quote = Quote::factory()->create([
        'company_id' => $this->company->id,
        'state' => 'PROOFING',
        'created_by' => $this->buyer->id,
    ]);
    $proof = Proof::factory()->create(['quote_id' => $quote->id, 'state' => 'CHANGES_REQUESTED']);
    $proof->forceFill(['created_at' => now()->subDays(9)])->saveQuietly();

    $this->artisan('quotes:chase')->assertSuccessful();

    Mail::assertNothingQueued();
});

// Artwork-first route: artwork is signed off but the price is not, so the buyer
// is still the one holding things up.
it('chases an artwork-approved order for the outstanding price agreement', function (): void {
    Quote::factory()->create([
        'company_id' => $this->company->id,
        'state' => 'ARTWORK_APPROVED',
        'created_by' => $this->buyer->id,
        'price_snapshot_at' => now()->subDays(4),
    ]);

    $this->artisan('quotes:chase')->assertSuccessful();

    Mail::assertQueued(
        OrderMilestoneMail::class,
        fn (OrderMilestoneMail $mail): bool => $mail->milestone === OrderMilestone::ReminderPrice,
    );
});

it('leaves orders that are not waiting on the buyer alone', function (): void {
    Quote::factory()->create([
        'company_id' => $this->company->id,
        'state' => 'PROCURING',
        'created_by' => $this->buyer->id,
        'price_snapshot_at' => now()->subDays(30),
    ]);

    $this->artisan('quotes:chase')->assertSuccessful();

    Mail::assertNothingQueued();
});
