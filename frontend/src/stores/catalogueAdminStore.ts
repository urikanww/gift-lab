import { create } from 'zustand';
import api, { apiError, ensureCsrf } from '../lib/api';
import type { AdminCatalogueItem } from '../types';

interface CatalogueAdminState {
  items: AdminCatalogueItem[];
  loading: boolean;
  error: string | null;
  autoPublishSaving: boolean;
  fetch: (filter?: { class?: string; state?: string }) => Promise<void>;
  publish: (id: number) => Promise<void>;
  unpublish: (id: number) => Promise<void>;
  setAutoPublish: (enabled: boolean) => Promise<void>;
}

export const useCatalogueAdminStore = create<CatalogueAdminState>((set, get) => ({
  items: [],
  loading: false,
  error: null,
  autoPublishSaving: false,

  fetch: async (filter) => {
    set({ loading: true, error: null });
    try {
      const { data } = await api.get<{ data: AdminCatalogueItem[] }>('/admin/catalogue', { params: filter });
      set({ items: data.data, loading: false });
    } catch (err) {
      set({ loading: false, error: apiError(err) });
    }
  },

  publish: async (id) => {
    await ensureCsrf();
    await api.post(`/admin/products/${id}/publish`);
    await get().fetch();
  },

  unpublish: async (id) => {
    await ensureCsrf();
    await api.post(`/admin/products/${id}/unpublish`);
    await get().fetch();
  },

  setAutoPublish: async (enabled) => {
    set({ autoPublishSaving: true });
    try {
      await ensureCsrf();
      await api.patch('/admin/settings/auto-publish', { enabled });
    } finally {
      set({ autoPublishSaving: false });
    }
  },
}));
