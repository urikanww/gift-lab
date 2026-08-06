import { beforeEach, expect, it, vi } from 'vitest';

const get = vi.fn();
const post = vi.fn();
vi.mock('../lib/api', () => ({
  default: {
    get: (...a: unknown[]) => get(...a),
    post: (...a: unknown[]) => post(...a),
  },
  apiError: () => 'Could not load the buy list.',
  ensureCsrf: async () => {},
}));

import { useProcurementStore } from './procurementStore';

beforeEach(() => {
  get.mockReset();
  post.mockReset();
  useProcurementStore.setState({ buyList: [], error: null, loading: false });
});

it('fetchBuyList populates rows from the endpoint', async () => {
  get.mockResolvedValue({
    data: {
      data: [
        {
          id: 1,
          product_id: 9,
          quote_id: 5,
          quote_reference: 'GL-5',
          qty: 2,
          product: { name: 'Mug', class: 'SCRAPED_UV', source_url: 'x', affiliate_url: 'y' },
        },
      ],
    },
  });

  await useProcurementStore.getState().fetchBuyList();

  expect(useProcurementStore.getState().buyList).toHaveLength(1);
  expect(get).toHaveBeenCalledWith('/procurement/buy-list');
});

it('surfaces a failed load instead of leaving a stale list', async () => {
  get.mockRejectedValue(new Error('boom'));

  await useProcurementStore.getState().fetchBuyList();

  const state = useProcurementStore.getState();
  expect(state.error).toBe('Could not load the buy list.');
  expect(state.loading).toBe(false);
});

it('markBought removes the bought row optimistically', async () => {
  useProcurementStore.setState({
    buyList: [
      { id: 1, product_id: 9, quote_id: 5, quote_reference: 'GL-5', qty: 2, product: { name: 'Mug', class: 'SCRAPED_UV' } },
    ],
  });
  post.mockResolvedValue({});

  await useProcurementStore.getState().markBought(1);

  expect(useProcurementStore.getState().buyList).toHaveLength(0);
  expect(post).toHaveBeenCalledWith('/line-items/1/mark-bought');
});

it('markProductBought removes every row for the product', async () => {
  useProcurementStore.setState({
    buyList: [
      { id: 1, product_id: 9, quote_id: 5, qty: 1, product: { name: 'Mug', class: 'SCRAPED_UV' } },
      { id: 2, product_id: 9, quote_id: 6, qty: 1, product: { name: 'Mug', class: 'SCRAPED_UV' } },
      { id: 3, product_id: 7, quote_id: 7, qty: 1, product: { name: 'Pen', class: 'SCRAPED_UV' } },
    ],
  });
  post.mockResolvedValue({});

  await useProcurementStore.getState().markProductBought(9);

  expect(useProcurementStore.getState().buyList.map((r) => r.id)).toEqual([3]);
  expect(post).toHaveBeenCalledWith('/procurement/buy-list/mark-product/9');
});
