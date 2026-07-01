import { create } from 'zustand';
import api, { apiError, ensureCsrf } from '../lib/api';
import { getEcho } from '../lib/echo';
import type { JobState, ProductionJob } from '../types';

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
  fetchQueue: () => Promise<void>;
  advance: (jobId: number, state: JobState) => Promise<void>;
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

  fetchQueue: async () => {
    set({ loading: true, error: null });
    try {
      const { data } = await api.get<{ data: ProductionJob[] }>('/production-queue');
      set({ jobs: sortQueue(data.data), loading: false });
    } catch (err) {
      set({ loading: false, error: apiError(err) });
    }
  },

  advance: async (jobId, state) => {
    await ensureCsrf();
    await api.post(`/production-jobs/${jobId}/advance`, { state });
    // The broadcast will reconcile state; no refetch/poll needed.
  },

  subscribe: () => {
    if (get().subscribed) return;
    getEcho()
      .private('staff.queue')
      .listen('.production-queue.updated', (e: QueueUpdatedPayload) => {
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
      });
    set({ subscribed: true });
  },

  unsubscribe: () => {
    if (!get().subscribed) return;
    getEcho().leave('staff.queue');
    set({ subscribed: false });
  },
}));
