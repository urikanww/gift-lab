import { afterEach, describe, expect, it, vi } from 'vitest';
import api from './api';
import { fetchRecentQuotes } from './quotes';
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
