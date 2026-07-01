import api from './api';
import type { Paginated, Product, PriceEstimate } from '../types';

export function fetchCatalogue(page = 1): Promise<Paginated<Product>> {
  return api.get<Paginated<Product>>('/catalogue', { params: { page } }).then((r) => r.data);
}

export function fetchProduct(id: number | string): Promise<Product> {
  return api.get<Product>(`/catalogue/${id}`).then((r) => r.data);
}

export interface TierPrice {
  qty: number;
  unitPrice: number;
  currency: string;
}

export async function fetchTierPrices(
  productId: number,
  variantId: number | null,
  quantities: number[],
): Promise<TierPrice[]> {
  const results = await Promise.all(
    quantities.map((qty) =>
      api
        .post<PriceEstimate>('/price-estimate', {
          line_items: [{ product_id: productId, variant_id: variantId, qty, has_customization: false }],
        })
        .then((r) => ({ qty, unitPrice: r.data.lines[0]?.unit_price ?? 0, currency: r.data.currency })),
    ),
  );
  return results;
}
