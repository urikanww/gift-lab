import { describe, it, expect, vi, beforeEach } from 'vitest';
import api from './api';
import { fetchCatalogue, fetchProduct, fetchTierPrices } from './catalogue';

vi.mock('./api', () => ({ default: { get: vi.fn(), post: vi.fn() } }));

describe('catalogue lib', () => {
  beforeEach(() => vi.clearAllMocks());

  it('fetchCatalogue passes page param', async () => {
    (api.get as any).mockResolvedValue({ data: { data: [], current_page: 1, last_page: 1 } });
    await fetchCatalogue(2);
    expect(api.get).toHaveBeenCalledWith('/catalogue', { params: { page: 2 } });
  });

  it('fetchProduct hits the show route', async () => {
    (api.get as any).mockResolvedValue({ data: { id: 5, name: 'A5' } });
    const p = await fetchProduct(5);
    expect(api.get).toHaveBeenCalledWith('/catalogue/5');
    expect(p.id).toBe(5);
  });

  it('fetchTierPrices posts one estimate per quantity and returns per-unit', async () => {
    (api.post as any).mockImplementation((_url: string, body: any) =>
      Promise.resolve({ data: { currency: 'SGD', lines: [{ unit_price: 6.4, line_total: 6.4 * body.line_items[0].qty }], subtotal: 0, delivery: 0, total: 0 } }),
    );
    const tiers = await fetchTierPrices(5, null, [25, 100]);
    expect(api.post).toHaveBeenCalledTimes(2);
    expect(tiers).toEqual([
      { qty: 25, unitPrice: 6.4, currency: 'SGD' },
      { qty: 100, unitPrice: 6.4, currency: 'SGD' },
    ]);
  });
});
