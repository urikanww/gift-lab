# Observability & Alerting (Point 3) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Email an ops address when an unhandled exception occurs (throttled), and expose a public `/api/health` endpoint reporting DB/Redis/queue health.

**Architecture:** In-house only — no SaaS. A new throttled `StaffNotifier::unexpectedException()` (sibling to the existing, already-wired `jobFailed()`) is invoked from the exception handler's existing `report()` callback and emails an ops address via a new `ExceptionAlertMail`. A public `HealthController` runs three guarded probes behind a 5-second cache and returns boolean-only JSON with 200/503.

**Tech Stack:** Laravel 11 (Pest), queued Mailables, cache-based throttle.

**Spec:** `docs/superpowers/specs/2026-08-04-observability-alerting-design.md`
**Branch:** `feat/observability-alerting` (already created).

**Standing context:**
- Both suites green at branch point. Keep them green.
- **Failed-job alerting is already built and out of scope** — `Queue::failing` → `StaffNotifier::jobFailed` (`AppServiceProvider.php:268`), `JobFailedAlertMail`, `FailedJobAlertTest`. Do NOT modify it. Model new code on it.
- Tests run `QUEUE_CONNECTION=sync`; `Mail::fake()` + `Mail::assertQueued(...)` is the established pattern (see `tests/Feature/FailedJobAlertTest.php`).
- The exception handler already `dontReport`s the 3 domain exceptions, and Laravel never reports its internal 4xx/validation/auth exceptions — so a `report()` callback only sees genuinely reportable (≈5xx/uncaught) exceptions.

---

## File Structure

**New**
- `config/alerts.php` — ops email + throttle window.
- `app/Mail/ExceptionAlertMail.php` — the alert mailable (queued).
- `resources/views/mail/exception-alert.blade.php` — its body.
- `app/Http/Controllers/HealthController.php` — the health probe.
- `tests/Feature/ObservabilityAlertingTest.php` — all tests for this feature.

**Modified**
- `app/Services/StaffNotifier.php` — add `unexpectedException()` + `opsRecipients()`.
- `bootstrap/app.php` — call `unexpectedException()` from the existing `report()`.
- `routes/api.php` — public `GET /health` route.
- `deploy/.env.production.example` — document `OPS_ALERT_EMAIL`, `OPS_ALERT_THROTTLE`.

---

## Task 1: Config + env docs

**Files:**
- Create: `config/alerts.php`
- Modify: `deploy/.env.production.example`
- Test: `tests/Feature/ObservabilityAlertingTest.php`

- [ ] **Step 1: Write the failing test.** Create `tests/Feature/ObservabilityAlertingTest.php`:

```php
<?php

declare(strict_types=1);

it('exposes the alerts config', function (): void {
    expect(config('alerts'))->toBeArray()
        ->and(array_key_exists('ops_email', config('alerts')))->toBeTrue()
        ->and((int) config('alerts.exception_throttle_minutes'))->toBeGreaterThan(0);
});
```

- [ ] **Step 2: Run it — expect FAIL.** `php artisan test --filter=ObservabilityAlertingTest` → config null.

- [ ] **Step 3: Create `config/alerts.php`:**

```php
<?php

declare(strict_types=1);

return [
    // Where unhandled-exception alerts are emailed. Null => fall back to the
    // staff-admin/superadmin list (see StaffNotifier::opsRecipients()).
    'ops_email' => env('OPS_ALERT_EMAIL'),

    // One alert per identical exception signature per this many minutes, so a
    // 500-storm can't flood the ops inbox.
    'exception_throttle_minutes' => (int) env('OPS_ALERT_THROTTLE', 15),
];
```

- [ ] **Step 4: Document the env vars.** Append to `deploy/.env.production.example` (near the other app-level vars):

```
# Ops alerting (point 3). OPS_ALERT_EMAIL unset => alerts go to staff-admin/superadmin users.
OPS_ALERT_EMAIL=
OPS_ALERT_THROTTLE=15
```

- [ ] **Step 5: Run it — expect PASS.** `php artisan test --filter=ObservabilityAlertingTest`

- [ ] **Step 6: Commit:**

```bash
git add config/alerts.php deploy/.env.production.example tests/Feature/ObservabilityAlertingTest.php
git commit -m "feat(observability): alerts config + env docs"
```

---

## Task 2: ExceptionAlertMail

**Files:**
- Create: `app/Mail/ExceptionAlertMail.php`
- Create: `resources/views/mail/exception-alert.blade.php`
- Test: `tests/Feature/ObservabilityAlertingTest.php` (append)

- [ ] **Step 1: Write the failing test.** Append:

```php
use App\Mail\ExceptionAlertMail;

it('builds the exception alert mailable with a subject and body', function (): void {
    $mail = new ExceptionAlertMail('RuntimeException', 'boom happened', 'api/quotes');
    $mail->assertHasSubject('Unhandled exception: RuntimeException — Gift Lab');
    $mail->assertSeeInHtml('boom happened');
    $mail->assertSeeInHtml('api/quotes');
});
```

- [ ] **Step 2: Run it — expect FAIL** (class missing). `php artisan test --filter=ObservabilityAlertingTest`

- [ ] **Step 3: Create `app/Mail/ExceptionAlertMail.php`** (modelled on `JobFailedAlertMail`):

```php
<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Staff-facing "an unhandled exception occurred" alert. Queued so a slow SMTP
 * handshake never blocks the request/worker. Sent only from
 * StaffNotifier::unexpectedException(), which throttles by signature.
 */
class ExceptionAlertMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $exceptionClass,
        public string $exceptionMessage,
        public ?string $path,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Unhandled exception: {$this->exceptionClass} — Gift Lab",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.exception-alert',
            with: [
                'exceptionClass' => $this->exceptionClass,
                'exceptionMessage' => $this->exceptionMessage,
                'path' => $this->path,
            ],
        );
    }
}
```

- [ ] **Step 4: Create `resources/views/mail/exception-alert.blade.php`:**

```blade
<x-mail::message>
# Unhandled exception

An unexpected error occurred and was reported to the team.

**Type:** {{ $exceptionClass }}

**Message:** {{ $exceptionMessage }}

@if ($path)
**Request path:** {{ $path }}
@endif

This is an automated system-health alert.
</x-mail::message>
```

Note: confirm the existing mail views use the `<x-mail::message>` markdown component (check `resources/views/mail/job-failed-alert.blade.php`). If that file uses a plain HTML/Blade layout instead, mirror ITS structure rather than the markdown component above.

- [ ] **Step 5: Run it — expect PASS.** `php artisan test --filter=ObservabilityAlertingTest`

- [ ] **Step 6: Commit:**

```bash
git add app/Mail/ExceptionAlertMail.php resources/views/mail/exception-alert.blade.php tests/Feature/ObservabilityAlertingTest.php
git commit -m "feat(observability): ExceptionAlertMail"
```

---

## Task 3: StaffNotifier::unexpectedException (throttled)

**Files:**
- Modify: `app/Services/StaffNotifier.php`
- Test: `tests/Feature/ObservabilityAlertingTest.php` (append)

- [ ] **Step 1: Write the failing tests.** Append (add the `use` lines to the top of the file if not present):

```php
use App\Services\StaffNotifier;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

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

    // Second identical-signature call inside the window is suppressed.
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

    // One per staff recipient, never the buyer.
    Mail::assertQueued(ExceptionAlertMail::class, 2);
});

it('never throws when the mailer itself fails', function (): void {
    config()->set('alerts.ops_email', 'ops@nexgen.test');
    Mail::shouldReceive('to->queue')->andThrow(new RuntimeException('SMTP down'));

    // Must not throw.
    app(StaffNotifier::class)->unexpectedException(new RuntimeException('boom'), null);
})->throwsNoExceptions();
```

- [ ] **Step 2: Run — expect FAIL** (method missing). `php artisan test --filter=ObservabilityAlertingTest`

- [ ] **Step 3: Add the method + helper to `app/Services/StaffNotifier.php`.** Add `use App\Mail\ExceptionAlertMail;` and `use Illuminate\Support\Facades\Cache;` to the imports. Then add these two methods to the class (place `unexpectedException` near `jobFailed`):

```php
    /**
     * Announce an unhandled (reportable) exception to the ops team. Invoked from
     * the framework's report() pipeline, so — like jobFailed() — it MUST NEVER
     * THROW, and it throttles by exception signature so a 500-storm can't flood
     * the inbox (one email per identical signature per the configured window).
     */
    public function unexpectedException(Throwable $e, ?string $path = null): void
    {
        try {
            $signature = sha1($e::class.'|'.$e->getMessage());
            $ttl = now()->addMinutes((int) config('alerts.exception_throttle_minutes', 15));

            // Cache::add is atomic "first one through the window wins": returns
            // false if the key already exists, so a repeat is suppressed.
            if (! Cache::add('ops-alert:'.$signature, true, $ttl)) {
                Log::info('Ops exception alert throttled.', ['exception' => $e::class]);

                return;
            }

            $recipients = $this->opsRecipients();
            if ($recipients === []) {
                Log::info('Ops exception alert: no recipient to email.', ['exception' => $e::class]);

                return;
            }

            foreach ($recipients as $email) {
                try {
                    Mail::to($email)->queue(new ExceptionAlertMail($e::class, $e->getMessage(), $path));
                } catch (Throwable $mailError) {
                    Log::error('Ops exception alert failed to queue.', ['error' => $mailError->getMessage()]);
                }
            }
        } catch (Throwable $unexpected) {
            // This is the alert path itself — it must never be the thing that throws.
            Log::error('StaffNotifier::unexpectedException itself failed; alert suppressed.', [
                'error' => $unexpected->getMessage(),
            ]);
        }
    }

    /**
     * Ops-alert recipients: the configured ops address if set, else every
     * staff-admin/superadmin with an email (mirrors the jobFailed resolution).
     *
     * @return array<int, string>
     */
    private function opsRecipients(): array
    {
        $ops = config('alerts.ops_email');
        if (is_string($ops) && $ops !== '') {
            return [$ops];
        }

        return User::query()
            ->whereIn('role', [UserRole::StaffAdmin->value, UserRole::Superadmin->value])
            ->whereNotNull('email')
            ->pluck('email')
            ->all();
    }
```

- [ ] **Step 4: Run — expect PASS (all 5 new + earlier).** `php artisan test --filter=ObservabilityAlertingTest`

- [ ] **Step 5: Commit:**

```bash
git add app/Services/StaffNotifier.php tests/Feature/ObservabilityAlertingTest.php
git commit -m "feat(observability): throttled unexpectedException staff alert"
```

---

## Task 4: Wire the exception handler

**Files:**
- Modify: `bootstrap/app.php:106-110` (the existing `report()` callback)
- Test: `tests/Feature/ObservabilityAlertingTest.php` (append)

- [ ] **Step 1: Write the failing tests.** Append (add `use` lines as needed):

```php
use App\Exceptions\DomainRuleException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Validation\ValidationException;

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
```

- [ ] **Step 2: Run — expect the first test FAIL** (no alert queued yet), the other two already pass. `php artisan test --filter=ObservabilityAlertingTest`

- [ ] **Step 3: Wire the call.** In `bootstrap/app.php`, extend the existing `report()` callback (currently lines 106-110, the Sentry seam) so it becomes:

```php
        $exceptions->report(function (Throwable $e): void {
            if (app()->bound('sentry')) {
                app('sentry')->captureException($e);
            }

            // In-house ops alert (point 3): email the ops team on genuinely
            // reportable exceptions. Domain guards are dontReport'd below and the
            // framework never reports 4xx/validation/auth, so only real faults
            // reach here. Runs in requests and workers alike; never throws.
            app(\App\Services\StaffNotifier::class)->unexpectedException(
                $e,
                app()->runningInConsole() ? null : request()->path(),
            );
        });
```

Leave the `dontReport([...])` block below it unchanged.

- [ ] **Step 4: Run — expect PASS (all).** `php artisan test --filter=ObservabilityAlertingTest`

- [ ] **Step 5: Commit:**

```bash
git add bootstrap/app.php tests/Feature/ObservabilityAlertingTest.php
git commit -m "feat(observability): alert ops from the exception report() pipeline"
```

---

## Task 5: Public health endpoint

**Files:**
- Create: `app/Http/Controllers/HealthController.php`
- Modify: `routes/api.php` (public `GET /health`)
- Test: `tests/Feature/ObservabilityAlertingTest.php` (append)

- [ ] **Step 1: Write the failing tests.** Append (add `use` lines as needed):

```php
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Queue;

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

    // Only the two documented keys; values are booleans, no versions/hosts/messages.
    expect(array_keys($body))->toEqualCanonicalizing(['ok', 'checks'])
        ->and($body['checks'])->each->toBeBool();
});
```

Note: these tests mock Redis + Queue so they don't depend on a live Redis in CI; the DB check runs against the real (sqlite) test connection and passes. If the app's test bootstrap already binds Redis to a working fake, the mocks still take precedence.

- [ ] **Step 2: Run — expect FAIL** (route 404). `php artisan test --filter=ObservabilityAlertingTest`

- [ ] **Step 3: Create `app/Http/Controllers/HealthController.php`:**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Public deep health probe for an uptime monitor. Boolean-only body (no infra
 * detail leaked), 200 when all checks pass / 503 otherwise. Result is cached a
 * few seconds so an unauthenticated request flood can't hammer DB/Redis.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $result = Cache::remember('health:probe', 5, function (): array {
            $checks = [
                'database' => $this->probe(fn () => DB::connection()->getPdo()),
                'redis' => $this->probe(fn () => Redis::connection()->ping()),
                'queue' => $this->probe(fn () => Queue::connection()->size()),
            ];

            return [
                'ok' => ! in_array(false, $checks, true),
                'checks' => $checks,
            ];
        });

        return response()->json($result, $result['ok'] ? 200 : 503);
    }

    /** Run one check; any throw reads as a failed (false) check, never bubbles. */
    private function probe(callable $check): bool
    {
        try {
            $check();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
```

- [ ] **Step 4: Add the public route.** In `routes/api.php`, add near the other public (no-auth) routes — e.g. beside the public catalogue route:

```php
Route::get('/health', \App\Http\Controllers\HealthController::class);
```

- [ ] **Step 5: Run — expect PASS (all).** `php artisan test --filter=ObservabilityAlertingTest`

- [ ] **Step 6: Commit:**

```bash
git add app/Http/Controllers/HealthController.php routes/api.php tests/Feature/ObservabilityAlertingTest.php
git commit -m "feat(observability): public /api/health endpoint"
```

---

## Task 6: Full-suite verification

- [ ] **Step 1: Full backend suite.** `php artisan test` — expect 0 failures, count = branch-point count + the new `ObservabilityAlertingTest` cases. The new `report()` wiring runs on EVERY reportable exception across the suite; if any existing test deliberately triggers a reportable exception AND asserts on mail, check for an unexpected `ExceptionAlertMail` interfering — unlikely (tests that expect 500s rarely `Mail::fake` + assert exact counts), but if one breaks, it's a real interaction to resolve, not to paper over. Report it.
- [ ] **Step 2: Commit** any fix from Step 1 (if none needed, skip).

---

## Self-Review

- **Spec coverage:** config (T1) ✓; ExceptionAlertMail (T2) ✓; throttled `unexpectedException` + ops-email/staff-list resolution + never-throws (T3) ✓; `report()` wiring, no-alert-on-validation/domain (T4) ✓; public boolean-only cached `/api/health` with 200/503 (T5) ✓; failed-job alerting untouched (not a task) ✓; Reverb omitted ✓.
- **Placeholder scan:** none — the blade note and the routes-placement note are conditional guidance, not TODOs; all code is complete.
- **Type/name consistency:** `unexpectedException(Throwable, ?string)`, `opsRecipients()`, `ExceptionAlertMail($class, $message, $path)`, config keys `alerts.ops_email` / `alerts.exception_throttle_minutes`, cache keys `ops-alert:` / `health:probe`, route `/api/health` — all consistent across tasks.
