<?php

declare(strict_types=1);

use App\Enums\QuoteState;
use App\Models\User;
use Tests\Harness\Agents\ValidatorAgent;
use Tests\Harness\Support\HarnessContext;

function harnessValidator(): ValidatorAgent
{
    // The oracle's transition check reads only the enum; a bare context with
    // unsaved users is enough (no DB access on this path).
    // test() returns a HigherOrderTapProxy wrapping the TestCase; unwrap via
    // ->target to get the Tests\TestCase instance HarnessContext expects.
    $ctx = new HarnessContext(test()->target, new User(), new User());

    return new ValidatorAgent($ctx);
}

it('records no violation for a legal quote transition', function (): void {
    $v = harnessValidator();

    $v->assertLegalTransition(QuoteState::Draft, QuoteState::Sent);

    expect($v->violations())->toBeEmpty();
});

it('records an ILLEGAL_TRANSITION violation for an illegal move', function (): void {
    $v = harnessValidator();

    $v->assertLegalTransition(QuoteState::Ready, QuoteState::Sent);

    expect($v->violations())->toHaveCount(1)
        ->and($v->violations()[0]->code)->toBe('ILLEGAL_TRANSITION');
});
