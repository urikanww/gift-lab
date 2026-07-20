import { useEffect, useState } from 'react';
import { Card } from '../../ui';
import { humanizeState } from '../../lib/quoteStatus';
import { fetchQuoteHistory, type QuoteHistoryEntry } from '../../lib/quotes';

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

function formatChangedAt(changedAt: string | null): string | null {
  if (!changedAt) return null;
  const at = new Date(changedAt);
  if (Number.isNaN(at.getTime())) return null;
  return at.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
}

/**
 * Buyer-facing trail of recorded state changes, newest first. Deliberately
 * renders only what the API logged: no partial history is reconstructed from
 * other fields on the order, because a timeline showing two entries and looking
 * complete is worse than one that admits it is empty.
 */
export default function StatusHistory({ reference }: { reference: string }) {
  const [entries, setEntries] = useState<QuoteHistoryEntry[]>([]);

  useEffect(() => {
    let active = true;
    // The fetcher already swallows failures, but catch here too: this component
    // must never throw an unhandled rejection into the order page.
    fetchQuoteHistory(reference)
      .then((rows) => {
        if (active) setEntries(Array.isArray(rows) ? rows : []);
      })
      .catch(() => {
        if (active) setEntries([]);
      });
    return () => {
      active = false;
    };
  }, [reference]);

  // API order is oldest first; the most recent change is what a buyer wants.
  const newestFirst = [...entries].reverse();

  return (
    <Card padding="lg" aria-labelledby="history-heading">
      <h2 id="history-heading" className="font-display text-xl text-fg">
        Status history
      </h2>

      {newestFirst.length > 0 ? (
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
                  {when && <span>{when}</span>}
                  <span>{actorLabel(entry)}</span>
                </span>
              </li>
            );
          })}
        </ul>
      ) : (
        <p className="mt-3 text-sm text-fg-muted">
          Status tracking started on {TRACKING_STARTED}. Changes before then were not recorded.
        </p>
      )}
    </Card>
  );
}
