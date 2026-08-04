<?php

declare(strict_types=1);

use App\Exceptions\DomainRuleException;
use App\Mail\ExceptionAlertMail;
use App\Models\User;
use App\Services\StaffNotifier;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Validation\ValidationException;

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

    // Same exception instance twice = same throw site = throttled to one email.
    $notifier = app(StaffNotifier::class);
    $e = new RuntimeException('same message');
    $notifier->unexpectedException($e, 'api/quotes');
    $notifier->unexpectedException($e, 'api/quotes');

    Mail::assertQueued(ExceptionAlertMail::class, 1);
});

it('does not throttle a different exception signature', function (): void {
    config()->set('alerts.ops_email', 'ops@nexgen.test');
    Mail::fake();

    // Distinct exception classes = distinct signatures regardless of throw site.
    $notifier = app(StaffNotifier::class);
    $notifier->unexpectedException(new RuntimeException('first'), null);
    $notifier->unexpectedException(new LogicException('second'), null);

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

it('alerts ops when a reportable exception is reported through the handler', function (): void {
    config()->set('alerts.ops_email', 'ops@nexgen.test');
    Mail::fake();

    app(ExceptionHandler::class)->report(new RuntimeException('an unhandled 500'));

    Mail::assertQueued(ExceptionAlertMail::class, 1);
});

it('does not alert ops for a validation exception', function (): void {
    config()->set('alerts.ops_email', 'ops@nexgen.test');
    Mail::fake();

    app(ExceptionHandler::class)->report(ValidationException::withMessages(['field' => 'bad']));

    Mail::assertNotQueued(ExceptionAlertMail::class);
});

it('does not alert ops for a domain-rule exception', function (): void {
    config()->set('alerts.ops_email', 'ops@nexgen.test');
    Mail::fake();

    app(ExceptionHandler::class)->report(new DomainRuleException('expected guard'));

    Mail::assertNotQueued(ExceptionAlertMail::class);
});

it('returns 200 with all-true checks when healthy', function (): void {
    Redis::shouldReceive('connection->ping')->andReturnTrue();
    Queue::shouldReceive('connection->size')->andReturn(0);

    $res = $this->getJson('/api/health');

    $res->assertOk()
        ->assertJson(['ok' => true, 'checks' => ['database' => true, 'redis' => true, 'queue' => true]]);
});

it('returns 503 when a check fails', function (): void {
    Redis::shouldReceive('connection->ping')->andThrow(new RuntimeException('redis down'));
    Queue::shouldReceive('connection->size')->andReturn(0);

    $res = $this->getJson('/api/health');

    $res->assertStatus(503)->assertJson(['ok' => false, 'checks' => ['redis' => false]]);
});

it('leaks no infra detail in the health body', function (): void {
    Redis::shouldReceive('connection->ping')->andReturnTrue();
    Queue::shouldReceive('connection->size')->andReturn(0);

    $body = $this->getJson('/api/health')->json();

    expect(array_keys($body))->toEqualCanonicalizing(['ok', 'checks'])
        ->and($body['checks'])->each->toBeBool();
});

it('falls back to an uncached probe when the cache store itself is unreachable', function (): void {
    // Production's default cache store is DB-backed (config/cache.php), so a
    // DB outage would otherwise throw inside Cache::remember before any check
    // runs. The endpoint must still degrade to a clean 200/503 boolean body.
    Cache::shouldReceive('remember')->andThrow(new RuntimeException('cache store down'));
    Redis::shouldReceive('connection->ping')->andReturnTrue();
    Queue::shouldReceive('connection->size')->andReturn(0);

    $res = $this->getJson('/api/health');

    $res->assertOk()
        ->assertJson(['ok' => true, 'checks' => ['database' => true, 'redis' => true, 'queue' => true]]);
});
