<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\OrderMilestone;
use App\Enums\QuoteState;
use App\Models\Quote;
use App\Services\AuditLogger;
use App\Services\OrderNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Chases buyers who have gone quiet.
 *
 * Nothing chased anything before this. A SENT quote or an unanswered proof sat
 * forever with no nudge to either side, so staff carried it by memory - which
 * is exactly the work this whole wave exists to stop.
 *
 * Two ladders, because the two waits are not equally urgent. An unread quote
 * can wait a few days. An unanswered proof is holding up production, and is
 * usually one person forgetting, so it is chased sooner and harder.
 *
 * The ladder ends deliberately. After the last rung the order is flagged for a
 * human to phone, and the machine stops writing - a buyer who has ignored three
 * emails will ignore the fourth, and continuing to send them is how a sender
 * ends up in a spam folder.
 */
class ChaseUnansweredOrders extends Command
{
    protected $signature = 'quotes:chase';

    protected $description = 'Remind buyers about unanswered quotes and proofs, then flag them for staff.';

    /** Days after the wait began at which to send each reminder. */
    private const QUOTE_LADDER = [3, 7, 12];

    private const PROOF_LADDER = [2, 5, 9];

    public function handle(OrderNotifier $notifier, AuditLogger $audit): int
    {
        $sent = 0;
        $flagged = 0;

        // Waiting on the buyer to agree a price: from SENT, and from
        // ARTWORK_APPROVED where the artwork is signed off but the price is not.
        foreach ($this->waitingOnPrice() as $quote) {
            $since = $quote->price_snapshot_at ?? $quote->updated_at;
            [$didSend, $didFlag] = $this->chase($quote, $since, self::QUOTE_LADDER, $notifier, $audit, 'price');
            $sent += (int) $didSend;
            $flagged += (int) $didFlag;
        }

        // Waiting on the buyer to sign off artwork.
        foreach ($this->waitingOnProof() as $quote) {
            $since = $quote->proofs->max('created_at') ?? $quote->updated_at;
            [$didSend, $didFlag] = $this->chase($quote, $since, self::PROOF_LADDER, $notifier, $audit, 'proof');
            $sent += (int) $didSend;
            $flagged += (int) $didFlag;
        }

        $this->info("Chased {$sent} order(s); flagged {$flagged} for staff follow-up.");

        return self::SUCCESS;
    }

    /**
     * @return \Illuminate\Support\Collection<int, Quote>
     */
    private function waitingOnPrice(): \Illuminate\Support\Collection
    {
        return Quote::query()
            ->whereIn('state', [QuoteState::Sent->value, QuoteState::ArtworkApproved->value])
            ->get();
    }

    /**
     * @return \Illuminate\Support\Collection<int, Quote>
     */
    private function waitingOnProof(): \Illuminate\Support\Collection
    {
        return Quote::query()
            ->where('state', QuoteState::Proofing->value)
            ->with('proofs')
            ->get()
            // Only when a proof is genuinely open. A quote sitting in PROOFING
            // with every proof already decided is waiting on staff, not on the
            // buyer, and chasing them for it would be wrong.
            ->filter(fn (Quote $quote): bool => $quote->proofs->contains(
                fn ($proof): bool => $proof->state->value === 'SENT'
            ));
    }

    /**
     * @param  array<int, int>  $ladder
     * @return array{bool, bool} [sent, flagged]
     */
    private function chase(
        Quote $quote,
        Carbon $since,
        array $ladder,
        OrderNotifier $notifier,
        AuditLogger $audit,
        string $waitingFor,
    ): array {
        $daysWaiting = (int) $since->diffInDays(now());
        $alreadySent = (int) $quote->reminders_sent;

        // Ladder exhausted: hand it to a person once, and record that we did, so
        // the flag is not re-raised every night.
        if ($alreadySent >= count($ladder)) {
            return [false, false];
        }

        $dueAt = $ladder[$alreadySent];
        if ($daysWaiting < $dueAt) {
            return [false, false];
        }

        $isFinalRung = $alreadySent === count($ladder) - 1;

        $notifier->send($quote, $waitingFor === 'proof'
            ? OrderMilestone::ReminderProof
            : OrderMilestone::ReminderPrice);

        $quote->reminders_sent = $alreadySent + 1;
        $quote->last_reminded_at = now();
        $quote->save();

        if ($isFinalRung) {
            $audit->log($quote, 'quote.chase_exhausted', null, [
                'waiting_for' => $waitingFor,
                'days_waiting' => $daysWaiting,
                'reminders_sent' => $quote->reminders_sent,
            ]);
        }

        return [true, $isFinalRung];
    }
}
