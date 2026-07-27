<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UserRole;
use App\Events\JobFailedAlert;
use App\Events\ProofChangesRequested;
use App\Mail\JobFailedAlertMail;
use App\Mail\ProofChangesRequestedMail;
use App\Models\Proof;
use App\Models\User;
use App\Support\Broadcasting;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Tells the internal team when a buyer does something that needs a staff hand.
 *
 * Counterpart to OrderNotifier (which writes to the buyer). Where OrderNotifier
 * fires on milestones the buyer cares about, this fires on the handful of buyer
 * actions that put the ball back in staff's court - today, a proof sent back for
 * changes. Two channels: an email to every operator, and a live push to the
 * shared staff.queue channel (surfaced as a toast + Quotes badge in the console).
 *
 * Never throws: a mail or broadcast failure must not roll back the buyer's
 * request. The action is already committed; failing to announce it is recoverable.
 */
class StaffNotifier
{
    /**
     * Announce that a buyer sent a proof back for changes. Call AFTER the
     * surrounding transaction has committed (the proof/quote state must be
     * settled before staff are told to act on it).
     */
    public function proofChangesRequested(Proof $proof): void
    {
        // Push (live) to the staff console. Swallows transport failures so a
        // Reverb outage can't turn a committed request into a 500.
        Broadcasting::dispatch(fn () => ProofChangesRequested::dispatch($proof));

        // Email every internal operator with an address on file.
        $recipients = User::query()
            ->whereIn('role', [UserRole::StaffAdmin->value, UserRole::Superadmin->value])
            ->whereNotNull('email')
            ->pluck('email')
            ->all();

        if ($recipients === []) {
            Log::info('Proof changes-requested: no staff recipient to email.', [
                'proof_id' => $proof->id,
                'quote_id' => $proof->quote_id,
            ]);

            return;
        }

        $quote = $proof->quote;

        foreach ($recipients as $email) {
            try {
                Mail::to($email)->queue(new ProofChangesRequestedMail($quote, $proof));
            } catch (\Throwable $e) {
                Log::error('Proof changes-requested staff email failed to queue.', [
                    'proof_id' => $proof->id,
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Reentrancy guard - see jobFailed() for why this exists. STATIC and not
     * an instance property: AppServiceProvider resolves a fresh instance per
     * app(StaffNotifier::class) call (it isn't bound as a singleton), and the
     * reentrant call this guards against comes through that same resolution
     * path - an instance property on a brand-new object would never see it.
     */
    private static bool $handlingJobFailure = false;

    /**
     * Announce that a queued job exhausted its retries and landed in the
     * dead-letter table (failed_jobs). This is the ONE place a failed-job
     * staff alert fires from - registered once via Queue::failing() in
     * AppServiceProvider::boot(). Per-job failed() handlers (e.g.
     * EnrichImportedModel3dProduct::failed()) only log; they must never also
     * alert staff, or every failure would double-notify.
     *
     * MUST NEVER THROW: this runs inside the queue worker's failure pipeline
     * (and, under the sync driver, inline in the original request/command).
     * An exception escaping here would compound an already-failed job with a
     * broken failure handler, so every step is defensive and the whole body
     * is wrapped as a last-resort safety net.
     *
     * REENTRANCY GUARD: under QUEUE_CONNECTION=sync, queuing the staff email
     * below (Mail::...->queue()) runs its SendQueuedMailable job inline; if
     * THAT job throws (e.g. the mail transport is down), Queue::failing()
     * fires again from inside this very call, which would otherwise call
     * back into alertStaffOfJobFailure() - trying to send the same failing
     * email again, forever. The guard makes any such reentrant call a
     * cheap, non-alerting log instead of a fresh alert attempt.
     */
    public function jobFailed(JobFailed $e): void
    {
        if (self::$handlingJobFailure) {
            Log::error('Job-failed alert pipeline itself failed while alerting; not re-alerting to avoid a recursive loop.', [
                'connection' => $e->connectionName,
                'exception' => $e->exception->getMessage(),
            ]);

            return;
        }

        self::$handlingJobFailure = true;

        try {
            $this->alertStaffOfJobFailure($e);
        } catch (Throwable $unexpected) {
            // Should be unreachable (every step below already guards itself),
            // but this is the dead-letter alert path itself - it must never
            // be the thing that throws.
            Log::error('StaffNotifier::jobFailed encountered an unexpected error; alert suppressed.', [
                'error' => $unexpected->getMessage(),
            ]);
        } finally {
            self::$handlingJobFailure = false;
        }
    }

    private function alertStaffOfJobFailure(JobFailed $e): void
    {
        $jobName = null;
        $uuid = null;

        try {
            $jobName = $e->job?->resolveName();
            $uuid = $e->job?->uuid();
        } catch (Throwable $resolveError) {
            Log::warning('Job-failed alert: could not resolve job name/uuid.', [
                'error' => $resolveError->getMessage(),
            ]);
        }

        $jobName ??= 'unknown';
        $queue = null;
        try {
            $queue = $e->job?->getQueue();
        } catch (Throwable) {
            // Defensive; getQueue() should never throw, but this path must not.
        }
        $queue ??= 'default';

        Log::error('Queued job failed and exhausted its retries.', [
            'job' => $jobName,
            'uuid' => $uuid,
            'connection' => $e->connectionName,
            'queue' => $queue,
            'exception' => $e->exception->getMessage(),
        ]);

        // Push (live) to the staff console. Swallows transport failures so a
        // Reverb outage can't turn this into a fresh exception.
        Broadcasting::dispatch(fn () => JobFailedAlert::dispatch(
            $jobName,
            $uuid,
            $e->connectionName,
            $queue,
            $e->exception->getMessage(),
        ));

        // Email every internal operator with an address on file.
        $recipients = User::query()
            ->whereIn('role', [UserRole::StaffAdmin->value, UserRole::Superadmin->value])
            ->whereNotNull('email')
            ->pluck('email')
            ->all();

        if ($recipients === []) {
            Log::info('Job-failed alert: no staff recipient to email.', [
                'job' => $jobName,
                'uuid' => $uuid,
            ]);

            return;
        }

        foreach ($recipients as $email) {
            try {
                Mail::to($email)->queue(new JobFailedAlertMail(
                    $jobName,
                    $uuid,
                    $e->connectionName,
                    $queue,
                    $e->exception->getMessage(),
                ));
            } catch (Throwable $mailError) {
                Log::error('Job-failed staff email failed to queue.', [
                    'job' => $jobName,
                    'email' => $email,
                    'error' => $mailError->getMessage(),
                ]);
            }
        }
    }
}
