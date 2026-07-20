import { afterEach, describe, expect, it, vi } from 'vitest';
import api from './api';
import { fetchQuoteHistory, fetchRecentQuotes } from './quotes';
import type { Quote } from '../types';

const quote = (id: number): Quote =>
  ({
    id,
    company_id: 1,
    state: 'ACCEPTED',
    currency: 'SGD',
    subtotal: '100.00',
    delivery: '0.00',
    total: '100.00',
    price_snapshot_at: null,
    notes: null,
    needed_by: null,
    created_at: '2026-07-01T00:00:00Z',
  }) as Quote;

afterEach(() => vi.restoreAllMocks());

describe('fetchRecentQuotes', () => {
  it('returns at most `limit` quotes', async () => {
    vi.spyOn(api, 'get').mockResolvedValue({
      data: { data: [quote(1), quote(2), quote(3), quote(4)], meta: { current_page: 1, last_page: 1, total: 4 } },
    } as any);

    await expect(fetchRecentQuotes(3)).resolves.toHaveLength(3);
  });

  it('resolves to an empty array when the request fails', async () => {
    vi.spyOn(api, 'get').mockRejectedValue(new Error('401'));

    await expect(fetchRecentQuotes(3)).resolves.toEqual([]);
  });
});

describe('fetchQuoteHistory', () => {
  it('returns the recorded transitions', async () => {
    vi.spyOn(api, 'get').mockResolvedValue({
      data: { data: [{ from: 'DRAFT', to: 'SENT', changed_at: '2026-07-20T10:00:00Z' }] },
    } as any);

    await expect(fetchQuoteHistory('ORD-123')).resolves.toHaveLength(1);
  });

  it('rejects rather than masking a failure as an empty history', async () => {
    // Resolving [] here is what let the UI tell a buyer whose request 500'd
    // that their order predates status tracking. The caller needs to be able
    // to tell "no history" apart from "no answer".
    vi.spyOn(api, 'get').mockRejectedValue(new Error('500'));

    await expect(fetchQuoteHistory('ORD-123')).rejects.toThrow('500');
  });
});
