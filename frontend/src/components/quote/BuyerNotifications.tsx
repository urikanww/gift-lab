import type { MilestoneKey, Quote } from '../../types';
import type { QuoteHistoryEntry } from '../../lib/quotes';

/**
 * Staff-facing notification panel for the order page: what the buyer was told on
 * reaching the current state, and when the next automatic chase is due. Answers
 * the question staff otherwise have to guess - "has the buyer heard from us, and
 * when will they hear next?" - without opening the mail logs.
 *
 * The "sent" time is not stored per email; it is the moment the order entered
 * its current state, read from the status trail. The "next" side comes from the
 * server, which owns the chase-ladder maths (see ReminderSchedule).
 */

/** Short label for the buyer email tied to a state. */
const MILESTONE_LABEL: Record<MilestoneKey, string> = {
  accepted: 'Acceptance received',
  artwork_approved: 'Artwork approved',
  proof_issued: 'Proof ready to review',
  committed: 'Order confirmed',
  in_production: 'In production',
  shipped: 'On its way',
  delivered: 'Delivered',
  cancelled: 'Order cancelled',
  line_changed: 'Order changed',
  reminder_price: 'Quote reminder',
  reminder_proof: 'Proof reminder',
};

/** What the buyer is being chased to do, for the "due" line. */
const CHASE_ACTION: Record<'price' | 'proof', string> = {
  price: 'accepts the pricing',
  proof: 'approves the proof',
};

function formatInstant(iso: string | null | undefined): string | null {
  if (!iso) return null;
  const at = new Date(iso);
  if (Number.isNaN(at.getTime())) return null;
  return at.toLocaleString(undefined, {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

function formatDay(iso: string | null | undefined): string | null {
  if (!iso) return null;
  const at = new Date(iso);
  if (Number.isNaN(at.getTime())) return null;
  return at.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
}

export default function BuyerNotifications({
  quote,
  history,
}: {
  quote: Quote;
  history: QuoteHistoryEntry[];
}) {
  const reminder = quote.reminder;
  // Staff-only field; a buyer payload (or an older API) simply omits the panel.
  if (!reminder) return null;

  const milestone = reminder.current_milestone;
  // When the current state entered its state = when the milestone email went out.
  // The history trail is oldest-first; the last transition INTO the current state
  // is the most recent one whose target is this state.
  const enteredAt = [...history].reverse().find((e) => e.to === quote.state)?.changed_at ?? null;
  const sentWhen = formatInstant(enteredAt);

  const next = reminder.next;

  return (
    <div className="rounded-md border border-border bg-surface-2/40 p-4">
      <h3 className="text-sm font-medium text-fg">Buyer notifications</h3>

      {/* What the buyer was told on reaching this state. */}
      <div className="mt-3 flex items-start gap-2 text-sm">
        <span aria-hidden="true" className="mt-0.5 text-fg-subtle">
          ✉
        </span>
        <p className="text-fg-muted">
          {milestone && reminder.current_milestone_enabled ? (
            <>
              <span className="font-medium text-fg">{MILESTONE_LABEL[milestone]}</span> email sent to
              the buyer
              {sentWhen ? (
                <>
                  {' '}
                  on <span className="font-medium text-fg">{sentWhen}</span>
                </>
              ) : (
                ' when the order reached this step'
              )}
              .
            </>
          ) : milestone && !reminder.current_milestone_enabled ? (
            <>The buyer notification for this step is switched off, so no email was sent.</>
          ) : (
            <>No buyer email is sent at this step.</>
          )}
        </p>
      </div>

      {/* When the buyer will next hear from us automatically. */}
      <div className="mt-2 flex items-start gap-2 text-sm">
        <span aria-hidden="true" className="mt-0.5 text-fg-subtle">
          ⏱
        </span>
        <p className="text-fg-muted">
          {!next ? (
            <>No automatic reminder is scheduled from this step.</>
          ) : next.exhausted ? (
            <>
              All {next.reminders_sent} automatic reminders have been sent — this order is flagged for
              a staff member to follow up by phone. No further emails will go out.
            </>
          ) : (
            <>
              Next reminder:{' '}
              <span className="font-medium text-fg">
                {next.kind === 'proof' ? 'proof' : 'price'} chase
              </span>{' '}
              due{' '}
              <span className="font-medium text-fg">{formatDay(next.next_due_at) ?? 'soon'}</span>,
              sent only if the buyer hasn’t {CHASE_ACTION[next.kind]} by then. Reminders go out on a{' '}
              {next.ladder_days.join(' / ')}-day ladder (checked daily at 9am);{' '}
              {next.reminders_sent} sent, {next.reminders_remaining} left.
            </>
          )}
        </p>
      </div>
    </div>
  );
}
