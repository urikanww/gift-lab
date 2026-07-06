import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ProductionJob } from '../types';

const { get, post } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock('../lib/api', () => ({
  default: { get, post },
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

  it('advance refetches silently — never flips loading true', async () => {
    useQueueStore.setState({ jobs: [job], loading: false });
    post.mockResolvedValue({ data: {} });
    let resolveGet!: (v: unknown) => void;
    get.mockReturnValue(new Promise((r) => { resolveGet = r; }));

    const p = useQueueStore.getState().advance(1, 'IN_PRODUCTION');
    // The safety refetch must not show a skeleton over the existing list.
    expect(useQueueStore.getState().loading).toBe(false);

    resolveGet({ data: { data: [job] } });
    await p;
    expect(post).toHaveBeenCalledWith('/production-jobs/1/advance', { state: 'IN_PRODUCTION' });
  });
});
