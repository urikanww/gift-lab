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
  /** Displayed identifier; quote_id stays the key rows are matched on. */
  quote_reference?: string | null;
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
  /** Jobs SHIPPED but not yet delivered - the awaiting-delivery panel. */
  inTransit: ProductionJob[];
  inTransitLoading: boolean;
  fetchQueue: (opts?: { silent?: boolean }) => Promise<void>;
  advance: (jobId: number, state: JobState, consignmentRef?: string, carrier?: string) => Promise<void>;
  advanceBatch: (jobIds: number[], state: 'IN_PRODUCTION' | 'CLOSED') => Promise<{ advanced: number[]; skipped: number[] }>;
  advanceNext: (jobId: number) => Promise<void>;
  fetchShippingAddress: (quoteId: number) => Promise<{ address: ShippingAddress; saved: boolean }>;
  saveShippingAddress: (quoteId: number, payload: ShippingAddressInput) => Promise<ShippingAddress>;
  createShipment: (jobId: number) => Promise<ShipmentResult>;
  /** SHIPPED jobs awaiting delivery confirmation (webhook-silent fallback). */
  fetchInTransit: (opts?: { silent?: boolean }) => Promise<void>;
  /** Staff manually confirm delivery when the courier webhook never arrived. */
  markDelivered: (jobId: number, note?: string) => Promise<boolean>;
  /** Parcels the courier reported returned/failed - the Needs-attention surface. */
  needsAttention: ProductionJob[];
  needsAttentionLoading: boolean;
  fetchNeedsAttention: (opts?: { silent?: boolean }) => Promise<void>;
  /** Staff resolve a returned/failed parcel (reship / close / cancel_credit). */
  resolveReturn: (
    jobId: number,
    disposition: 'reship' | 'close' | 'cancel_credit',
    note?: string,
  ) => Promise<boolean>;
  subscribe: () => void;
  unsubscribe: () => void;
}

// Print-file downloads are Sanctum-gated, so callers fetch these paths through
// the authed axios client (baseURL already carries the `/api` origin) as a blob
// - never a bare anchor. One path per file, plus a bundle of the whole job.
export function printFilePath(jobId: number, ref: string): string {
  return `/production-jobs/${jobId}/print-file?ref=${encodeURIComponent(ref)}`;
}

export function printFilesZipPath(jobId: number): string {
  return `/production-jobs/${jobId}/print-files.zip`;
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
  inTransit: [],
  inTransitLoading: false,
  needsAttention: [],
  needsAttentionLoading: false,

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
      // Still refetch to reconcile the queue against server truth (a dropped
      // socket shouldn't leave a stale row), but do NOT stash the message in
      // store.error - fetchQueue's first line resets that field to null, so a
      // set-then-refetch here would wipe it before anyone could read it, and
      // that's exactly how this failure used to go silent. Re-throw instead so
      // the caller (the page's onScan/onAdvance) can catch it and toast -
      // mirroring how createShipment already propagates instead of swallowing.
      await get().fetchQueue({ silent: true });
      throw err;
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
      // See advance() above: still refetch to reconcile, but don't stash the
      // message in store.error (fetchQueue would just reset it to null) -
      // re-throw so onScan's catch can toast the 422 SHIPPED-guard (or any
      // other failure) instead of it vanishing.
      await get().fetchQueue({ silent: true });
      throw err;
    }
  },

  // Delivery address for a quote (staff-gated): the saved address or a
  // company-defaulted one. Read-only fetch, so it does not touch store error.
  fetchShippingAddress: async (quoteId) => {
    const { data } = await api.get<{ data: ShippingAddress; saved?: boolean }>(
      `/quotes/${quoteId}/shipping-address`,
    );
    // `saved` distinguishes a persisted row from the company-defaulted address;
    // the create-shipment gate depends on persistence truth, not a non-empty line1.
    return { address: data.data, saved: Boolean(data.saved) };
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
    // A newly-shipped job leaves the FCFS board and joins the in-transit list.
    await get().fetchInTransit({ silent: true });
    return data.data;
  },

  fetchInTransit: async (opts) => {
    if (!opts?.silent) set({ inTransitLoading: true });
    try {
      const { data } = await api.get<{ data: ProductionJob[] }>('/production-jobs/in-transit');
      set({ inTransit: data.data });
    } catch {
      // Non-critical: the panel just stays as-is. The FCFS board is unaffected.
    } finally {
      set({ inTransitLoading: false });
    }
  },

  markDelivered: async (jobId, note) => {
    await ensureCsrf();
    try {
      await api.post(`/production-jobs/${jobId}/mark-delivered`, note ? { note } : {});
      // Drop it from the in-transit list; the closed job also leaves the board.
      set((s) => ({ inTransit: s.inTransit.filter((j) => j.id !== jobId) }));
      return true;
    } catch (err) {
      set({ error: apiError(err) });
      return false;
    }
  },

  fetchNeedsAttention: async (opts) => {
    if (!opts?.silent) set({ needsAttentionLoading: true });
    try {
      const { data } = await api.get<{ data: ProductionJob[] }>('/production-jobs/needs-attention');
      set({ needsAttention: data.data });
    } catch {
      // Non-critical: the panel stays as-is; the rest of the board is unaffected.
    } finally {
      set({ needsAttentionLoading: false });
    }
  },

  resolveReturn: async (jobId, disposition, note) => {
    await ensureCsrf();
    try {
      await api.post(`/production-jobs/${jobId}/resolve-return`, {
        disposition,
        ...(note ? { note } : {}),
      });
      // The parcel leaves the needs-attention list; a reship re-enters the make
      // queue, so reconcile both against server truth.
      await get().fetchNeedsAttention({ silent: true });
      await get().fetchQueue({ silent: true });
      return true;
    } catch (err) {
      set({ error: apiError(err) });
      return false;
    }
  },

  subscribe: () => {
    if (get().subscribed) return;
    // Reconcile the queue after a socket reconnect (events missed while down).
    offReconnect = onEchoReconnect(() => void get().fetchQueue({ silent: true }));

    queueUpdatedListener = (e: QueueUpdatedPayload) => {
      const existing = get().jobs.find((j) => j.id === e.job_id);
      if (e.action === 'queued' && !existing) {
        // The lightweight broadcast omits artwork_refs/line_items entirely. For
        // a job we've never loaded, merging the stub below would permanently
        // hide the print-file download and customization panel until a manual
        // reload - there's no future refetch to backfill them. Hydrate the
        // full job from the API instead; this is the no-polling live board's
        // only source of truth for a job that appears after initial load.
        void get().fetchQueue({ silent: true });
        return;
      }
      // A job that ships leaves the FCFS board and joins the in-transit list;
      // one that closes (delivered) leaves both. Refresh the panel so it tracks
      // realtime without a manual reload.
      if (e.action === 'shipped' || e.action === 'closed') {
        void get().fetchInTransit({ silent: true });
      }
      set((s) => {
        if (e.action === 'closed') {
          return { jobs: s.jobs.filter((j) => j.id !== e.job_id) };
        }
        const next: ProductionJob = {
          id: e.job_id,
          quote_id: e.quote_id,
          // The broadcast carries the reference, but fall back to what we
          // already loaded so a realtime tick can never blank the identifier.
          quote_reference: e.quote_reference ?? existing?.quote_reference ?? null,
          track: e.track,
          state: e.state,
          ready_at: e.ready_at,
          // The lightweight broadcast carries no print files; keep what we loaded
          // so the floor's download links survive a realtime state change.
          artwork_refs: existing?.artwork_refs,
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
