import { useState } from 'react';
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

/**
 * Where a state sits on the happy path, for *rendering the stepper only*.
 *
 * Off-path states (CHANGES_REQUESTED, CLOSED, CANCELLED) have no real position,
 * so they are pinned to an end of the path to keep the stepper coherent. That
 * pinning is a layout convenience and nothing more - it must never be read back
 * as "what happens next", or a cancelled order would cheerfully announce that
 * it is about to be sent. Use `nextState` for that question instead.
 */
function timelineIndex(state: QuoteState): number {
  const i = TIMELINE.indexOf(state);
  if (i !== -1) return i;
  if (state === 'CLOSED') return TIMELINE.length - 1;
  return 0;
}

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
 * Buyer-facing status timeline. Collapsed by default to current state, next
 * state and position - the full nine-step stepper wrapped to two rows at
 * desktop width and dwarfed the order it was describing.
 */
export default function QuoteTimeline({ state }: { state: QuoteState }) {
  const [expanded, setExpanded] = useState(false);

  const onPath = isOnPath(state);
  const next = nextState(state);
  const currentIdx = timelineIndex(state);
  const cancelled = state === 'CANCELLED';

  return (
    <Card padding="md">
      <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
        <Badge tone={quoteStateTone(state)} dot>
          {humanizeState(state)}
        </Badge>

        {next && (
          <span className="text-sm text-fg-muted">→ next: {humanizeState(next)}</span>
        )}

        {cancelled && <span className="text-sm text-fg-muted">This quote was cancelled.</span>}

        {onPath && (
          <span className="text-xs text-fg-subtle">
            step {currentIdx + 1} of {TIMELINE.length}
          </span>
        )}

        {/* A cancelled order has no path left to walk, so there is nothing to
            disclose - matches the stepper-free card this state shipped with. */}
        {!cancelled && (
          <button
            type="button"
            aria-expanded={expanded}
            onClick={() => setExpanded((v) => !v)}
            className="ml-auto rounded-md text-xs font-medium text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-bg"
          >
            {expanded ? 'Hide all steps' : 'Show all steps'}
          </button>
        )}
      </div>

      {!cancelled && expanded && (
        <ol className="mt-4 flex flex-wrap items-center gap-x-2 gap-y-3">
          {TIMELINE.map((step, i) => {
            const done = i < currentIdx;
            const active = i === currentIdx;
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
                <span
                  className={
                    'text-xs ' + (active ? 'font-semibold text-fg' : done ? 'text-fg-muted' : 'text-fg-subtle')
                  }
                >
                  {humanizeState(step)}
                  {active && <span className="sr-only"> (current status)</span>}
                </span>
                {i < TIMELINE.length - 1 && <span className="mx-1 h-px w-4 bg-border" aria-hidden="true" />}
              </li>
            );
          })}
        </ol>
      )}
    </Card>
  );
}
