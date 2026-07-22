import { beforeEach, expect, it, vi } from 'vitest';
import { act, renderHook, waitFor } from '@testing-library/react';

const fetchQuoteHistory = vi.fn();
vi.mock('./quotes', () => ({
  fetchQuoteHistory: (reference: string) => fetchQuoteHistory(reference),
}));

import { useQuoteHistory } from './useQuoteHistory';

beforeEach(() => {
  fetchQuoteHistory.mockReset();
});

it('fetches by the order reference and returns the trail oldest-first, as given', async () => {
  const rows = [
    { from: 'DRAFT', to: 'SENT', changed_at: '2026-07-20T10:00:00Z', actor_name: 'Bo' },
    { from: 'SENT', to: 'ACCEPTED', changed_at: '2026-07-21T10:00:00Z', actor_name: 'Ada' },
  ];
  fetchQuoteHistory.mockResolvedValue(rows);

  const { result } = renderHook(() => useQuoteHistory('9BWVKWCDXH', 'ACCEPTED'));

  // Starts loading so nothing asserts a fact about the order before the answer.
  expect(result.current.loading).toBe(true);

  await waitFor(() => expect(result.current.loading).toBe(false));
  expect(fetchQuoteHistory).toHaveBeenCalledWith('9BWVKWCDXH');
  expect(result.current.entries).toEqual(rows);
  expect(result.current.failed).toBe(false);
});

it('reports failed - distinct from empty - when the fetch rejects', async () => {
  fetchQuoteHistory.mockRejectedValue(new Error('network down'));

  const { result } = renderHook(() => useQuoteHistory('9BWVKWCDXH', 'SENT'));

  await waitFor(() => expect(result.current.loading).toBe(false));
  expect(result.current.failed).toBe(true);
  expect(result.current.entries).toEqual([]);
});

it('refetches when the order state moves, so the trail never lags the badge', async () => {
  fetchQuoteHistory.mockResolvedValue([]);

  const { result, rerender } = renderHook(
    ({ state }: { state: 'SENT' | 'ACCEPTED' }) => useQuoteHistory('9BWVKWCDXH', state),
    { initialProps: { state: 'SENT' } as { state: 'SENT' | 'ACCEPTED' } },
  );

  await waitFor(() => expect(result.current.loading).toBe(false));
  expect(fetchQuoteHistory).toHaveBeenCalledTimes(1);

  rerender({ state: 'ACCEPTED' });
  await waitFor(() => expect(fetchQuoteHistory).toHaveBeenCalledTimes(2));
});

it('does not fetch until it has a reference to fetch by', async () => {
  fetchQuoteHistory.mockResolvedValue([]);

  const { rerender } = renderHook(
    ({ reference }: { reference: string }) => useQuoteHistory(reference, 'DRAFT'),
    { initialProps: { reference: '' } },
  );

  // No reference yet (order still loading) - nothing to request.
  expect(fetchQuoteHistory).not.toHaveBeenCalled();

  await act(async () => {
    rerender({ reference: '9BWVKWCDXH' });
  });
  await waitFor(() => expect(fetchQuoteHistory).toHaveBeenCalledWith('9BWVKWCDXH'));
});
