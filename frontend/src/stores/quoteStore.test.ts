import { beforeEach, expect, it, vi } from 'vitest';
import type { Quote } from '../types';

const get = vi.fn();
const post = vi.fn();
const patch = vi.fn();
vi.mock('../lib/api', () => ({
  default: {
    get: (...a: unknown[]) => get(...a),
    post: (...a: unknown[]) => post(...a),
    patch: (...a: unknown[]) => patch(...a),
  },
  apiError: (e: unknown) => (e instanceof Error ? e.message : 'Something went wrong.'),
  apiFieldErrors: () => ({}),
  ensureCsrf: async () => {},
}));
vi.mock('../lib/echo', () => ({
  joinSharedPrivate: () => ({ listen: () => ({ listen: () => ({}) }) }),
  leaveSharedPrivate: () => {},
  onEchoReconnect: () => () => {},
}));

import { useQuoteStore } from './quoteStore';

const quote = (id: number): Quote =>
  ({
    id,
    company_id: 1,
    reference: `REF${id}`,
    state: 'DRAFT',
    currency: 'SGD',
    subtotal: '100.00',
    delivery: '0.00',
    total: '109.00',
    price_snapshot_at: null,
    notes: null,
    needed_by: null,
    created_at: '2026-07-27T00:00:00Z',
  }) as Quote;

beforeEach(() => {
  get.mockReset();
  post.mockReset();
  patch.mockReset();
  useQuoteStore.setState({ actionError: null });
});

it('reorderQuote clones the source order and returns the new draft', async () => {
  const created = quote(42);
  post.mockResolvedValue({ data: { data: created } });

  const result = await useQuoteStore.getState().reorderQuote(7);

  expect(post).toHaveBeenCalledWith('/quotes/7/reorder');
  expect(result).toEqual(created);
  expect(useQuoteStore.getState().actionError).toBeNull();
});

it('reorderQuote surfaces the failure reason and returns null (e.g. every line dropped)', async () => {
  post.mockRejectedValue(new Error('This order has no lines left to reorder.'));

  const result = await useQuoteStore.getState().reorderQuote(7);

  expect(result).toBeNull();
  expect(useQuoteStore.getState().actionError).toBe('This order has no lines left to reorder.');
});
