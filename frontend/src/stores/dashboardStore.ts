import { create } from 'zustand';
import { apiError } from '../lib/api';
import { getEcho, onEchoReconnect } from '../lib/echo';
import { fetchDashboard, type DashboardPayload } from '../lib/dashboard';

let offReconnect: (() => void) | null = null;
let subscribed = false;
let debounce: ReturnType<typeof setTimeout> | null = null;

interface DashboardStoreState {
  data: DashboardPayload | null;
  loading: boolean;
  error: string | null;
  load: () => Promise<void>;
  subscribe: () => void;
  unsubscribe: () => void;
}

export const useDashboardStore = create<DashboardStoreState>((set, get) => ({
  data: null,
  loading: false,
  error: null,

  load: async () => {
    set({ loading: true, error: null });
    try {
      const data = await fetchDashboard();
      set({ data, loading: false });
    } catch (err) {
      set({ loading: false, error: apiError(err) });
    }
  },

  // Realtime-driven refresh: reuse the existing staff channels. Any floor/
  // procurement event debounces a single refetch (no polling; matches the app's
  // Reverb-only constraint). Also reconciles after a socket reconnect.
  subscribe: () => {
    if (subscribed) return;
    subscribed = true;

    const refresh = () => {
      if (debounce) clearTimeout(debounce);
      debounce = setTimeout(() => void get().load(), 800);
    };

    offReconnect = onEchoReconnect(refresh);

    const echo = getEcho();
    echo.private('staff.queue').listen('.production-queue.updated', refresh);
    echo.private('staff.procurement').listen('.line-item.awaiting-reconfirm', refresh);
  },

  unsubscribe: () => {
    if (!subscribed) return;
    subscribed = false;
    if (debounce) clearTimeout(debounce);
    offReconnect?.();
    offReconnect = null;
    const echo = getEcho();
    echo.leave('staff.queue');
    echo.leave('staff.procurement');
  },
}));
