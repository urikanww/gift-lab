<?php

declare(strict_types=1);

use Tests\Harness\Support\Violation;

it('carries a code, message and state', function (): void {
    $v = new Violation('ILLEGAL_TRANSITION', 'READY -> SENT is illegal', 'READY');

    expect($v->code)->toBe('ILLEGAL_TRANSITION')
        ->and($v->message)->toBe('READY -> SENT is illegal')
        ->and($v->state)->toBe('READY');
});

it('renders a readable string for test failure output', function (): void {
    $v = new Violation('INVOICE_MATCHES_PRODUCED', 'billed 5 != produced 2', 'PROCURING');

    expect((string) $v)->toBe('[INVOICE_MATCHES_PRODUCED] billed 5 != produced 2 (state: PROCURING)');
});
