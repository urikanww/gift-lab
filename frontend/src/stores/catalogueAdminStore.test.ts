import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { AdminCatalogueItem } from '../types';

const { get, post, del } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), del: vi.fn() }));
vi.mock('../lib/api', () => ({
  default: { get, post, patch: vi.fn(), delete: del },
  apiError: (e: unknown) => String(e),
  ensureCsrf: vi.fn(),
}));

import { useCatalogueAdminStore } from './catalogueAdminStore';

const item: AdminCatalogueItem = {
  id: 7,
  name: 'Scraped Mug',
  class: 'SCRAPED_UV',
  publish_state: 'READY_TO_APPROVE',
  cannot_publish_reasons: null,
  weight: null,
  dimensions: null,
  print_method: null,
  is_printable: false,
  stock_estimate: null,
  base_cost: '4.00',
  currency: 'SGD',
  creator_credit: null,
  image_url: null,
  source_url: 'https://shopee.example/p/1',
  source_kind: null,
  filament_material: null,
  filament_color: null,
  est_grams: null,
  estimates_verified: false,
  model_file_ref: null,
};

beforeEach(() => {
  useCatalogueAdminStore.setState({ items: [], loading: false, error: null, autoPublishSaving: false });
  get.mockReset();
  post.mockReset();
  del.mockReset();
});

describe('catalogueAdminStore', () => {
  it('fetches gated items', async () => {
    get.mockResolvedValue({ data: { data: [item] } });

    await useCatalogueAdminStore.getState().fetch();

    expect(get).toHaveBeenCalledWith('/admin/catalogue', { params: undefined });
    expect(useCatalogueAdminStore.getState().items).toHaveLength(1);
  });

  it('publishes then refetches', async () => {
    post.mockResolvedValue({ data: {} });
    get.mockResolvedValue({ data: { data: [] } });

    await useCatalogueAdminStore.getState().publish(7);

    expect(post).toHaveBeenCalledWith('/admin/products/7/publish');
    expect(get).toHaveBeenCalledOnce();
  });

  it('deletes a gate product then refetches', async () => {
    del.mockResolvedValue({ data: { deleted: true } });
    get.mockResolvedValue({ data: { data: [] } });

    const ok = await useCatalogueAdminStore.getState().deleteProduct(7);

    expect(ok).toBe(true);
    expect(del).toHaveBeenCalledWith('/admin/catalogue/7');
    expect(get).toHaveBeenCalledOnce();
  });

  it('returns false and records an error when delete fails', async () => {
    del.mockImplementation(async () => {
      throw new Error('nope');
    });

    const ok = await useCatalogueAdminStore.getState().deleteProduct(7);

    expect(ok).toBe(false);
    expect(useCatalogueAdminStore.getState().error).toContain('nope');
  });

  it('bulk-deletes selected rows then refetches', async () => {
    post.mockResolvedValue({ data: { meta: { deleted: 2, failed: 1 } } });
    get.mockResolvedValue({ data: { data: [] } });

    const result = await useCatalogueAdminStore.getState().bulkDelete([1, 2, 3]);

    expect(result).toEqual({ deleted: 2, failed: 1 });
    expect(post).toHaveBeenCalledWith('/admin/catalogue/bulk-delete', { ids: [1, 2, 3] });
    expect(get).toHaveBeenCalledOnce();
  });

  it('returns null when bulk delete fails', async () => {
    post.mockImplementation(async () => {
      throw new Error('boom');
    });

    const result = await useCatalogueAdminStore.getState().bulkDelete([1]);

    expect(result).toBeNull();
    expect(useCatalogueAdminStore.getState().error).toContain('boom');
  });

  it('records an error on fetch failure', async () => {
    get.mockImplementation(async () => {
      throw new Error('boom');
    });

    await useCatalogueAdminStore.getState().fetch();

    expect(useCatalogueAdminStore.getState().error).toContain('boom');
  });
});
