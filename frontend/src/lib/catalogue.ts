import api from './api';
import type { Paginated, Product, PriceEstimate } from '../types';

export type CatalogueSort = 'name' | 'newest' | 'price_asc' | 'price_desc';

export interface CatalogueQuery {
  page?: number;
  /** Marketplace category slug (see lib/categories.ts). */
  category?: string;
  /** Server-side name search - keeps pagination valid across all pages. */
  q?: string;
  sort?: CatalogueSort;
}

export function fetchCatalogue(query: CatalogueQuery = {}): Promise<Paginated<Product>> {
  // All filtering/sorting is server-side: the catalogue paginates at 24/page,
  // so client-side filtering over one loaded page would hide later-page matches.
  const params: Record<string, string | number> = { page: query.page ?? 1 };
  if (query.category) params.category = query.category;
  if (query.q?.trim()) params.q = query.q.trim();
  if (query.sort && query.sort !== 'name') params.sort = query.sort;
  return api.get<Paginated<Product>>('/catalogue', { params }).then((r) => r.data);
}

export function fetchProduct(id: number | string): Promise<Product> {
  // The show endpoint wraps the product in a Laravel resource envelope
  // ({ data: {...} }), unlike the paginated index. Unwrap to the Product.
  // Accepts a slug (canonical) or a numeric id (legacy links).
  return api.get<{ data: Product }>(`/catalogue/${id}`).then((r) => r.data.data);
}

/** Canonical public route key: slug when present, id as legacy fallback. */
export function productKey(p: Pick<Product, 'id' | 'slug'>): string {
  return p.slug ?? String(p.id);
}

export const productPath = (p: Pick<Product, 'id' | 'slug'>) => `/products/${productKey(p)}`;

export const designPath = (p: Pick<Product, 'id' | 'slug'>) => `/design/${productKey(p)}`;

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
