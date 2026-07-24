import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

// Mock the api module so we can count /user requests without a network.
vi.mock('../lib/api', () => ({
  default: { get: vi.fn() },
  ensureCsrf: vi.fn(),
  apiError: (e: unknown) => String(e),
}));

import api from '../lib/api';
import { useAuthStore } from './authStore';

const initial = useAuthStore.getState();

describe('authStore.fetchUser', () => {
  beforeEach(() => {
    vi.mocked(api.get).mockReset();
    useAuthStore.setState(initial, true);
  });

  afterEach(() => {
    useAuthStore.setState(initial, true);
  });

  it('coalesces concurrent probes onto a single /user request', async () => {
    let resolveGet: (v: { data: { id: number } }) => void = () => {};
    vi.mocked(api.get).mockReturnValue(
      new Promise((res) => {
        resolveGet = res as (v: { data: { id: number } }) => void;
      }) as ReturnType<typeof api.get>,
    );

    // Two callers race (mirrors React StrictMode's double boot effect).
    const a = useAuthStore.getState().fetchUser();
    const b = useAuthStore.getState().fetchUser();

    resolveGet({ data: { id: 1 } });
    await Promise.all([a, b]);

    // Only ONE network request despite two callers.
    expect(api.get).toHaveBeenCalledTimes(1);
    expect(useAuthStore.getState().user).toEqual({ id: 1 });
    expect(useAuthStore.getState().status).toBe('ready');
  });

  it('can probe again after the in-flight request settles', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: { id: 7 } } as Awaited<ReturnType<typeof api.get>>);

    await useAuthStore.getState().fetchUser();
    await useAuthStore.getState().fetchUser();

    // Sequential (non-overlapping) calls are NOT coalesced - the guard clears.
    expect(api.get).toHaveBeenCalledTimes(2);
  });

  it('settles to ready with no user on a 401 (anonymous visitor)', async () => {
    vi.mocked(api.get).mockRejectedValue(new Error('401'));

    await useAuthStore.getState().fetchUser();

    expect(useAuthStore.getState().user).toBeNull();
    expect(useAuthStore.getState().status).toBe('ready');
  });
});
