<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\QuoteState;
use App\Models\PricingConfig;
use App\Models\Quote;
use Illuminate\Support\Carbon;

/**
 * The single source of truth for the buyer chase cadence.
 *
 * ChaseUnansweredOrders reads it to decide when to actually send; the order page
 * reads it to TELL STAFF when the next reminder is due, without duplicating the
 * ladder maths (and the risk of the two answers drifting apart). The command
 * fires the emails; this only ever computes - it never sends or mutates.
 */
class ReminderSchedule
{
    /** Fallback ladders, used until staff set their own on the settings screen. */
    public const DEFAULT_QUOTE_LADDER = [3, 7, 12];

    public const DEFAULT_PROOF_LADDER = [2, 5, 9];

    /**
     * Days after the wait began at which to send each reminder. Configurable via
     * the notifications-cadence config (settings screen).
     *
     * @param  array<int, int>  $default
     * @return array<int, int>
     */
    public function ladder(string $key, array $default): array
    {
        $configured = PricingConfig::value('notifications_cadence', $key, $default);

        return is_array($configured) && $configured !== []
            ? array_map('intval', array_values($configured))
            : $default;
    }

    /**
     * The next buyer chase for an order, or null when none is pending (the order
     * is not in a waiting state, or the proof relation was not loaded to tell).
     *
     * Mirrors ChaseUnansweredOrders exactly: which states wait on what, the clock
     * each wait runs from, and the ladder each uses.
     *
     * @return array{kind: string, reminders_sent: int, reminders_remaining: int, exhausted: bool, next_due_at: ?string, ladder_days: array<int, int>}|null
     */
    public function next(Quote $quote): ?array
    {
        [$kind, $since, $ladder] = $this->pending($quote);

        if ($kind === null) {
            return null;
        }

        $remindersSent = (int) $quote->reminders_sent;
        $total = count($ladder);
        $remaining = max(0, $total - $remindersSent);

        // Ladder exhausted: the machine has stopped and the order is flagged for a
        // person to phone. No further email is scheduled.
        if ($remindersSent >= $total) {
            return [
                'kind' => $kind,
                'reminders_sent' => $remindersSent,
                'reminders_remaining' => 0,
                'exhausted' => true,
                'next_due_at' => null,
                'ladder_days' => $ladder,
            ];
        }

        $dueAt = $since->copy()->addDays($ladder[$remindersSent]);

        return [
            'kind' => $kind,
            'reminders_sent' => $remindersSent,
            'reminders_remaining' => $remaining,
            'exhausted' => false,
            'next_due_at' => $dueAt->toIso8601String(),
            'ladder_days' => $ladder,
        ];
    }

    /**
     * Which wait (if any) an order is in, the clock it runs from, and its ladder.
     *
     * @return array{0: ?string, 1: ?Carbon, 2: array<int, int>}
     */
    private function pending(Quote $quote): array
    {
        $state = $quote->state;

        // Waiting on the buyer to agree a price: SENT, or ARTWORK_APPROVED where
        // the artwork is signed off but the price is not.
        if ($state === QuoteState::Sent || $state === QuoteState::ArtworkApproved) {
            $since = $quote->price_snapshot_at ?? $quote->updated_at;

            return ['price', $since, $this->ladder('quote_days', self::DEFAULT_QUOTE_LADDER)];
        }

        // Waiting on the buyer to sign off artwork - only when a proof is genuinely
        // open. Needs the proofs relation; without it we cannot tell, so say null.
        if ($state === QuoteState::Proofing && $quote->relationLoaded('proofs')) {
            $hasOpenProof = $quote->proofs->contains(
                fn ($proof): bool => $proof->state->value === 'SENT'
            );

            if ($hasOpenProof) {
                $since = $quote->proofs->max('created_at') ?? $quote->updated_at;

                return ['proof', $since, $this->ladder('proof_days', self::DEFAULT_PROOF_LADDER)];
            }
        }

        return [null, null, []];
    }
}
