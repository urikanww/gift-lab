import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ProductionJob } from '../types';

const { get, post, put, listen, stopListening } = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  put: vi.fn(),
  listen: vi.fn(),
  stopListening: vi.fn(),
}));
vi.mock('../lib/api', () => ({
  default: { get, post, put },
  apiError: (e: unknown) => String(e),
  ensureCsrf: vi.fn(),
}));
// The store wires Reverb channels at import; stub them out for the test, but
// capture the registered handler via `listen` so realtime tests can invoke it
// directly instead of standing up a real socket.
vi.mock('../lib/echo', () => ({
  joinSharedPrivate: () => ({ listen, stopListening }),
  leaveSharedPrivate: vi.fn(),
  onEchoReconnect: () => () => {},
}));

import { printFilePath, printFilesZipPath, useQueueStore } from './queueStore';

const job: ProductionJob = {
  id: 1,
  quote_id: 10,
  track: '3D',
  state: 'READY',
  ready_at: '2026-07-06T00:00:00Z',
  print_method: 'FDM',
  qty: 5,
};

beforeEach(() => {
  useQueueStore.setState({ jobs: [], loading: false, error: null, subscribed: false });
  get.mockReset();
  post.mockReset();
  put.mockReset();
  listen.mockReset();
  stopListening.mockReset();
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

  // The bug: advance's catch block used to do set({ error }) then immediately
  // await fetchQueue({ silent: true }), whose first statement resets error to
  // null - wiping the message before the page's onScan ever read it. An
  // operator scanning a wrong-state job got zero feedback. The fix re-throws
  // so the caller can catch and toast it directly.
  it('advance rejects with the API error (instead of swallowing it) so the caller can toast it', async () => {
    useQueueStore.setState({ jobs: [job], loading: false });
    const apiErr = new Error('422 job is not READY');
    post.mockRejectedValue(apiErr);
    get.mockResolvedValue({ data: { data: [job] } }); // reconciling refetch still succeeds

    await expect(useQueueStore.getState().advance(1, 'IN_PRODUCTION')).rejects.toThrow(
      '422 job is not READY',
    );

    // The reconciling refetch still ran (dropped-socket guard preserved)...
    expect(get).toHaveBeenCalledWith('/production-queue');
    // ...but the rejection itself - not a wiped store.error - is what carries
    // the failure to the caller.
  });

  it('advance still reconciles the queue via a silent refetch even when the mutation itself fails', async () => {
    useQueueStore.setState({ jobs: [job], loading: false });
    post.mockRejectedValue(new Error('500'));
    get.mockResolvedValue({ data: { data: [job] } });

    await expect(useQueueStore.getState().advance(1, 'IN_PRODUCTION')).rejects.toThrow();

    expect(useQueueStore.getState().loading).toBe(false);
    expect(useQueueStore.getState().jobs).toHaveLength(1);
  });

  it('advance still clears the happy path (no lingering error, queue refetched) on success', async () => {
    useQueueStore.setState({ jobs: [job], loading: false, error: 'stale previous error' });
    post.mockResolvedValue({ data: {} });
    get.mockResolvedValue({ data: { data: [job] } });

    await useQueueStore.getState().advance(1, 'IN_PRODUCTION');

    expect(useQueueStore.getState().error).toBeNull();
  });

  it('advanceNext posts to the job and refetches silently on success', async () => {
    useQueueStore.setState({ jobs: [job], loading: false });
    post.mockResolvedValue({ data: {} });
    get.mockResolvedValue({ data: { data: [job] } });

    await useQueueStore.getState().advanceNext(1);

    expect(post).toHaveBeenCalledWith('/production-jobs/1/advance-next');
    expect(useQueueStore.getState().error).toBeNull();
  });

  // Same bug as advance(), for the scan-to-advance path specifically: a scanned
  // job in the wrong state (the backend's 422 SHIPPED-guard) must reach the
  // operator, not vanish into a refetch that resets store.error to null.
  it('advanceNext rejects with the API error (instead of swallowing it) so onScan can toast it', async () => {
    useQueueStore.setState({ jobs: [job], loading: false });
    const apiErr = new Error('422 job already SHIPPED');
    post.mockRejectedValue(apiErr);
    get.mockResolvedValue({ data: { data: [job] } });

    await expect(useQueueStore.getState().advanceNext(1)).rejects.toThrow('422 job already SHIPPED');
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

  it('fetchShippingAddress GETs the quote address and passes through the saved flag', async () => {
    const address = {
      recipient_name: 'Ada', phone: '+65 1234 5678', email: null,
      line1: '1 Robinson Rd', line2: null, city: 'Singapore', state: null,
      postal_code: '048542', country: 'SG', notes: null,
    };
    get.mockResolvedValue({ data: { data: address, saved: true } });

    const result = await useQueueStore.getState().fetchShippingAddress(10);

    expect(get).toHaveBeenCalledWith('/quotes/10/shipping-address');
    expect(result).toEqual({ address, saved: true });
  });

  it('fetchShippingAddress reports saved=false for a defaulted (unpersisted) address', async () => {
    const address = {
      recipient_name: 'Acme Co', phone: null, email: null,
      line1: '10 Anson Rd', line2: null, city: null, state: null,
      postal_code: null, country: 'SG', notes: null,
    };
    get.mockResolvedValue({ data: { data: address } }); // no `saved` key

    const result = await useQueueStore.getState().fetchShippingAddress(10);

    expect(result).toEqual({ address, saved: false });
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

  describe('realtime ProductionQueueUpdated handling', () => {
    // Grab the handler `subscribe()` registered on the mocked channel, so tests
    // can fire a realtime event without a real socket.
    function subscribeAndGetHandler() {
      useQueueStore.getState().subscribe();
      expect(listen).toHaveBeenCalledWith('.production-queue.updated', expect.any(Function));
      return listen.mock.calls[listen.mock.calls.length - 1][1] as (e: unknown) => void;
    }

    // The bug: the broadcast payload never carries artwork_refs/line_items. For
    // a job already in state that's fine (the merge below preserves what was
    // loaded), but for a job the board has never seen, merging the stub left
    // those fields permanently undefined - no future refetch would ever
    // backfill them, so "Download print file" never appeared until a manual
    // reload. The fix: an unknown "queued" job triggers a silent fetchQueue()
    // to hydrate the full record instead of caching the incomplete stub.
    it('a "queued" event for a job NOT already in state triggers a silent fetchQueue to hydrate it', () => {
      useQueueStore.setState({ jobs: [], loading: false });
      get.mockResolvedValue({ data: { data: [] } });
      const handler = subscribeAndGetHandler();

      handler({
        job_id: 99,
        quote_id: 900,
        quote_reference: 'ABC123',
        track: '3D',
        state: 'READY',
        ready_at: '2026-07-06T00:00:00Z',
        qty: 2,
        action: 'queued',
      });

      expect(get).toHaveBeenCalledWith('/production-queue');
    });

    it('a "queued" event for a job already in state keeps the existing in-place merge (no refetch, fields preserved)', () => {
      const existingJob: ProductionJob = {
        ...job,
        artwork_refs: [{ line_item_id: 1, product_name: 'Mug', ref: 'prints/job-1/mug.pdf' }],
        line_items: [],
      };
      useQueueStore.setState({ jobs: [existingJob], loading: false });
      const handler = subscribeAndGetHandler();

      handler({
        job_id: existingJob.id,
        quote_id: existingJob.quote_id,
        quote_reference: existingJob.quote_reference,
        track: existingJob.track,
        state: 'READY',
        ready_at: existingJob.ready_at,
        qty: existingJob.qty,
        action: 'queued',
      });

      expect(get).not.toHaveBeenCalledWith('/production-queue');
      const updated = useQueueStore.getState().jobs.find((j) => j.id === existingJob.id);
      expect(updated?.artwork_refs).toEqual(existingJob.artwork_refs);
      expect(updated?.line_items).toEqual(existingJob.line_items);
    });

    it('a "closed" event still removes the job from state, even though it is not "queued"', () => {
      useQueueStore.setState({ jobs: [job], loading: false });
      const handler = subscribeAndGetHandler();

      handler({
        job_id: job.id,
        quote_id: job.quote_id,
        track: job.track,
        state: 'CLOSED',
        ready_at: job.ready_at,
        qty: job.qty,
        action: 'closed',
      });

      expect(useQueueStore.getState().jobs).toHaveLength(0);
      expect(get).not.toHaveBeenCalledWith('/production-queue');
    });
  });

  it('printFilePath targets the per-item print-file endpoint with the ref query, URL-encoded', () => {
    expect(printFilePath(7, 'prints/job-7/line 3.pdf')).toBe(
      '/production-jobs/7/print-file?ref=prints%2Fjob-7%2Fline%203.pdf',
    );
  });

  it('printFilesZipPath targets the whole-job ZIP endpoint', () => {
    expect(printFilesZipPath(7)).toBe('/production-jobs/7/print-files.zip');
  });
});
