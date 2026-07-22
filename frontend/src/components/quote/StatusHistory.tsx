import { Card, SkeletonText } from '../../ui';
import { humanizeState } from '../../lib/quoteStatus';
import type { QuoteHistoryEntry } from '../../lib/quotes';
import type { QuoteHistory } from '../../lib/useQuoteHistory';

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
 *
 * Presentational: the fetch and its loading/failed bookkeeping live in
 * `useQuoteHistory`, which the order page owns and shares with the timeline's
 * per-step timestamps - one fetch, one source of truth for the trail.
 */
export default function StatusHistory({ entries, loading, failed }: QuoteHistory) {
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
