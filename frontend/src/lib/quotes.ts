import api from './api';
import type { Paginated, Quote } from '../types';

/**
 * Most recent quotes for the signed-in buyer, for the home reorder rail.
 * Best-effort: a failure (including a 401 on a stale session) yields an empty
 * list, never a rejection - the rail is optional and must not break the shelf.
 */
export function fetchRecentQuotes(limit: number): Promise<Quote[]> {
  return api
    .get<Paginated<Quote>>('/quotes', { params: { page: 1 } })
    .then((r) => r.data.data.slice(0, limit))
    .catch(() => []);
}

/** One logged state change on an order. Deliberately carries no id or email. */
export interface QuoteHistoryEntry {
  from: string | null;
  to: string | null;
  changed_at: string | null;
  /** Null for system/console transitions - there was no human behind them. */
  actor_name?: string | null;
}

/**
 * Append-only state trail for an order, oldest first as the API returns it.
 *
 * REJECTS on failure, deliberately. This used to swallow errors to `[]`, which
 * made "the request failed" indistinguishable from "this order has no recorded
 * history" - and the only caller renders very different copy for the two. The
 * best-effort behaviour still holds, it just lives in the caller: StatusHistory
 * catches this and stays quiet, so a failed history never takes the order page
 * down or pushes an error into the space where the order details belong.
 */
export function fetchQuoteHistory(reference: string): Promise<QuoteHistoryEntry[]> {
  return api
    .get<{ data: QuoteHistoryEntry[] }>(`/quotes/${encodeURIComponent(reference)}/history`)
    .then((r) => r.data.data ?? []);
}
