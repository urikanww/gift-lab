import { beforeEach, expect, it, vi } from 'vitest';

const get = vi.fn();
vi.mock('../lib/api', () => ({
  default: { get: (...a: unknown[]) => get(...a) },
  apiError: () => 'Could not load the desk.',
  ensureCsrf: async () => {},
}));
vi.mock('../lib/echo', () => ({
  joinSharedPrivate: () => ({ listen: () => ({}) }),
  leaveSharedPrivate: () => {},
}));

import { useProcurementStore } from './procurementStore';

beforeEach(() => {
  get.mockReset();
  useProcurementStore.setState({ alerts: [], error: null, loading: false });
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
  expect(get).toHaveBeenCalledWith('/procurement/awaiting-reconfirm');
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
