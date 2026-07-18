import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ProductionJob } from '../types';

const { get, post, put } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn() }));
vi.mock('../lib/api', () => ({
  default: { get, post, put },
  apiError: (e: unknown) => String(e),
  ensureCsrf: vi.fn(),
}));
// The store wires Reverb channels at import; stub them out for the test.
vi.mock('../lib/echo', () => ({
  joinSharedPrivate: () => ({ listen: vi.fn(), stopListening: vi.fn() }),
  leaveSharedPrivate: vi.fn(),
  onEchoReconnect: () => () => {},
}));

import { useQueueStore } from './queueStore';

const job: ProductionJob = {
  id: 1,
  quote_id: 10,
  track: '3D',
  state: 'READY',
  ready_at: '2026-07-06T00:00:00Z',
  artwork_ref: null,
  print_method: 'FDM',
  qty: 5,
};

beforeEach(() => {
  useQueueStore.setState({ jobs: [], loading: false, error: null });
  get.mockReset();
  post.mockReset();
  put.mockReset();
});

describe('queueStore', () => {
  it('shows loading on a normal (non-silent) fetch', async () => {
    let resolveGet!: (v: unknown) => void;
    get.mockReturnValue(new Promise((r) => { resolveGet = r; }));

    const p = useQueueStore.getState().fetchQueue();
    expect(useQueueStore.getState().loading).toBe(true); // mid-flight

    resolveGet({ data: { data: [job] } });
    await p;
    expect(useQueueStore.getState().loading).toBe(false);
    expect(useQueueStore.getState().jobs).toHaveLength(1);
  });

  // The discriminating assertion: fetchQueue's first statement is a synchronous
  // set(), so loading reflects the silent decision immediately on call - before
  // any await. A non-silent fetch flips loading true here (see test above); the
  // silent path must leave the existing list un-skeletoned.
  it('fetchQueue({ silent: true }) never flips loading true', async () => {
    useQueueStore.setState({ jobs: [job], loading: false });
    let resolveGet!: (v: unknown) => void;
    get.mockReturnValue(new Promise((r) => { resolveGet = r; }));

    const p = useQueueStore.getState().fetchQueue({ silent: true });
    expect(useQueueStore.getState().loading).toBe(false); // no skeleton over the list

    resolveGet({ data: { data: [job] } });
    await p;
    expect(useQueueStore.getState().loading).toBe(false);
  });

  it('advance posts to the job and refetches silently (no skeleton)', async () => {
    useQueueStore.setState({ jobs: [job], loading: false });
    post.mockResolvedValue({ data: {} });
    get.mockResolvedValue({ data: { data: [job] } });

    await useQueueStore.getState().advance(1, 'IN_PRODUCTION');

    expect(post).toHaveBeenCalledWith('/production-jobs/1/advance', { state: 'IN_PRODUCTION' });
    expect(useQueueStore.getState().loading).toBe(false);
  });

  it('advanceBatch posts job_ids + state and refetches', async () => {
    post.mockResolvedValue({ data: { advanced: [1, 2], skipped: [] } });
    get.mockResolvedValue({ data: { data: [] } });

    const result = await useQueueStore.getState().advanceBatch([1, 2], 'IN_PRODUCTION');

    expect(post).toHaveBeenCalledWith('/production-jobs/advance-batch', {
      job_ids: [1, 2],
      state: 'IN_PRODUCTION',
    });
    expect(result).toEqual({ advanced: [1, 2], skipped: [] });
    expect(get).toHaveBeenCalledWith('/production-queue');
  });

  it('fetchShippingAddress GETs the quote address and returns data.data', async () => {
    const address = {
      recipient_name: 'Ada', phone: '+65 1234 5678', email: null,
      line1: '1 Robinson Rd', line2: null, city: 'Singapore', state: null,
      postal_code: '048542', country: 'SG', notes: null,
    };
    get.mockResolvedValue({ data: { data: address } });

    const result = await useQueueStore.getState().fetchShippingAddress(10);

    expect(get).toHaveBeenCalledWith('/quotes/10/shipping-address');
    expect(result).toEqual(address);
  });

  it('saveShippingAddress ensures CSRF, PUTs the payload, and returns data.data', async () => {
    const { ensureCsrf } = await import('../lib/api');
    const payload = {
      recipient_name: 'Ada', phone: '+65 1234 5678',
      line1: '1 Robinson Rd', postal_code: '048542',
    };
    const saved = { ...payload, email: null, line2: null, city: null, state: null, country: 'SG', notes: null };
    put.mockResolvedValue({ data: { data: saved } });

    const result = await useQueueStore.getState().saveShippingAddress(10, payload);

    expect(ensureCsrf).toHaveBeenCalled();
    expect(put).toHaveBeenCalledWith('/quotes/10/shipping-address', payload);
    expect(result).toEqual(saved);
  });

  it('createShipment ensures CSRF, posts to create-shipment, returns the payload, and silently refetches', async () => {
    const { ensureCsrf } = await import('../lib/api');
    const shipment = { state: 'SHIPPED', carrier: 'NINJAVAN', consignment_ref: 'NV-TEST-123', tracking_url: 'https://track/NV-TEST-123' };
    post.mockResolvedValue({ data: { data: shipment } });
    get.mockResolvedValue({ data: { data: [] } });

    const result = await useQueueStore.getState().createShipment(7);

    expect(ensureCsrf).toHaveBeenCalled();
    expect(post).toHaveBeenCalledWith('/production-jobs/7/create-shipment');
    expect(result).toEqual(shipment);
    expect(get).toHaveBeenCalledWith('/production-queue');
  });
});
