import { useEffect, useState } from 'react';
import { Card, SkeletonText } from '../../ui';
import { humanizeState } from '../../lib/quoteStatus';
import { fetchQuoteHistory, type QuoteHistoryEntry } from '../../lib/quotes';
import type { QuoteState } from '../../types';

/**
 * The day transition logging shipped. Every order created before this has no
 * recorded history and can never acquire one, so the empty state names the
 * boundary instead of implying the order never moved.
 *
 * This is a fixed historical fact, NOT a "last updated" marker. Bumping it
 * forward would silently misdate the boundary and tell buyers their real,
 * recorded history predates tracking. It changes only if the logging release
 * date itself is corrected.
 */
const TRACKING_STARTED = '20 July 2026';

/** Actor label. A transition with no human behind it is a real thing. */
function actorLabel(entry: QuoteHistoryEntry): string {
  return entry.actor_name?.trim() || 'System';
}

/**
 * Date AND time. Orders routinely move through several states in one day - the
 * endpoint orders by (created_at, id) precisely because transitions can share a
 * second - so a date alone renders consecutive entries identically and tells the
 * buyer nothing about how long anything took.
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

/**
 * Buyer-facing trail of recorded state changes, newest first. Deliberately
 * renders only what the API logged: no partial history is reconstructed from
 * other fields on the order, because a timeline showing two entries and looking
 * complete is worse than one that admits it is empty.
 */
export default function StatusHistory({
  reference,
  state,
}: {
  reference: string;
  /**
   * The order's current state. Not rendered - it exists so this component
   * refetches when the order moves. `reference` is fixed for the page's whole
   * lifetime, so keying the fetch on it alone left the buyer reading a history
   * whose newest entry contradicted the status badge directly above it.
   * Required, not optional: a call site that forgets it reintroduces exactly
   * that bug, silently.
   */
  state: QuoteState;
}) {
  const [entries, setEntries] = useState<QuoteHistoryEntry[]>([]);
  // Distinct from "resolved empty". Both render no list, but only one of them
  // justifies claiming the order predates tracking.
  const [failed, setFailed] = useState(false);
  // Third distinct case: we haven't been told yet. Starts true so the first
  // paint doesn't flash the tracking-boundary explanation before the fetch has
  // answered - that copy asserts a fact about the ORDER, and mid-flight we have
  // no facts about the order at all.
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let active = true;
    // Set INSIDE the effect, not just as the initial state: this effect re-runs
    // on every state change, and a refetch that kept showing the pre-change
    // trail would be a smaller replay of the staleness this key exists to fix.
    setLoading(true);
    // The fetcher rejects on failure; swallowing it here is what keeps the
    // history best-effort. This component must never throw an unhandled
    // rejection into the order page.
    fetchQuoteHistory(reference)
      .then((rows) => {
        if (!active) return;
        setEntries(Array.isArray(rows) ? rows : []);
        setFailed(false);
        setLoading(false);
      })
      .catch(() => {
        if (!active) return;
        setEntries([]);
        setFailed(true);
        setLoading(false);
      });
    return () => {
      // Guards a late settle after unmount, and equally a stale in-flight
      // request superseded by a newer state change - neither may write.
      active = false;
    };
  }, [reference, state]);

  // API order is oldest first; the most recent change is what a buyer wants.
  const newestFirst = [...entries].reverse();

  return (
    <Card padding="lg" aria-labelledby="history-heading" aria-busy={loading}>
      <h2 id="history-heading" className="font-display text-xl text-fg">
        Status history
      </h2>

      {loading ? (
        // Say nothing until the fetch answers. Skeleton lines match how the
        // page's other sections wait (see QuoteDetailSkeleton); they are
        // decorative, so the card carries aria-busy for assistive tech.
        <SkeletonText lines={2} className="mt-4" />
      ) : newestFirst.length > 0 ? (
        <ul className="mt-4 flex flex-col divide-y divide-border">
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
                  {/* The raw instant stays machine-readable; the visible text is
                      formatted in the reader's own locale and timezone. */}
                  {when && <time dateTime={entry.changed_at ?? undefined}>{when}</time>}
                  <span>{actorLabel(entry)}</span>
                </span>
              </li>
            );
          })}
        </ul>
      ) : failed ? (
        // Stays quiet and supplementary - never an error banner competing with
        // the order details. It says only what we know: we don't have it. The
        // tracking-started copy would assert a CAUSE we cannot know here.
        <p className="mt-3 text-sm text-fg-muted">Couldn’t load the status history.</p>
      ) : (
        <p className="mt-3 text-sm text-fg-muted">
          Status tracking started on {TRACKING_STARTED}. Changes before then were not recorded.
        </p>
      )}
    </Card>
  );
}
