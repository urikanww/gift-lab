import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { Product, Variant } from '../types';

const { post } = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock('../lib/api', () => ({
  default: { post },
  apiError: (e: unknown) => String(e),
  ensureCsrf: vi.fn(),
}));

import { useCartStore } from './cartStore';

const product: Product = {
  id: 1,
  name: 'Ceramic Mug',
  description: null,
  class: 'CORE',
  from_price: 3.2,
  currency: 'SGD',
  dimensions: null,
  weight: '320',
  print_method: 'UV',
  stock_mode: 'STOCKED',
  image_url: null,
  is_printable: true,
  creator_credit: null,
};
const variant: Variant = {
  id: 5,
  attributes: { color: 'White' },
  sku: 'CORE-001-01',
  price_delta: '0.00',
  currency: 'SGD',
  in_stock: true,
};

beforeEach(() => {
  useCartStore.setState({ lines: [], estimate: null, estimating: false, estimateError: null });
  post.mockReset();
});

describe('cartStore', () => {
  it('adds, updates, and removes lines', () => {
    const s = useCartStore.getState();
    s.addLine(product, variant, { name_text: 'Acme' });
    let lines = useCartStore.getState().lines;
    expect(lines).toHaveLength(1);
    expect(lines[0].qty).toBe(1);

    const key = lines[0].key;
    useCartStore.getState().updateQty(key, 25);
    expect(useCartStore.getState().lines[0].qty).toBe(25);

    // qty never drops below 1
    useCartStore.getState().updateQty(key, 0);
    expect(useCartStore.getState().lines[0].qty).toBe(1);

    useCartStore.getState().removeLine(key);
    expect(useCartStore.getState().lines).toHaveLength(0);
  });

  it('fetches a live estimate and stores the result', async () => {
    post.mockResolvedValue({
      data: { currency: 'SGD', lines: [{ unit_price: 15, line_total: 45 }], subtotal: 45, delivery: 5, total: 50 },
    });
    useCartStore.getState().addLine(product, variant, {});

    await useCartStore.getState().refreshEstimate();

    const estimate = useCartStore.getState().estimate;
    expect(post).toHaveBeenCalledWith('/price-estimate', expect.anything());
    expect(estimate?.total).toBe(50);
  });

  it('clears the estimate when the cart is empty', async () => {
    await useCartStore.getState().refreshEstimate();
    expect(post).not.toHaveBeenCalled();
    expect(useCartStore.getState().estimate).toBeNull();
  });
});
