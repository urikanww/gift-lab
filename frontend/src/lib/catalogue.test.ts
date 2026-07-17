import { describe, it, expect, vi, beforeEach } from 'vitest';
import api from './api';
import { fetchBulkPricing, fetchCatalogue, fetchProduct, fetchTierPrices } from './catalogue';

vi.mock('./api', () => ({ default: { get: vi.fn(), post: vi.fn() } }));

describe('catalogue lib', () => {
  beforeEach(() => vi.clearAllMocks());

  it('fetchCatalogue passes page, category, q and sort params', async () => {
    (api.get as any).mockResolvedValue({ data: { data: [], current_page: 1, last_page: 1 } });
    await fetchCatalogue({ page: 2, category: 'drinkware', q: 'mug', sort: 'newest' });
    expect(api.get).toHaveBeenCalledWith('/catalogue', {
      params: { page: 2, category: 'drinkware', q: 'mug', sort: 'newest' },
    });
  });

  it('fetchCatalogue omits empty params and defaults page to 1', async () => {
    (api.get as any).mockResolvedValue({ data: { data: [], current_page: 1, last_page: 1 } });
    await fetchCatalogue();
    expect(api.get).toHaveBeenCalledWith('/catalogue', { params: { page: 1 } });
  });

  it('fetchCatalogue drops sort=name and whitespace-only q, trims a real q', async () => {
    (api.get as any).mockResolvedValue({ data: { data: [], current_page: 1, last_page: 1 } });

    // 'name' is the backend default order; whitespace-only q is noise.
    await fetchCatalogue({ sort: 'name', q: '  ' });
    expect(api.get).toHaveBeenLastCalledWith('/catalogue', { params: { page: 1 } });

    await fetchCatalogue({ q: ' mug ' });
    expect(api.get).toHaveBeenLastCalledWith('/catalogue', { params: { page: 1, q: 'mug' } });
  });

  it('fetchProduct hits the show route and unwraps the resource envelope', async () => {
    (api.get as any).mockResolvedValue({ data: { data: { id: 5, name: 'A5' } } });
    const p = await fetchProduct(5);
    expect(api.get).toHaveBeenCalledWith('/catalogue/5');
    expect(p.id).toBe(5);
  });

  it('fetchTierPrices probes every quantity in ONE request and maps prices back in order', async () => {
    (api.post as any).mockResolvedValue({
      data: {
        currency: 'SGD',
        lines: [{ unit_price: 7.1, line_total: 177.5 }, { unit_price: 6.4, line_total: 640 }],
        subtotal: 0, delivery: 0, total: 0,
      },
    });

    const tiers = await fetchTierPrices(5, 9, [25, 100]);

    expect(api.post).toHaveBeenCalledTimes(1);
    expect(api.post).toHaveBeenCalledWith('/price-estimate', {
      line_items: [
        { product_id: 5, variant_id: 9, qty: 25, has_customization: false },
        { product_id: 5, variant_id: 9, qty: 100, has_customization: false },
      ],
    });
    expect(tiers).toEqual([
      { qty: 25, unitPrice: 7.1, currency: 'SGD' },
      { qty: 100, unitPrice: 6.4, currency: 'SGD' },
    ]);
  });

  it('fetchTierPrices falls back to 0 for a missing line rather than dropping the tier', async () => {
    (api.post as any).mockResolvedValue({
      data: { currency: 'SGD', lines: [{ unit_price: 7.1 }], subtotal: 0, delivery: 0, total: 0 },
    });

    const tiers = await fetchTierPrices(5, null, [25, 100]);

    expect(tiers).toEqual([
      { qty: 25, unitPrice: 7.1, currency: 'SGD' },
      { qty: 100, unitPrice: 0, currency: 'SGD' },
    ]);
  });

  it('fetchBulkPricing maps the offer to camelCase', async () => {
    (api.get as any).mockResolvedValue({ data: { bulk_qty: 50, bulk_discount_pct: 10 } });
    await expect(fetchBulkPricing()).resolves.toEqual({ bulkQty: 50, discountPct: 10 });
    expect(api.get).toHaveBeenCalledWith('/bulk-pricing');
  });

  it('fetchBulkPricing passes through a no-offer response', async () => {
    (api.get as any).mockResolvedValue({ data: { bulk_qty: null, bulk_discount_pct: 0 } });
    await expect(fetchBulkPricing()).resolves.toEqual({ bulkQty: null, discountPct: 0 });
  });

  it('fetchBulkPricing resolves null on failure - callers say nothing rather than break', async () => {
    (api.get as any).mockRejectedValue(new Error('network'));
    await expect(fetchBulkPricing()).resolves.toBeNull();
  });
});
