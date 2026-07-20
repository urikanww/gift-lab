<?php

declare(strict_types=1);

use App\Enums\QuoteState;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\AuditLog;
use App\Models\Quote;
use App\Services\QuoteService;

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
