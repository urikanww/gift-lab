<?php

declare(strict_types=1);

it('exposes the alerts config', function (): void {
    expect(config('alerts'))->toBeArray()
        ->and(array_key_exists('ops_email', config('alerts')))->toBeTrue()
        ->and((int) config('alerts.exception_throttle_minutes'))->toBeGreaterThan(0);
});
