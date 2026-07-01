import { create } from 'zustand';
import api, { apiError, ensureCsrf } from '../lib/api';
import type { AdminCatalogueItem } from '../types';

interface CatalogueAdminState {
  items: AdminCatalogueItem[];
  loading: boolean;
  error: string | null;
  autoPublish: boolean;
  autoPublishSaving: boolean;
  fetch: (filter?: { class?: string; state?: string }) => Promise<void>;
  publish: (id: number) => Promise<void>;
  unpublish: (id: number) => Promise<void>;
  setAutoPublish: (enabled: boolean) => Promise<boolean>;
}

export const useCatalogueAdminStore = create<CatalogueAdminState>((set, get) => ({
  items: [],
  loading: false,
  error: null,
  autoPublish: false,
  autoPublishSaving: false,

  fetch: async (filter) => {
    set({ loading: true, error: null });
    try {
      const { data } = await api.get<{
        data: AdminCatalogueItem[];
        meta?: { auto_publish?: boolean };
      }>('/admin/catalogue', { params: filter });
      set({
        items: data.data,
        autoPublish: data.meta?.auto_publish ?? get().autoPublish,
        loading: false,
      });
    } catch (err) {
      set({ loading: false, error: apiError(err) });
    }
  },

  publish: async (id) => {
    set({ error: null });
    try {
      await ensureCsrf();
      await api.post(`/admin/products/${id}/publish`);
      await get().fetch();
    } catch (err) {
      set({ error: apiError(err) });
    }
  },

  unpublish: async (id) => {
    set({ error: null });
    try {
      await ensureCsrf();
      await api.post(`/admin/products/${id}/unpublish`);
      await get().fetch();
    } catch (err) {
      set({ error: apiError(err) });
    }
  },

  // Returns whether the PATCH persisted so the caller can revert an optimistic
  // checkbox flip on failure (was swallowing errors → checkbox could show a
  // policy change that never saved).
  setAutoPublish: async (enabled) => {
    set({ autoPublishSaving: true, error: null });
    try {
      await ensureCsrf();
      await api.patch('/admin/settings/auto-publish', { enabled });
      set({ autoPublish: enabled });
      return true;
    } catch (err) {
      set({ error: apiError(err) });
      return false;
    } finally {
      set({ autoPublishSaving: false });
    }
  },
}));
