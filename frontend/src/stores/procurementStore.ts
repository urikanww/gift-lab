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
   */
  fetchAlerts: () => Promise<void>;
  subscribe: () => void;
  unsubscribe: () => void;
  reconfirm: (
    lineItemId: number,
    action: 'amend' | 'approve' | 'drop',
    payload?: { qty: number; unit_price: number },
  ) => Promise<void>;
}

export const useProcurementStore = create<ProcurementStoreState>((set, get) => ({
  alerts: [],
  subscribed: false,
  loading: false,
  error: null,

  fetchAlerts: async () => {
    set({ loading: true, error: null });
    try {
      const { data } = await api.get<{ data: AwaitingReconfirmLine[] }>(
        '/procurement/awaiting-reconfirm',
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
      await api.post(`/line-items/${lineItemId}/reconfirm`, { action, ...payload });
      // Only drop the alert from the desk once the resolution actually persisted
      // - a rejected request now surfaces `error` and keeps the alert visible
      // (was an unhandled rejection that left the operator with no feedback).
      set((s) => ({ alerts: s.alerts.filter((a) => a.line_item_id !== lineItemId) }));
    } catch (err) {
      set({ error: apiError(err) });
    }
  },
}));
