<?php

declare(strict_types=1);

use App\Mail\ExceptionAlertMail;

it('exposes the alerts config', function (): void {
    expect(config('alerts'))->toBeArray()
        ->and(array_key_exists('ops_email', config('alerts')))->toBeTrue()
        ->and((int) config('alerts.exception_throttle_minutes'))->toBeGreaterThan(0);
});

it('builds the exception alert mailable with a subject and body', function (): void {
    $mail = new ExceptionAlertMail('RuntimeException', 'boom happened', 'api/quotes');
    $mail->assertHasSubject('Unhandled exception: RuntimeException — Gift Lab');
    $mail->assertSeeInHtml('boom happened');
    $mail->assertSeeInHtml('api/quotes');
});
