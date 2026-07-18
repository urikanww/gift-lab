import { create } from 'zustand';
import api, { apiError, ensureCsrf } from '../lib/api';
import { joinSharedPrivate, leaveSharedPrivate, onEchoReconnect } from '../lib/echo';
import type {
  JobState,
  ProductionJob,
  ShipmentResult,
  ShippingAddress,
  ShippingAddressInput,
} from '../types';

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
  advance: (jobId: number, state: JobState, consignmentRef?: string, carrier?: string) => Promise<void>;
  advanceBatch: (jobIds: number[], state: 'IN_PRODUCTION' | 'CLOSED') => Promise<{ advanced: number[]; skipped: number[] }>;
  advanceNext: (jobId: number) => Promise<void>;
  fetchShippingAddress: (quoteId: number) => Promise<ShippingAddress>;
  saveShippingAddress: (quoteId: number, payload: ShippingAddressInput) => Promise<ShippingAddress>;
  createShipment: (jobId: number) => Promise<ShipmentResult>;
  subscribe: () => void;
  unsubscribe: () => void;
}

// FCFS by ready_at - the queue always renders in readiness order, never order time.
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

  advance: async (jobId, state, consignmentRef, carrier) => {
    set({ error: null });
    try {
      await ensureCsrf();
      await api.post(`/production-jobs/${jobId}/advance`, {
        state,
        ...(consignmentRef ? { consignment_ref: consignmentRef } : {}),
        ...(carrier ? { carrier } : {}),
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

  advanceBatch: async (jobIds, state) => {
    set({ error: null });
    try {
      await ensureCsrf();
      const { data } = await api.post<{ advanced: number[]; skipped: number[] }>(
        '/production-jobs/advance-batch',
        { job_ids: jobIds, state },
      );
      await get().fetchQueue({ silent: true });
      return data;
    } catch (err) {
      set({ error: apiError(err) });
      await get().fetchQueue({ silent: true });
      return { advanced: [], skipped: jobIds };
    }
  },

  advanceNext: async (jobId) => {
    set({ error: null });
    try {
      await ensureCsrf();
      await api.post(`/production-jobs/${jobId}/advance-next`);
      await get().fetchQueue({ silent: true });
    } catch (err) {
      set({ error: apiError(err) });
      await get().fetchQueue({ silent: true });
    }
  },

  // Delivery address for a quote (staff-gated): the saved address or a
  // company-defaulted one. Read-only fetch, so it does not touch store error.
  fetchShippingAddress: async (quoteId) => {
    const { data } = await api.get<{ data: ShippingAddress }>(`/quotes/${quoteId}/shipping-address`);
    return data.data;
  },

  saveShippingAddress: async (quoteId, payload) => {
    await ensureCsrf();
    const { data } = await api.put<{ data: ShippingAddress }>(
      `/quotes/${quoteId}/shipping-address`,
      payload,
    );
    return data.data;
  },

  // Automated NinjaVan path: book the shipment, then silently refetch so the row
  // flips to SHIPPED. Deliberately lets the error THROW - the page needs the
  // 422/502 message to toast it (swallowing into store.error would hide it).
  createShipment: async (jobId) => {
    await ensureCsrf();
    const { data } = await api.post<{ data: ShipmentResult }>(
      `/production-jobs/${jobId}/create-shipment`,
    );
    await get().fetchQueue({ silent: true });
    return data.data;
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
          // The lightweight broadcast carries no line items; keep what we loaded
          // so the customization/preview panel survives a state change.
          line_items: existing?.line_items,
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
