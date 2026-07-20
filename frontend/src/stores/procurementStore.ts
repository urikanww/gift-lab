import { create } from 'zustand';
import api, { apiError, ensureCsrf } from '../lib/api';
import { joinSharedPrivate, leaveSharedPrivate } from '../lib/echo';

let procurementChannel: ReturnType<typeof joinSharedPrivate> | null = null;
let reconfirmListener: ((e: ReconfirmAlert) => void) | null = null;

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
  error: string | null;
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
  error: null,

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
