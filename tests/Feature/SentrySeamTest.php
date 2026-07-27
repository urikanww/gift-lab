<?php

declare(strict_types=1);

use App\Exceptions\DomainRuleException;
use App\Exceptions\FeatureNotEnabledException;
use App\Exceptions\InvalidStateTransitionException;

// Task 12: the Sentry seam must be inert (no sentry/sentry-laravel dependency
// installed) - config defaults to an empty DSN, and the app boots/handles
// requests unaffected since bootstrap/app.php only calls into app('sentry')
// when something has actually bound it.

it('defaults the Sentry DSN to an empty string', function (): void {
    expect(config('services.sentry.dsn'))->toBe('');
});

it('boots and serves requests unaffected with no Sentry package bound', function (): void {
    expect(app()->bound('sentry'))->toBeFalse();

    $this->get('/up')->assertOk();
});

it('does not report the well-mapped domain exceptions to the exception handler', function (): void {
    $handler = app(\Illuminate\Contracts\Debug\ExceptionHandler::class);

    expect($handler->shouldReport(new DomainRuleException('x')))->toBeFalse();
    expect($handler->shouldReport(new InvalidStateTransitionException('x')))->toBeFalse();
    expect($handler->shouldReport(FeatureNotEnabledException::make('x')))->toBeFalse();
});
