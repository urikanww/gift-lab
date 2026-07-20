<?php

declare(strict_types=1);

use App\Enums\QuoteState;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Quote;
use App\Models\User;
use App\Services\QuoteService;
use Laravel\Sanctum\Sanctum;

/**
 * Every quote state transition must leave a trail. The log lives inside
 * Quote::transitionTo(), so these tests exercise the model directly - that is
 * the choke point every caller goes through.
 */
it('writes exactly one audit row for a successful transition', function (): void {
    $quote = Quote::factory()->create(['state' => 'DRAFT']);

    $quote->transitionTo(QuoteState::Sent);

    $rows = AuditLog::query()->where('event', 'quote.state_changed')->get();

    // Exactly one - a double-write would be invisible to a non-zero assertion.
    expect($rows)->toHaveCount(1);

    $row = $rows->first();
    expect($row->auditable_type)->toBe(Quote::class)
        ->and($row->auditable_id)->toBe($quote->id)
        ->and($row->old_values)->toBe(['state' => 'DRAFT'])
        ->and($row->new_values)->toBe(['state' => 'SENT']);
});

it('writes nothing when a transition is rejected', function (): void {
    $quote = Quote::factory()->create(['state' => 'DRAFT']);

    // DRAFT -> READY is not in the legal graph (QuoteState::nextStates).
    expect(fn () => $quote->transitionTo(QuoteState::Ready))
        ->toThrow(InvalidStateTransitionException::class);

    // The log sits AFTER the guard, so a refused move leaves no trace.
    expect(AuditLog::query()->where('event', 'quote.state_changed')->count())->toBe(0);
    expect($quote->refresh()->state)->toBe(QuoteState::Draft);
});

it('rolls the state back when the audit insert fails during procure', function (): void {
    $quote = Quote::factory()->create(['state' => 'CONFIRMED']);

    // Fail the audit insert itself - the real AuditLogger and the real create()
    // path still run, a `creating` hook just makes the write throw. That is the
    // SECOND write in transitionTo, after the state save has already happened.
    AuditLog::creating(function (): void {
        throw new RuntimeException('audit insert failed');
    });

    expect(fn () => app(QuoteService::class)->procure($quote))
        ->toThrow(RuntimeException::class);

    // Without the transaction the state would have committed as PROCURING while
    // the caller saw an exception - a transition that happened but was never logged.
    expect(Quote::query()->find($quote->id)->state)->toBe(QuoteState::Confirmed);
});

it('records each hop of a multi-step journey in order', function (): void {
    $quote = Quote::factory()->create(['state' => 'DRAFT']);

    $quote->transitionTo(QuoteState::Sent);
    $quote->transitionTo(QuoteState::Accepted);
    $quote->transitionTo(QuoteState::Proofing);
    $quote->transitionTo(QuoteState::ProofApproved);

    $trail = AuditLog::query()
        ->where('event', 'quote.state_changed')
        ->where('auditable_id', $quote->id)
        ->orderBy('id')
        ->get()
        ->map(fn (AuditLog $log): array => [$log->old_values['state'], $log->new_values['state']])
        ->all();

    expect($trail)->toBe([
        ['DRAFT', 'SENT'],
        ['SENT', 'ACCEPTED'],
        ['ACCEPTED', 'PROOFING'],
        ['PROOFING', 'PROOF_APPROVED'],
    ]);
});

/*
|--------------------------------------------------------------------------
| GET /api/quotes/{quote}/history
|--------------------------------------------------------------------------
| The read side of the trail above. Tenancy is the policy's call, not an
| inline company_id compare, and the actor is rendered by NAME only - a buyer
| can read this endpoint and staff addresses are not theirs to have.
*/

it('returns the state trail oldest first', function (): void {
    $buyer = User::factory()->create();
    $quote = Quote::factory()->create([
        'company_id' => $buyer->company_id,
        'state' => 'DRAFT',
    ]);

    Sanctum::actingAs($buyer);

    $quote->transitionTo(QuoteState::Sent);
    $quote->transitionTo(QuoteState::Accepted);
    $quote->transitionTo(QuoteState::Proofing);

    $response = $this->getJson("/api/quotes/{$quote->id}/history")->assertOk();

    $trail = collect($response->json('data'))
        ->map(fn (array $row): array => [$row['from'], $row['to']])
        ->all();

    // Chronological, not newest-first: a timeline reads forwards.
    expect($trail)->toBe([
        ['DRAFT', 'SENT'],
        ['SENT', 'ACCEPTED'],
        ['ACCEPTED', 'PROOFING'],
    ]);

    expect($response->json('data.0.actor_name'))->toBe($buyer->name)
        ->and($response->json('data.0.changed_at'))->not->toBeNull();
});

it('refuses a buyer reading another company history with 403', function (): void {
    $intruder = User::factory()->create();
    $quote = Quote::factory()->create([
        'company_id' => Company::factory()->create()->id,
        'state' => 'DRAFT',
    ]);

    $quote->transitionTo(QuoteState::Sent);

    Sanctum::actingAs($intruder);

    // 403, not 200-with-an-empty-list: an empty list would silently confirm the
    // order exists and would go green if the tenancy filter were ever dropped.
    $this->getJson("/api/quotes/{$quote->id}/history")->assertForbidden();
});

it('never leaks an email address in the history payload', function (): void {
    $staff = User::factory()->staffAdmin()->create();
    $buyer = User::factory()->create();
    $quote = Quote::factory()->create([
        'company_id' => $buyer->company_id,
        'state' => 'DRAFT',
    ]);

    Sanctum::actingAs($staff);
    $quote->transitionTo(QuoteState::Sent);

    Sanctum::actingAs($buyer);
    $response = $this->getJson("/api/quotes/{$quote->id}/history")->assertOk();

    // Asserted on the WHOLE body, not a named field: this also catches an email
    // arriving under some key added later, or a serialised user object.
    expect($response->getContent())->not->toContain('@')
        ->and($response->getContent())->not->toContain($staff->email)
        ->and($response->json('data.0.actor_name'))->toBe($staff->name);
});

it('renders a cancellation once even though cancel writes two audit rows', function (): void {
    $buyer = User::factory()->create();
    $quote = Quote::factory()->create([
        'company_id' => $buyer->company_id,
        'state' => 'DRAFT',
    ]);

    Sanctum::actingAs($buyer);

    app(QuoteService::class)->cancel($quote, 'changed our minds');

    // cancel() logs quote.state_changed AND quote.cancelled against the same
    // quote; without the event filter the timeline would show the cancel twice.
    expect(AuditLog::query()->where('auditable_id', $quote->id)->count())->toBe(2);

    $response = $this->getJson("/api/quotes/{$quote->id}/history")->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.from'))->toBe('DRAFT')
        ->and($response->json('data.0.to'))->toBe('CANCELLED');
});

it('returns 200 and an empty array for a quote with no transitions', function (): void {
    $buyer = User::factory()->create();
    $quote = Quote::factory()->create([
        'company_id' => $buyer->company_id,
        'state' => 'DRAFT',
    ]);

    Sanctum::actingAs($buyer);

    // An order that has never moved is normal, not missing - 200 with [], not 404.
    $this->getJson("/api/quotes/{$quote->id}/history")
        ->assertOk()
        ->assertExactJson(['data' => []]);
});
