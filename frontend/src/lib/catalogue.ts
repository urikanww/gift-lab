import api from './api';
import type { Paginated, Product, PriceEstimate } from '../types';

export function fetchCatalogue(page = 1, productClass = ''): Promise<Paginated<Product>> {
  // Category filtering is server-side: the catalogue paginates at 24/page, so a
  // client-side filter over one loaded page silently hides matches on later pages.
  const params: Record<string, string | number> = { page };
  if (productClass) params.class = productClass;
  return api.get<Paginated<Product>>('/catalogue', { params }).then((r) => r.data);
}

export function fetchProduct(id: number | string): Promise<Product> {
  // The show endpoint wraps the product in a Laravel resource envelope
  // ({ data: {...} }), unlike the paginated index. Unwrap to the Product.
  return api.get<{ data: Product }>(`/catalogue/${id}`).then((r) => r.data.data);
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
