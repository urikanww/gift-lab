import { beforeEach, expect, it, vi } from 'vitest';

const get = vi.fn();
const post = vi.fn();
vi.mock('../lib/api', () => ({
  default: {
    get: (...a: unknown[]) => get(...a),
    post: (...a: unknown[]) => post(...a),
  },
  apiError: () => 'Could not load the desk.',
  ensureCsrf: async () => {},
}));
vi.mock('../lib/echo', () => ({
  joinSharedPrivate: () => ({ listen: () => ({}) }),
  leaveSharedPrivate: () => {},
}));

import { useProcurementStore } from './procurementStore';
import type { ReconfirmAlert } from './procurementStore';

const alert = (overrides: Partial<ReconfirmAlert> = {}): ReconfirmAlert => ({
  line_item_id: 5,
  quote_id: 9,
  quote_reference: 'ORD-1',
  reason: 'qty_short',
  ordered_qty: 10,
  procured_qty: 4,
  unit_price: '15.00',
  procured_price: null,
  ...overrides,
});

beforeEach(() => {
  get.mockReset();
  post.mockReset();
  useProcurementStore.setState({ alerts: [], buyList: [], error: null, loading: false });
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

// P0-2: the desk had no data source, so a blocked line was visible only to
// whoever had the page open when it broke. Anyone arriving later saw an empty
// desk while orders sat stuck.
it('loads the lines awaiting a decision', async () => {
  get.mockResolvedValue({
    data: {
      data: [
        {
          id: 5,
          quote_id: 9,
          quote_reference: 'ORD-1',
          qty: 10,
          unit_price: '15.00',
          procured_qty: 4,
          procured_price: null,
        },
      ],
    },
  });

  await useProcurementStore.getState().fetchAlerts();

  const alerts = useProcurementStore.getState().alerts;
  expect(get).toHaveBeenCalledWith('/procurement/awaiting-reconfirm', { params: undefined });
  expect(alerts).toHaveLength(1);
  expect(alerts[0]).toMatchObject({
    line_item_id: 5,
    quote_reference: 'ORD-1',
    ordered_qty: 10,
    procured_qty: 4,
    reason: 'qty_short',
  });
});

it('reads a full-quantity line as a price jump rather than a shortfall', async () => {
  get.mockResolvedValue({
    data: {
      data: [
        {
          id: 6,
          quote_id: 9,
          quote_reference: 'ORD-2',
          qty: 10,
          unit_price: '15.00',
          procured_qty: 10,
          procured_price: '19.00',
        },
      ],
    },
  });

  await useProcurementStore.getState().fetchAlerts();

  expect(useProcurementStore.getState().alerts[0].reason).toBe('price_jumped');
});

it('surfaces a failed load instead of showing an empty desk', async () => {
  get.mockRejectedValue(new Error('boom'));

  await useProcurementStore.getState().fetchAlerts();

  const state = useProcurementStore.getState();
  expect(state.error).toBe('Could not load the desk.');
  expect(state.loading).toBe(false);
});

// Bug: run() on the page used to infer success from the alert list shrinking.
// The live `.awaiting-reconfirm` subscription can add an unrelated alert
// while a reconfirm request is in flight, which made a genuine success look
// like a failure (the list was the same length, or longer, than before). The
// store must report the real HTTP outcome instead.
it('reports the real outcome even when an unrelated alert arrives mid-request', async () => {
  useProcurementStore.setState({ alerts: [alert()] });

  let resolvePost!: (value: unknown) => void;
  post.mockReturnValue(
    new Promise((resolve) => {
      resolvePost = resolve;
    }),
  );

  const outcomePromise = useProcurementStore.getState().reconfirm(5, 'drop');

  // Simulate the live subscription growing the list while the drop is in flight.
  useProcurementStore.setState((s) => ({
    alerts: [...s.alerts, alert({ line_item_id: 8, quote_id: 20, quote_reference: 'ORD-3' })],
  }));

  resolvePost({
    data: {
      data: {
        id: 5,
        quote_id: 9,
        quote_reference: 'ORD-1',
        qty: 10,
        unit_price: '15.00',
        procured_qty: 0,
        procured_price: null,
        line_state: 'DROPPED',
      },
    },
  });

  const outcome = await outcomePromise;

  expect(outcome).toMatchObject({ success: true, line_state: 'DROPPED' });
  const alerts = useProcurementStore.getState().alerts;
  expect(alerts.map((a) => a.line_item_id)).toEqual([8]);
});

// Bug: alert removal used to be keyed off "the request didn't throw", so an
// amend that re-procures and immediately fails its own re-check (still short,
// still AWAITING_RECONFIRM) silently vanished from the desk as if resolved.
it('keeps the alert when an amend re-blocks (response line_state is AWAITING_RECONFIRM)', async () => {
  useProcurementStore.setState({ alerts: [alert()] });
  post.mockResolvedValue({
    data: {
      data: {
        id: 5,
        quote_id: 9,
        quote_reference: 'ORD-1',
        qty: 7,
        unit_price: '16.00',
        procured_qty: 3,
        procured_price: null,
        line_state: 'AWAITING_RECONFIRM',
      },
    },
  });

  const outcome = await useProcurementStore
    .getState()
    .reconfirm(5, 'amend', { qty: 7, unit_price: 16 });

  expect(outcome).toMatchObject({ success: true, line_state: 'AWAITING_RECONFIRM' });
  const alerts = useProcurementStore.getState().alerts;
  expect(alerts).toHaveLength(1);
  expect(alerts[0]).toMatchObject({ line_item_id: 5, procured_qty: 3, reason: 'qty_short' });
});

it('reports failure and keeps the alert when the request errors', async () => {
  useProcurementStore.setState({ alerts: [alert()] });
  post.mockRejectedValue(new Error('boom'));

  const outcome = await useProcurementStore.getState().reconfirm(5, 'drop');

  expect(outcome.success).toBe(false);
  expect(useProcurementStore.getState().alerts).toHaveLength(1);
  expect(useProcurementStore.getState().error).toBe('Could not load the desk.');
});
