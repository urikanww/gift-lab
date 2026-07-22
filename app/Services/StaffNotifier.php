<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UserRole;
use App\Events\ProofChangesRequested;
use App\Mail\ProofChangesRequestedMail;
use App\Models\Proof;
use App\Models\User;
use App\Support\Broadcasting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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
}
