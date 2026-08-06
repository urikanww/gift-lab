import { create } from 'zustand';
import api, { apiError, ensureCsrf } from '../lib/api';

/** A row on the manual buy list (a LineItemResource with its product embedded). */
export interface BuyListRow {
  id: number;
  product_id: number;
  quote_id: number;
  quote_reference?: string | null;
  qty: number;
  product: {
    name: string;
    class: 'CORE' | 'SCRAPED_UV' | 'MODEL_3D';
    source_url?: string | null;
    affiliate_url?: string | null;
  };
}

interface ProcurementStoreState {
  buyList: BuyListRow[];
  loading: boolean;
  error: string | null;
  /** Load every line waiting to be bought for an approved order. */
  fetchBuyList: () => Promise<void>;
  /** Staff bought one line: raise the bill + push to the floor, drop the row. */
  markBought: (lineItemId: number) => Promise<void>;
  /** "Mark all bought" for one product across orders; drops all its rows. */
  markProductBought: (productId: number) => Promise<void>;
}

export const useProcurementStore = create<ProcurementStoreState>((set) => ({
  buyList: [],
  loading: false,
  error: null,

  fetchBuyList: async () => {
    set({ loading: true, error: null });
    try {
      const { data } = await api.get<{ data: BuyListRow[] }>('/procurement/buy-list');
      set({ buyList: data.data, loading: false });
    } catch (err) {
      set({ loading: false, error: apiError(err) });
    }
  },

  markBought: async (lineItemId: number) => {
    await ensureCsrf();
    await api.post(`/line-items/${lineItemId}/mark-bought`);
    set((s) => ({ buyList: s.buyList.filter((r) => r.id !== lineItemId) }));
  },

  markProductBought: async (productId: number) => {
    await ensureCsrf();
    await api.post(`/procurement/buy-list/mark-product/${productId}`);
    set((s) => ({ buyList: s.buyList.filter((r) => r.product_id !== productId) }));
  },
}));
