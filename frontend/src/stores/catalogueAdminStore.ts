import { create } from 'zustand';
import api, { apiError, ensureCsrf } from '../lib/api';
import type { AdminCatalogueItem } from '../types';

interface CatalogueAdminState {
  items: AdminCatalogueItem[];
  loading: boolean;
  error: string | null;
  autoPublish: boolean;
  autoPublishSaving: boolean;
  /** Last filter used, so silent refetches after a mutation keep the view. */
  lastFilter?: { class?: string; state?: string };
  fetch: (filter?: { class?: string; state?: string }, opts?: { silent?: boolean }) => Promise<void>;
  publish: (id: number) => Promise<void>;
  unpublish: (id: number) => Promise<void>;
  bulkPublish: (ids: number[]) => Promise<{ published: number; failed: number } | null>;
  setAutoPublish: (enabled: boolean) => Promise<boolean>;
  verifyEstimates: (
    id: number,
    estimates: { filament_material: string; filament_color: string; est_grams: number },
  ) => Promise<boolean>;
  uploadModelFile: (id: number, file: File) => Promise<boolean>;
}

export const useCatalogueAdminStore = create<CatalogueAdminState>((set, get) => ({
  items: [],
  loading: false,
  error: null,
  autoPublish: false,
  autoPublishSaving: false,

  // A silent refetch keeps the current list rendered (no skeleton, no scroll
  // jump) — used after a row mutation so the staffer stays exactly where they
  // were. `filter` defaults to the last one used, so filters survive the reload.
  fetch: async (filter, opts) => {
    const activeFilter = filter ?? get().lastFilter;
    set({ loading: opts?.silent ? get().loading : true, error: null, lastFilter: activeFilter });
    try {
      const { data } = await api.get<{
        data: AdminCatalogueItem[];
        meta?: { auto_publish?: boolean };
      }>('/admin/catalogue', { params: activeFilter });
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
      await get().fetch(undefined, { silent: true });
    } catch (err) {
      set({ error: apiError(err) });
    }
  },

  unpublish: async (id) => {
    set({ error: null });
    try {
      await ensureCsrf();
      await api.post(`/admin/products/${id}/unpublish`);
      await get().fetch(undefined, { silent: true });
    } catch (err) {
      set({ error: apiError(err) });
    }
  },

  // Bulk-approve the ready rows in one request, then silently refetch the gate
  // so the list reflects the new state without a skeleton flash. Returns the
  // {published, failed} tally (or null on request failure) so the page can toast.
  bulkPublish: async (ids) => {
    set({ error: null });
    try {
      await ensureCsrf();
      const { data } = await api.post<{ meta: { published: number; failed: number } }>(
        '/admin/products/bulk-publish',
        { ids },
      );
      await get().fetch(undefined, { silent: true });
      return { published: data.meta.published, failed: data.meta.failed };
    } catch (err) {
      set({ error: apiError(err) });
      return null;
    }
  },

  // Both return success so the row UI can close its inline form only when the
  // change persisted.
  verifyEstimates: async (id, estimates) => {
    set({ error: null });
    try {
      await ensureCsrf();
      await api.post(`/admin/products/${id}/verify-estimates`, estimates);
      await get().fetch(undefined, { silent: true });
      return true;
    } catch (err) {
      set({ error: apiError(err) });
      return false;
    }
  },

  uploadModelFile: async (id, file) => {
    set({ error: null });
    try {
      await ensureCsrf();
      const form = new FormData();
      form.append('file', file);
      await api.post(`/admin/products/${id}/model-file`, form, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      await get().fetch(undefined, { silent: true });
      return true;
    } catch (err) {
      set({ error: apiError(err) });
      return false;
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
