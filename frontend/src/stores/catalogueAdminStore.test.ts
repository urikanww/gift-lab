import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { AdminCatalogueItem } from '../types';

const { get, post, del } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), del: vi.fn() }));
vi.mock('../lib/api', () => ({
  default: { get, post, delete: del, patch: vi.fn() },
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

  it('deletes a product then refetches', async () => {
    del.mockResolvedValue({ data: {} });
    get.mockResolvedValue({ data: { data: [] } });

    await useCatalogueAdminStore.getState().deleteProduct(7);

    expect(del).toHaveBeenCalledWith('/admin/catalogue/7');
    expect(get).toHaveBeenCalledOnce();
  });

  it('bulk-deletes then refetches, surfacing the skipped ids', async () => {
    post.mockResolvedValue({ data: { deleted: [7], skipped: [9] } });
    get.mockResolvedValue({ data: { data: [] } });

    const result = await useCatalogueAdminStore.getState().bulkDelete([7, 9]);

    expect(post).toHaveBeenCalledWith('/admin/catalogue/bulk-delete', { ids: [7, 9] });
    expect(get).toHaveBeenCalledOnce();
    expect(result).toEqual({ deleted: [7], skipped: [9] });
  });

  it('returns null and records an error when bulk-delete fails', async () => {
    post.mockImplementation(async () => {
      throw new Error('nope');
    });

    const result = await useCatalogueAdminStore.getState().bulkDelete([7]);

    expect(result).toBeNull();
    expect(useCatalogueAdminStore.getState().error).toContain('nope');
  });

  it('records an error on fetch failure', async () => {
    get.mockImplementation(async () => {
      throw new Error('boom');
    });

    await useCatalogueAdminStore.getState().fetch();

    expect(useCatalogueAdminStore.getState().error).toContain('boom');
  });
});
