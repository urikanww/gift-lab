<?php

declare(strict_types=1);

use App\Support\Permissions;

it('registers a sensitive reports.view permission', function (): void {
    expect(Permissions::all())->toContain('reports.view')
        ->and(Permissions::SENSITIVE_SECTIONS)->toContain('reports')
        ->and(Permissions::defaults())->not->toContain('reports.view');
});
