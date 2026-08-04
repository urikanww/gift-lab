<?php

declare(strict_types=1);

use App\Mail\ExceptionAlertMail;
use App\Models\User;
use App\Services\StaffNotifier;
use Illuminate\Support\Facades\Mail;

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

it('emails the ops address once for an unhandled exception', function (): void {
    config()->set('alerts.ops_email', 'ops@nexgen.test');
    Mail::fake();

    app(StaffNotifier::class)->unexpectedException(new RuntimeException('kaboom'), 'api/quotes');

    Mail::assertQueued(ExceptionAlertMail::class, 1);
});

it('throttles a repeat of the same exception signature', function (): void {
    config()->set('alerts.ops_email', 'ops@nexgen.test');
    config()->set('alerts.exception_throttle_minutes', 15);
    Mail::fake();

    $notifier = app(StaffNotifier::class);
    $notifier->unexpectedException(new RuntimeException('same message'), 'api/quotes');
    $notifier->unexpectedException(new RuntimeException('same message'), 'api/quotes');

    Mail::assertQueued(ExceptionAlertMail::class, 1);
});

it('does not throttle a different exception signature', function (): void {
    config()->set('alerts.ops_email', 'ops@nexgen.test');
    Mail::fake();

    $notifier = app(StaffNotifier::class);
    $notifier->unexpectedException(new RuntimeException('first'), null);
    $notifier->unexpectedException(new RuntimeException('second'), null);

    Mail::assertQueued(ExceptionAlertMail::class, 2);
});

it('falls back to the staff-admin/superadmin list when no ops email is set', function (): void {
    config()->set('alerts.ops_email', null);
    Mail::fake();

    User::factory()->staffAdmin()->create(['email' => 'admin@nexgen.test']);
    User::factory()->create(['role' => 'superadmin', 'email' => 'boss@nexgen.test']);
    User::factory()->create(['role' => 'buyer', 'email' => 'buyer@nexgen.test']);

    app(StaffNotifier::class)->unexpectedException(new RuntimeException('to staff'), null);

    Mail::assertQueued(ExceptionAlertMail::class, 2);
});

it('never throws when the mailer itself fails', function (): void {
    config()->set('alerts.ops_email', 'ops@nexgen.test');
    Mail::shouldReceive('to->queue')->andThrow(new RuntimeException('SMTP down'));

    app(StaffNotifier::class)->unexpectedException(new RuntimeException('boom'), null);
})->throwsNoExceptions();
