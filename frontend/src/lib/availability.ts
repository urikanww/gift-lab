import type { BadgeTone } from '../ui';

export type Availability = 'in_stock' | 'made_to_order' | 'out_of_stock';

/**
 * Customer-facing availability copy. "made_to_order" covers both true
 * make-to-order (3D) and on-demand blanks ordered at zero stock — the buyer
 * doesn't care which, only that it's produced after they order.
 */
export const AVAILABILITY: Record<Availability, { label: string; tone: BadgeTone; note?: string }> = {
  in_stock: { label: 'In stock', tone: 'success' },
  made_to_order: {
    label: 'Made to order',
    tone: 'brand',
    note: 'Produced after you order — see the estimated delivery window in the studio.',
  },
  out_of_stock: { label: 'Out of stock', tone: 'neutral' },
};
