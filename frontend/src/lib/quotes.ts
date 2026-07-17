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
