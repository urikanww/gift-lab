import { useId, useState } from 'react';
import { Badge, Card } from '../../ui';
import { humanizeState, quoteStateTone } from '../../lib/quoteStatus';
import type { QuoteState } from '../../types';

/** Ordered happy-path lifecycle used to render the status timeline. */
const TIMELINE: QuoteState[] = [
  'DRAFT',
  'SENT',
  'ACCEPTED',
  'PROOFING',
  'PROOF_APPROVED',
  'INVOICED',
  'CONFIRMED',
  'PROCURING',
  'READY',
];

/** True only for states that genuinely occupy a slot on the happy path. */
function isOnPath(state: QuoteState): boolean {
  return TIMELINE.indexOf(state) !== -1;
}

/**
 * The state that actually follows this one, or null when there is nothing
 * honest to promise: off-path states, and READY as the genuine last step.
 */
function nextState(state: QuoteState): QuoteState | null {
  const i = TIMELINE.indexOf(state);
  if (i === -1 || i === TIMELINE.length - 1) return null;
  return TIMELINE[i + 1];
}

/**
 * Short date + time a step was reached, for the expanded stepper. Same
 * date-AND-time reasoning as the status history: several steps can land on one
 * day, so a bare date would render them identically.
 */
function formatStepTime(at: string | null | undefined): string | null {
  if (!at) return null;
  const d = new Date(at);
  if (Number.isNaN(d.getTime())) return null;
  return d.toLocaleString(undefined, {
    day: 'numeric',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
  });
}

/**
 * Buyer-facing status timeline. Collapsed by default to current state, next
 * state and position - the full nine-step stepper wrapped to two rows at
 * desktop width and dwarfed the order it was describing.
 *
 * `timestamps` (optional) maps a state to the instant the order reached it, so
 * the expanded stepper reads as a dated history rather than a bare checklist.
 * A step with no recorded instant simply shows no date - future steps never
 * have one, and older orders may predate transition logging entirely.
 */
export default function QuoteTimeline({
  state,
  timestamps,
}: {
  state: QuoteState;
  timestamps?: Partial<Record<QuoteState, string>>;
}) {
  const [expanded, setExpanded] = useState(false);
  // The component can render more than once on a page, so the disclosure
  // target needs a unique id rather than a hardcoded one.
  const listId = useId();

  const onPath = isOnPath(state);
  const next = nextState(state);
  const cancelled = state === 'CANCELLED';
  // CLOSED is the one terminal state that means "finished well". Checked
  // explicitly rather than derived from a position, so that no new route
  // exists by which an index could masquerade as a forward-looking claim.
  const complete = state === 'CLOSED';

  const idx = TIMELINE.indexOf(state);
  // Only a state that genuinely occupies a slot may mark one as current.
  // Off-path states get -1, so every step falls through to "upcoming" and
  // nothing announces itself as the current status - a changes-requested
  // order used to render DRAFT as active, which a screen reader read aloud
  // as "Draft (current status)".
  const activeIdx = onPath ? idx : -1;
  // Leading steps drawn as done: everything before the current step, or the
  // whole path once the order is closed.
  const doneCount = complete ? TIMELINE.length : onPath ? idx : 0;

  return (
    <Card padding="md">
      <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
        <Badge tone={quoteStateTone(state)} dot>
          {humanizeState(state)}
        </Badge>

        {next && (
          <span className="text-sm text-fg-muted">
            <span aria-hidden="true">→</span> next: {humanizeState(next)}
          </span>
        )}

        {cancelled && <span className="text-sm text-fg-muted">This quote was cancelled.</span>}

        {onPath && (
          <span className="text-xs text-fg-subtle">
            step {idx + 1} of {TIMELINE.length}
          </span>
        )}

        {/* CLOSED sits off the path, so it has no slot to count - saying
            "step 9 of 9" would hand READY's position to a second state. */}
        {complete && <span className="text-xs text-fg-subtle">All steps complete</span>}

        {/* No disclosure for a cancelled order, deliberately asymmetric with
            the other off-path state. CHANGES_REQUESTED is live and will rejoin
            the path, so its stepper still describes something real. CANCELLED
            is over, and with no current step its stepper would draw nine empty
            circles - reading as "no progress was ever made", which is wrong for
            an order cancelled at, say, the proofing stage. How far it actually
            got lives in the transition history, not in the current state, so
            the status history section is where that belongs. */}
        {!cancelled && (
          <button
            type="button"
            aria-expanded={expanded}
            aria-controls={listId}
            onClick={() => setExpanded((v) => !v)}
            className="ml-auto rounded-md text-xs font-medium text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-bg"
          >
            {expanded ? 'Hide all steps' : 'Show all steps'}
          </button>
        )}
      </div>

      {!cancelled && expanded && (
        <div id={listId}>
          {/* An off-path order has no current step to point at, so say why
              rather than leaving nine untouched circles unexplained. */}
          {!onPath && !complete && (
            <p className="mt-3 text-xs text-fg-muted">Your order has left the standard path.</p>
          )}
          <ol className="mt-4 flex flex-wrap items-center gap-x-2 gap-y-3">
            {TIMELINE.map((step, i) => {
              const done = i < doneCount;
              const active = i === activeIdx;
              const rawWhen = timestamps?.[step];
              // Only steps that have actually happened carry an instant. Never
              // date an upcoming step, even if a timestamp somehow exists for it.
              const when = done || active ? formatStepTime(rawWhen) : null;
              return (
                <li key={step} className="flex items-center gap-2">
                  <span
                    className={
                      'flex h-6 w-6 items-center justify-center rounded-full text-2xs font-semibold ' +
                      (active
                        ? 'bg-primary text-primary-fg'
                        : done
                          ? 'bg-success text-white'
                          : 'bg-surface-2 text-fg-subtle')
                    }
                    aria-hidden="true"
                  >
                    {done ? '✓' : i + 1}
                  </span>
                  <span className="flex flex-col leading-tight">
                    <span
                      className={
                        'text-xs ' +
                        (active ? 'font-semibold text-fg' : done ? 'text-fg-muted' : 'text-fg-subtle')
                      }
                    >
                      {humanizeState(step)}
                      {active && <span className="sr-only"> (current status)</span>}
                    </span>
                    {when && (
                      <time dateTime={rawWhen} className="text-2xs tabular-nums text-fg-subtle">
                        {when}
                      </time>
                    )}
                  </span>
                  {i < TIMELINE.length - 1 && <span className="mx-1 h-px w-4 bg-border" aria-hidden="true" />}
                </li>
              );
            })}
          </ol>
        </div>
      )}
    </Card>
  );
}
