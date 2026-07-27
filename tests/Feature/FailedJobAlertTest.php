<?php

declare(strict_types=1);

use App\Events\JobFailedAlert;
use App\Mail\JobFailedAlertMail;
use App\Models\User;
use App\Services\StaffNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

// Global dead-letter alert (Task 9): Queue::failing() -> StaffNotifier::jobFailed()
// is registered once in AppServiceProvider::boot() and must be the ONE place a
// failed-job staff alert fires from, must email exactly the staff/superadmin
// operators (never a buyer), and must never itself throw - even if the mail
// transport is down.

/** A throwaway job that always fails, to exercise the real Queue::failing() pipeline. */
class FailedJobAlertTestJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(): void
    {
        throw new RuntimeException('Deliberate failure for FailedJobAlertTest.');
    }

    public function failed(Throwable $e): void
    {
        // Intentionally does nothing extra: the global Queue::failing() hook
        // owns the one staff alert, per-job handlers must only log (or, here,
        // nothing at all).
    }
}

it('alerts staff exactly once when a queued job exhausts its retries and fails', function (): void {
    Mail::fake();
    Event::fake([JobFailedAlert::class]);

    // The two staff roles that must be alerted.
    User::factory()->staffAdmin()->create(['email' => 'ops@nexgen.test']);
    User::factory()->create(['role' => 'superadmin', 'email' => 'boss@nexgen.test']);
    // A buyer must never be a recipient.
    User::factory()->create(['role' => 'buyer', 'email' => 'buyer@nexgen.test']);

    try {
        // QUEUE_CONNECTION=sync in tests: the sync driver invokes the job's
        // failure pipeline (which fires Queue::failing()) and THEN re-throws
        // the original exception to the caller.
        FailedJobAlertTestJob::dispatch();
    } catch (Throwable) {
        // Expected under the sync driver; the alert already fired above.
    }

    Mail::assertQueued(JobFailedAlertMail::class, 2);
    Event::assertDispatched(JobFailedAlert::class);
});

it('never throws even when the mail transport itself fails, and still logs the failure', function (): void {
    Event::fake([JobFailedAlert::class]);
    Log::spy();

    User::factory()->staffAdmin()->create(['email' => 'ops@nexgen.test']);

    Mail::shouldReceive('to->queue')->andThrow(new RuntimeException('SMTP transport down.'));

    $job = Mockery::mock(QueueJobContract::class);
    $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\SomeJob');
    $job->shouldReceive('uuid')->andReturn('11111111-1111-1111-1111-111111111111');
    $job->shouldReceive('getQueue')->andReturn('default');

    $event = new JobFailed('database', $job, new RuntimeException('The job blew up.'));

    // Must not throw, despite the mail transport throwing internally.
    app(StaffNotifier::class)->jobFailed($event);

    Log::shouldHaveReceived('error')->withArgs(
        fn (string $message) => $message === 'Queued job failed and exhausted its retries.'
    )->once();
    Log::shouldHaveReceived('error')->withArgs(
        fn (string $message) => $message === 'Job-failed staff email failed to queue.'
    )->once();
});
