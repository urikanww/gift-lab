import { create } from 'zustand';
import api, { ensureCsrf } from '../lib/api';
import { getEcho } from '../lib/echo';

export interface ReconfirmAlert {
  line_item_id: number;
  quote_id: number;
  reason: string;
  ordered_qty: number;
  procured_qty: number | null;
  unit_price: string;
  procured_price: string | null;
}

interface ProcurementStoreState {
  alerts: ReconfirmAlert[];
  subscribed: boolean;
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

  subscribe: () => {
    if (get().subscribed) return;
    getEcho()
      .private('staff.procurement')
      .listen('.line-item.awaiting-reconfirm', (e: ReconfirmAlert) => {
        set((s) => ({
          alerts: [e, ...s.alerts.filter((a) => a.line_item_id !== e.line_item_id)],
        }));
      });
    set({ subscribed: true });
  },

  unsubscribe: () => {
    if (!get().subscribed) return;
    getEcho().leave('staff.procurement');
    set({ subscribed: false });
  },

  reconfirm: async (lineItemId, action, payload) => {
    await ensureCsrf();
    await api.post(`/line-items/${lineItemId}/reconfirm`, { action, ...payload });
    // Once resolved, drop the alert from the desk.
    set((s) => ({ alerts: s.alerts.filter((a) => a.line_item_id !== lineItemId) }));
  },
}));
