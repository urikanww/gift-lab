import { create } from 'zustand';
import api, { apiError, ensureCsrf } from '../lib/api';
import { joinSharedPrivate, leaveSharedPrivate } from '../lib/echo';

let procurementChannel: ReturnType<typeof joinSharedPrivate> | null = null;
let reconfirmListener: ((e: ReconfirmAlert) => void) | null = null;

/** A row as the index endpoint returns it (LineItemResource). */
interface AwaitingReconfirmLine {
  id: number;
  quote_id: number;
  quote_reference?: string | null;
  qty: number;
  unit_price: string;
  procured_qty: number | null;
  procured_price: string | null;
}

export interface ReconfirmAlert {
  line_item_id: number;
  quote_id: number;
  /** Displayed identifier; quote_id stays the key alerts are matched on. */
  quote_reference?: string | null;
  reason: string;
  ordered_qty: number;
  procured_qty: number | null;
  unit_price: string;
  procured_price: string | null;
}

/** The subset of LineItemResource fields the reconfirm endpoint's response carries. */
interface ReconfirmedLine {
  id: number;
  quote_id: number;
  quote_reference?: string | null;
  qty: number;
  unit_price: string;
  procured_qty: number | null;
  procured_price: string | null;
  line_state: string;
}

/**
 * The real HTTP outcome of a reconfirm call, so callers can drive toasts off
 * what actually happened instead of inferring it from the alert list shrinking
 * - which the live `.awaiting-reconfirm` subscription can grow mid-await,
 * turning a genuine success into a false "could not resolve" toast.
 */
export interface ReconfirmOutcome {
  success: boolean;
  /**
   * Present on success: the line's resulting state (e.g. an amend that
   * re-blocks comes back AWAITING_RECONFIRM rather than leaving the desk).
   */
  line_state?: string;
  quote_reference?: string | null;
  quote_id?: number;
  error?: string;
}

interface ProcurementStoreState {
  alerts: ReconfirmAlert[];
  subscribed: boolean;
  loading: boolean;
  error: string | null;
  /**
   * Load the lines currently awaiting a decision.
   *
   * The desk used to have no data source at all - only the live broadcast - so
   * a blocked line was visible solely to whoever had the page open at the
   * moment it broke. Everyone else saw "nothing to do" while orders sat stuck.
   *
   * `params` carries the staff-list search/filters (q, updated_from/to, sort);
   * forwarded straight to the index. The live subscription never refetches, so
   * there is nothing to persist - the page owns the current filter state.
   */
  fetchAlerts: (params?: Record<string, string>) => Promise<void>;
  subscribe: () => void;
  unsubscribe: () => void;
  reconfirm: (
    lineItemId: number,
    action: 'amend' | 'approve' | 'drop',
    payload?: { qty: number; unit_price: number },
  ) => Promise<ReconfirmOutcome>;
}

export const useProcurementStore = create<ProcurementStoreState>((set, get) => ({
  alerts: [],
  subscribed: false,
  loading: false,
  error: null,

  fetchAlerts: async (params) => {
    set({ loading: true, error: null });
    try {
      const { data } = await api.get<{ data: AwaitingReconfirmLine[] }>(
        '/procurement/awaiting-reconfirm',
        { params },
      );
      // The broadcast payload and the row shape differ; map once here so the
      // page renders one type whichever way a line arrived.
      set({
        alerts: data.data.map((line) => ({
          line_item_id: line.id,
          quote_id: line.quote_id,
          quote_reference: line.quote_reference,
          reason: (line.procured_qty ?? 0) < line.qty ? 'qty_short' : 'price_jumped',
          ordered_qty: line.qty,
          procured_qty: line.procured_qty,
          unit_price: line.unit_price,
          procured_price: line.procured_price,
        })),
        loading: false,
      });
    } catch (err) {
      set({ loading: false, error: apiError(err) });
    }
  },

  subscribe: () => {
    if (get().subscribed) return;
    reconfirmListener = (e: ReconfirmAlert) => {
      set((s) => ({
        alerts: [e, ...s.alerts.filter((a) => a.line_item_id !== e.line_item_id)],
      }));
    };
    procurementChannel = joinSharedPrivate('staff.procurement');
    procurementChannel.listen('.line-item.awaiting-reconfirm', reconfirmListener);
    set({ subscribed: true });
  },

  unsubscribe: () => {
    if (!get().subscribed) return;
    if (reconfirmListener) {
      procurementChannel?.stopListening('.line-item.awaiting-reconfirm', reconfirmListener);
      reconfirmListener = null;
    }
    procurementChannel = null;
    leaveSharedPrivate('staff.procurement');
    set({ subscribed: false });
  },

  reconfirm: async (lineItemId, action, payload) => {
    set({ error: null });
    try {
      await ensureCsrf();
      const { data: envelope } = await api.post<{ data: ReconfirmedLine }>(
        `/line-items/${lineItemId}/reconfirm`,
        { action, ...payload },
      );
      const data = envelope.data;
      // Alert removal is decided by the response's `line_state`, not by "the
      // request came back 2xx". An amend re-procure can fail its own re-check
      // and land the line right back in AWAITING_RECONFIRM - that must stay on
      // the desk, not disappear as if it had been resolved.
      if (data.line_state === 'AWAITING_RECONFIRM') {
        set((s) => ({
          alerts: s.alerts.map((a) =>
            a.line_item_id === lineItemId
              ? {
                  ...a,
                  reason: (data.procured_qty ?? 0) < data.qty ? 'qty_short' : 'price_jumped',
                  ordered_qty: data.qty,
                  procured_qty: data.procured_qty,
                  unit_price: data.unit_price,
                  procured_price: data.procured_price,
                }
              : a,
          ),
        }));
      } else {
        set((s) => ({ alerts: s.alerts.filter((a) => a.line_item_id !== lineItemId) }));
      }
      return {
        success: true,
        line_state: data.line_state,
        quote_reference: data.quote_reference,
        quote_id: data.quote_id,
      };
    } catch (err) {
      const message = apiError(err);
      set({ error: message });
      return { success: false, error: message };
    }
  },
}));
