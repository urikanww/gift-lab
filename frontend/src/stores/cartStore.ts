import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import api, { apiError } from '../lib/api';
import type { CartLine, Customization, PriceEstimate, Product, Variant } from '../types';

function hasCustomization(c: Customization): boolean {
  return Boolean(c.logo_size || c.artwork_ref);
}

interface CartState {
  lines: CartLine[];
  estimate: PriceEstimate | null;
  estimating: boolean;
  estimateError: string | null;
  addLine: (product: Product, variant: Variant | null, customization: Customization, qty?: number) => void;
  updateQty: (key: string, qty: number) => void;
  removeLine: (key: string) => void;
  clear: () => void;
  refreshEstimate: () => Promise<void>;
}

// Cart survives reloads/direct links via localStorage. Only the lines are
// persisted — the estimate is server-derived and re-fetched, so a stale price
// is never rehydrated.
export const useCartStore = create<CartState>()(
  persist(
    (set, get) => ({
      lines: [],
      estimate: null,
      estimating: false,
      estimateError: null,

      addLine: (product, variant, customization, qty = 1) => {
        const key = `${product.id}:${variant?.id ?? 0}:${Date.now()}`;
        const safeQty = Number.isFinite(qty) ? Math.max(1, Math.floor(qty)) : 1;
        set((s) => ({ lines: [...s.lines, { key, product, variant, qty: safeQty, customization }] }));
      },

      updateQty: (key, qty) =>
        set((s) => ({
          // Guard against NaN (an emptied number input sends Number('') === NaN,
          // and Math.max(1, NaN) === NaN) — floor to a valid integer ≥ 1.
          lines: s.lines.map((l) =>
            l.key === key ? { ...l, qty: Number.isFinite(qty) ? Math.max(1, Math.floor(qty)) : 1 } : l,
          ),
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
              logo_size: l.customization.logo_size ?? null,
            })),
          });
          set({ estimate: data, estimating: false });
        } catch (err) {
          set({ estimating: false, estimateError: apiError(err) });
        }
      },
    }),
    {
      name: 'giftlab-cart',
      partialize: (s) => ({ lines: s.lines }),
    },
  ),
);
