import { useId, useState } from 'react';
import { Badge, Card, SkeletonText } from '../../ui';
import { humanizeState, quoteStateTone } from '../../lib/quoteStatus';
import type { QuoteHistoryEntry } from '../../lib/quotes';
import type { QuoteHistory } from '../../lib/useQuoteHistory';
import type { QuoteState } from '../../types';

/**
 * Merged order-status card: the at-a-glance position (current state, what's
 * next, step N of 9) with the recorded who/when trail folded in behind a
 * disclosure. Replaces the old two cards - a stepper and a separate "Status
 * history" - which, once the stepper gained per-step dates, said much the same
 * thing twice. The glance is the summary; expand for the ledger, which is the
 * one that can show loops, off-path hops and the actor behind each change.
 */

/** Ordered happy-path lifecycle, for the glance position only. */
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
 * The day transition logging shipped. Orders created before it have no trail and
 * never will, so the empty state names the boundary rather than implying the
 * order never moved. A fixed historical fact - see the old StatusHistory note.
 */
const TRACKING_STARTED = '20 July 2026';

function isOnPath(state: QuoteState): boolean {
  return TIMELINE.indexOf(state) !== -1;
}

/** The state that honestly follows, or null (off-path, or READY as the last). */
function nextState(state: QuoteState): QuoteState | null {
  const i = TIMELINE.indexOf(state);
  if (i === -1 || i === TIMELINE.length - 1) return null;
  return TIMELINE[i + 1];
}

/** Actor label. A transition with no human behind it is a real thing. */
function actorLabel(entry: QuoteHistoryEntry): string {
  return entry.actor_name?.trim() || 'System';
}

/**
 * Date AND time. Orders move through several states in one day - the endpoint
 * orders by (created_at, id) precisely because transitions can share a second -
 * so a bare date would render consecutive entries identically.
 */
function formatChangedAt(changedAt: string | null): string | null {
  if (!changedAt) return null;
  const at = new Date(changedAt);
  if (Number.isNaN(at.getTime())) return null;
  return at.toLocaleString(undefined, {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

export default function OrderStatus({
  state,
  history,
}: {
  state: QuoteState;
  history: QuoteHistory;
}) {
  const [expanded, setExpanded] = useState(false);
  const listId = useId();
  const { entries, loading, failed } = history;

  const onPath = isOnPath(state);
  const next = nextState(state);
  const cancelled = state === 'CANCELLED';
  const complete = state === 'CLOSED';
  const idx = TIMELINE.indexOf(state);

  // API order is oldest first; the most recent change is what a reader wants.
  const newestFirst = [...entries].reverse();

  return (
    <Card padding="md" aria-labelledby="order-status-heading">
      <h2 id="order-status-heading" className="sr-only">
        Order status
      </h2>

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

        {/* CLOSED sits off the path, so it has no slot to count. */}
        {complete && <span className="text-xs text-fg-subtle">All steps complete</span>}

        <button
          type="button"
          aria-expanded={expanded}
          aria-controls={listId}
          onClick={() => setExpanded((v) => !v)}
          className="ml-auto rounded-md text-xs font-medium text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-bg"
        >
          {expanded ? 'Hide history' : 'Show history'}
        </button>
      </div>

      {expanded && (
        <div
          id={listId}
          role="region"
          aria-label="Status history"
          aria-busy={loading}
          className="mt-4 border-t border-border pt-4"
        >
          <h3 className="text-sm font-medium text-fg">Status history</h3>

          {loading ? (
            <SkeletonText lines={2} className="mt-3" />
          ) : newestFirst.length > 0 ? (
            <ul className="mt-3 flex flex-col divide-y divide-border">
              {newestFirst.map((entry, i) => {
                const when = formatChangedAt(entry.changed_at);
                return (
                  <li
                    key={`${entry.changed_at ?? 'unknown'}-${entry.to ?? 'unknown'}-${i}`}
                    className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 py-3 first:pt-0"
                  >
                    <span className="font-medium text-fg">
                      {entry.to ? humanizeState(entry.to) : 'Unknown status'}
                    </span>
                    <span className="flex flex-wrap items-baseline gap-x-3 text-sm text-fg-muted">
                      {when && <time dateTime={entry.changed_at ?? undefined}>{when}</time>}
                      <span>{actorLabel(entry)}</span>
                    </span>
                  </li>
                );
              })}
            </ul>
          ) : failed ? (
            <p className="mt-3 text-sm text-fg-muted">Couldn’t load the status history.</p>
          ) : (
            <p className="mt-3 text-sm text-fg-muted">
              Status tracking started on {TRACKING_STARTED}. Changes before then were not recorded.
            </p>
          )}
        </div>
      )}
    </Card>
  );
}
