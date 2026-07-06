import { create } from 'zustand';
import api, { apiError, ensureCsrf } from '../lib/api';
import { joinSharedPrivate, leaveSharedPrivate, onEchoReconnect } from '../lib/echo';
import type { JobState, ProductionJob } from '../types';

// Unregister handle for the reconnect-refetch subscription.
let offReconnect: (() => void) | null = null;
let queueChannel: ReturnType<typeof joinSharedPrivate> | null = null;
let queueUpdatedListener: ((e: QueueUpdatedPayload) => void) | null = null;

interface QueueUpdatedPayload {
  job_id: number;
  quote_id: number;
  track: ProductionJob['track'];
  state: JobState;
  ready_at: string | null;
  qty: number;
  action: 'queued' | 'started' | 'shipped' | 'closed';
}

interface QueueStoreState {
  jobs: ProductionJob[];
  loading: boolean;
  error: string | null;
  subscribed: boolean;
  fetchQueue: (opts?: { silent?: boolean }) => Promise<void>;
  advance: (jobId: number, state: JobState, consignmentRef?: string) => Promise<void>;
  subscribe: () => void;
  unsubscribe: () => void;
}

// FCFS by ready_at — the queue always renders in readiness order, never order time.
function sortQueue(jobs: ProductionJob[]): ProductionJob[] {
  return [...jobs].sort((a, b) => (a.ready_at ?? '').localeCompare(b.ready_at ?? ''));
}

export const useQueueStore = create<QueueStoreState>((set, get) => ({
  jobs: [],
  loading: false,
  error: null,
  subscribed: false,

  fetchQueue: async (opts) => {
    set({ loading: opts?.silent ? get().loading : true, error: null });
    try {
      const { data } = await api.get<{ data: ProductionJob[] }>('/production-queue');
      set({ jobs: sortQueue(data.data), loading: false });
    } catch (err) {
      set({ loading: false, error: apiError(err) });
    }
  },

  advance: async (jobId, state, consignmentRef) => {
    set({ error: null });
    try {
      await ensureCsrf();
      await api.post(`/production-jobs/${jobId}/advance`, {
        state,
        ...(consignmentRef ? { consignment_ref: consignmentRef } : {}),
      });
      // Broadcast reconciles the happy path; a single post-mutation refetch (not
      // a poll) guards against a dropped socket / missed event leaving the queue
      // diverged from server truth, and surfaces rejections instead of a
      // silently frozen button.
      await get().fetchQueue({ silent: true });
    } catch (err) {
      set({ error: apiError(err) });
      await get().fetchQueue({ silent: true });
    }
  },

  subscribe: () => {
    if (get().subscribed) return;
    // Reconcile the queue after a socket reconnect (events missed while down).
    offReconnect = onEchoReconnect(() => void get().fetchQueue({ silent: true }));

    queueUpdatedListener = (e: QueueUpdatedPayload) => {
      set((s) => {
        const existing = s.jobs.find((j) => j.id === e.job_id);
        if (e.action === 'closed') {
          return { jobs: s.jobs.filter((j) => j.id !== e.job_id) };
        }
        const next: ProductionJob = {
          id: e.job_id,
          quote_id: e.quote_id,
          track: e.track,
          state: e.state,
          ready_at: e.ready_at,
          artwork_ref: existing?.artwork_ref ?? null,
          print_method: existing?.print_method ?? null,
          qty: e.qty,
        };
        const others = s.jobs.filter((j) => j.id !== e.job_id);
        return { jobs: sortQueue([...others, next]) };
      });
    };
    queueChannel = joinSharedPrivate('staff.queue');
    queueChannel.listen('.production-queue.updated', queueUpdatedListener);
    set({ subscribed: true });
  },

  unsubscribe: () => {
    if (!get().subscribed) return;
    offReconnect?.();
    offReconnect = null;
    if (queueUpdatedListener) {
      queueChannel?.stopListening('.production-queue.updated', queueUpdatedListener);
      queueUpdatedListener = null;
    }
    queueChannel = null;
    leaveSharedPrivate('staff.queue');
    set({ subscribed: false });
  },
}));
