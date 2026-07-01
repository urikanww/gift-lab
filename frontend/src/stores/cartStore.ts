import { create } from 'zustand';
import api, { apiError } from '../lib/api';
import type { CartLine, Customization, PriceEstimate, Product, Variant } from '../types';

function hasCustomization(c: Customization): boolean {
  return Boolean(c.logo_size || c.name_text || c.artwork_ref);
}

interface CartState {
  lines: CartLine[];
  estimate: PriceEstimate | null;
  estimating: boolean;
  estimateError: string | null;
  addLine: (product: Product, variant: Variant | null, customization: Customization) => void;
  updateQty: (key: string, qty: number) => void;
  removeLine: (key: string) => void;
  clear: () => void;
  refreshEstimate: () => Promise<void>;
}

export const useCartStore = create<CartState>((set, get) => ({
  lines: [],
  estimate: null,
  estimating: false,
  estimateError: null,

  addLine: (product, variant, customization) => {
    const key = `${product.id}:${variant?.id ?? 0}:${Date.now()}`;
    set((s) => ({ lines: [...s.lines, { key, product, variant, qty: 1, customization }] }));
  },

  updateQty: (key, qty) =>
    set((s) => ({
      lines: s.lines.map((l) => (l.key === key ? { ...l, qty: Math.max(1, qty) } : l)),
    })),

  removeLine: (key) => set((s) => ({ lines: s.lines.filter((l) => l.key !== key) })),

  clear: () => set({ lines: [], estimate: null, estimateError: null }),

  refreshEstimate: async () => {
    const { lines } = get();
    if (lines.length === 0) {
      set({ estimate: null, estimateError: null });
      return;
    }
    set({ estimating: true, estimateError: null });
    try {
      const { data } = await api.post<PriceEstimate>('/price-estimate', {
        line_items: lines.map((l) => ({
          product_id: l.product.id,
          variant_id: l.variant?.id ?? null,
          qty: l.qty,
          has_customization: hasCustomization(l.customization),
        })),
      });
      set({ estimate: data, estimating: false });
    } catch (err) {
      set({ estimating: false, estimateError: apiError(err) });
    }
  },
}));
