import { useEffect, useState } from 'react';
import { fetchQuoteHistory, type QuoteHistoryEntry } from './quotes';
import type { QuoteState } from '../types';

/**
 * The order's recorded state trail, fetched once and shared by every surface
 * that needs it. The order page renders it in two places - the status-history
 * card and the per-step timestamps on the timeline - and both must read the
 * SAME fetch: a second, independent request would double the load AND could
 * answer differently, putting two disagreeing versions of the trail on one page.
 *
 * The three states are kept distinct on purpose (see the callers):
 *  - loading: we haven't been told yet. Starts true so nothing asserts a fact
 *    about the order before the fetch answers.
 *  - failed: the request errored. Different in kind from an empty trail - only
 *    an empty trail justifies "this order predates tracking".
 *  - entries empty + not failed + not loading: a genuinely empty history.
 */
export interface QuoteHistory {
  /** Oldest first, exactly as the API returns it. Callers reverse for display. */
  entries: QuoteHistoryEntry[];
  loading: boolean;
  failed: boolean;
}

export function useQuoteHistory(reference: string, state: QuoteState): QuoteHistory {
  const [entries, setEntries] = useState<QuoteHistoryEntry[]>([]);
  const [failed, setFailed] = useState(false);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    // The order page calls this hook before the order itself has loaded, when
    // there is no reference to fetch by. Skip until one arrives; the dep change
    // fetches the moment it does.
    if (!reference) return;
    let active = true;
    // Set INSIDE the effect: it re-runs on every `state` change, and a refetch
    // that kept the pre-change trail on screen would be the staleness this key
    // exists to prevent, in miniature - a record contradicting the badge above.
    setLoading(true);
    // fetchQuoteHistory REJECTS on failure by design, so "request failed" stays
    // distinguishable from "no recorded history". We catch it here and expose it
    // as `failed`; the trail is best-effort and must never throw into the page.
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
      // Guards a late settle after unmount, and a stale in-flight request
      // superseded by a newer state change - neither may write.
      active = false;
    };
    // `reference` is fixed for the page's life; `state` is what makes this
    // refetch when the order moves, so the trail can never lag the badge.
  }, [reference, state]);

  return { entries, loading, failed };
}
