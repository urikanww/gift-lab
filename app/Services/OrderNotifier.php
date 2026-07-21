<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderMilestone;
use App\Enums\QuoteState;
use App\Enums\UserRole;
use App\Mail\OrderMilestoneMail;
use App\Models\PricingConfig;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Tells the buyer what has happened to their order.
 *
 * The application used to send two emails in total, so every other milestone
 * was a phone call somebody had to remember to make. This is that job.
 *
 * Driven from Quote::transitionTo(), the single choke point every state change
 * passes through, rather than from the twelve call sites - a milestone cannot
 * then be missed because a new code path forgot to announce itself.
 */
class OrderNotifier
{
    /** Config group holding the per-milestone on/off switches. */
    private const SETTINGS_GROUP = 'notifications';

    /**
     * State changes that are worth telling a buyer about, and what to call them.
     * States absent from this map are internal bookkeeping (INVOICED is never
     * observable; PROCURING means nothing to a buyer) and stay silent.
     *
     * @var array<string, OrderMilestone>
     */
    private const STATE_MILESTONES = [
        QuoteState::Accepted->value => OrderMilestone::Accepted,
        QuoteState::ArtworkApproved->value => OrderMilestone::ArtworkApproved,
        QuoteState::Confirmed->value => OrderMilestone::Committed,
        QuoteState::Ready->value => OrderMilestone::InProduction,
        QuoteState::Closed->value => OrderMilestone::Delivered,
        QuoteState::Cancelled->value => OrderMilestone::Cancelled,
    ];

    /**
     * Announce a state change, if that state has anything to say.
     *
     * Never throws: a mail failure must not roll back the transition that
     * prompted it. An order that advanced but failed to notify is recoverable;
     * an order that failed to advance because an SMTP host was down is not.
     */
    public function stateChanged(Quote $quote, QuoteState $to): void
    {
        $milestone = self::STATE_MILESTONES[$to->value] ?? null;

        if ($milestone === null) {
            return;
        }

        $this->send($quote, $milestone);
    }

    public function send(Quote $quote, OrderMilestone $milestone): void
    {
        if (! $this->isEnabled($milestone)) {
            return;
        }

        $recipient = $this->resolveBuyerRecipient($quote);

        if ($recipient?->email === null) {
            // Nothing to do, but worth knowing about: a company with no buyer
            // account hears nothing at all, and that looks like working software.
            Log::info('Order milestone not sent: no buyer recipient.', [
                'quote_id' => $quote->id,
                'milestone' => $milestone->value,
            ]);

            return;
        }

        try {
            Mail::to($recipient->email)->queue(new OrderMilestoneMail($quote, $milestone));
        } catch (\Throwable $e) {
            Log::error('Order milestone email failed to queue.', [
                'quote_id' => $quote->id,
                'milestone' => $milestone->value,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Queue the announcement only once the surrounding transaction commits, so a
     * rolled-back transition never emails a buyer about something that did not
     * happen.
     */
    public function stateChangedAfterCommit(Quote $quote, QuoteState $to): void
    {
        DB::afterCommit(fn () => $this->stateChanged($quote, $to));
    }

    /**
     * Per-milestone switch, settable without a deploy. Absent config falls back
     * to the milestone's own default, so a newly added milestone starts sending
     * without needing a row written for it first.
     */
    public function isEnabled(OrderMilestone $milestone): bool
    {
        $configured = PricingConfig::value(self::SETTINGS_GROUP, $milestone->value);

        return $configured === null
            ? $milestone->enabledByDefault()
            : (bool) $configured;
    }

    /**
     * The buyer to write to.
     *
     * Deliberately a person, not the company's shared billing_email: every CTA
     * in these emails is login-gated, so a shared inbox nobody signs in from is
     * a dead end. Invoicing may want the billing address once invoice documents
     * exist - that is a separate decision, recorded in the plan doc.
     */
    private function resolveBuyerRecipient(Quote $quote): ?User
    {
        $creator = $quote->creator;
        if ($creator !== null
            && $creator->email !== null
            && $creator->company_id === $quote->company_id) {
            return $creator;
        }

        return User::query()
            ->where('company_id', $quote->company_id)
            ->where('role', UserRole::Buyer->value)
            ->whereNotNull('email')
            ->orderBy('id')
            ->first();
    }
}
