<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\PricingConfig;
use App\Models\Proof;
use App\Models\Quote;
use App\Services\ReminderSchedule;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->schedule = app(ReminderSchedule::class);
});

it('schedules the next price reminder from the default ladder for an artwork-approved order', function (): void {
    $quote = Quote::factory()->create([
        'company_id' => $this->company->id,
        'state' => 'ARTWORK_APPROVED',
        'price_snapshot_at' => now()->subDay(),
        'reminders_sent' => 0,
    ]);

    $next = $this->schedule->next($quote);

    expect($next)->not->toBeNull()
        ->and($next['kind'])->toBe('price')
        ->and($next['exhausted'])->toBeFalse()
        ->and($next['ladder_days'])->toBe([3, 7, 12])
        ->and($next['reminders_remaining'])->toBe(3)
        // First rung is 3 days after the price snapshot.
        ->and($next['next_due_at'])->toBe($quote->price_snapshot_at->copy()->addDays(3)->toIso8601String());
});

it('advances to the next rung once a reminder has been sent', function (): void {
    $quote = Quote::factory()->create([
        'company_id' => $this->company->id,
        'state' => 'SENT',
        'price_snapshot_at' => now()->subDays(5),
        'reminders_sent' => 1,
    ]);

    $next = $this->schedule->next($quote);

    // Second rung of [3,7,12] is 7 days after the clock.
    expect($next['reminders_remaining'])->toBe(2)
        ->and($next['next_due_at'])->toBe($quote->price_snapshot_at->copy()->addDays(7)->toIso8601String());
});

it('reports an exhausted ladder with no further reminder', function (): void {
    $quote = Quote::factory()->create([
        'company_id' => $this->company->id,
        'state' => 'SENT',
        'price_snapshot_at' => now()->subDays(30),
        'reminders_sent' => 3,
    ]);

    $next = $this->schedule->next($quote);

    expect($next['exhausted'])->toBeTrue()
        ->and($next['next_due_at'])->toBeNull()
        ->and($next['reminders_remaining'])->toBe(0);
});

it('honours a staff-configured cadence ladder', function (): void {
    PricingConfig::query()->create([
        'group' => 'notifications_cadence',
        'key' => 'quote_days',
        'value' => [1, 4],
    ]);

    $quote = Quote::factory()->create([
        'company_id' => $this->company->id,
        'state' => 'SENT',
        'price_snapshot_at' => now()->subDay(),
        'reminders_sent' => 0,
    ]);

    $next = $this->schedule->next($quote);

    expect($next['ladder_days'])->toBe([1, 4])
        ->and($next['next_due_at'])->toBe($quote->price_snapshot_at->copy()->addDay()->toIso8601String());
});

it('has nothing to schedule for a state that is not waiting on the buyer', function (): void {
    $quote = Quote::factory()->create([
        'company_id' => $this->company->id,
        'state' => 'PROOF_APPROVED',
    ]);

    expect($this->schedule->next($quote))->toBeNull();
});

it('schedules a proof reminder when a proof is open in proofing', function (): void {
    $quote = Quote::factory()->create([
        'company_id' => $this->company->id,
        'state' => 'PROOFING',
        'reminders_sent' => 0,
    ]);
    Proof::factory()->create([
        'quote_id' => $quote->id,
        'state' => 'SENT',
        'created_at' => now()->subDay(),
    ]);

    $next = $this->schedule->next($quote->load('proofs'));

    expect($next['kind'])->toBe('proof')
        ->and($next['ladder_days'])->toBe([2, 5, 9]);
});

it('reports how many proofs are still awaiting the buyer', function (): void {
    $quote = Quote::factory()->create([
        'company_id' => $this->company->id,
        'state' => 'PROOFING',
        'reminders_sent' => 0,
    ]);
    Proof::factory()->count(2)->create([
        'quote_id' => $quote->id,
        'state' => 'SENT',
        'created_at' => now()->subDay(),
    ]);
    // An already-approved proof on the same order is not awaiting anyone.
    Proof::factory()->approved()->create([
        'quote_id' => $quote->id,
        'created_at' => now()->subDay(),
    ]);

    $next = $this->schedule->next($quote->load('proofs'));

    expect($next['kind'])->toBe('proof')
        ->and($next['awaiting_count'])->toBe(2);
});
