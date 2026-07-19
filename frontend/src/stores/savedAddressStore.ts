import { create } from 'zustand';
import api, { apiError, ensureCsrf } from '../lib/api';
import type { SavedAddress } from '../types';

interface SavedAddressState {
  addresses: SavedAddress[];
  loading: boolean;
  error: string | null;
  fetch: () => Promise<void>;
  create: (payload: Omit<SavedAddress, 'id'>) => Promise<boolean>;
  update: (id: number, payload: Omit<SavedAddress, 'id'>) => Promise<boolean>;
  remove: (id: number) => Promise<boolean>;
}

export const MAX_SAVED_ADDRESSES = 3;

export const useSavedAddressStore = create<SavedAddressState>((set, get) => ({
  addresses: [],
  loading: false,
  error: null,

  fetch: async () => {
    set({ loading: true, error: null });
    try {
      const { data } = await api.get<{ data: SavedAddress[] }>('/saved-addresses');
      set({ addresses: data.data, loading: false });
    } catch (err) {
      set({ loading: false, error: apiError(err) });
    }
  },

  create: async (payload) => {
    set({ error: null });
    try {
      await ensureCsrf();
      const { data } = await api.post<{ data: SavedAddress }>('/saved-addresses', payload);
      set({ addresses: [data.data, ...get().addresses] });
      return true;
    } catch (err) {
      set({ error: apiError(err) });
      return false;
    }
  },

  update: async (id, payload) => {
    set({ error: null });
    try {
      await ensureCsrf();
      const { data } = await api.put<{ data: SavedAddress }>(`/saved-addresses/${id}`, payload);
      set({ addresses: get().addresses.map((a) => (a.id === id ? data.data : a)) });
      return true;
    } catch (err) {
      set({ error: apiError(err) });
      return false;
    }
  },

  remove: async (id) => {
    set({ error: null });
    try {
      await ensureCsrf();
      await api.delete(`/saved-addresses/${id}`);
      set({ addresses: get().addresses.filter((a) => a.id !== id) });
      return true;
    } catch (err) {
      set({ error: apiError(err) });
      return false;
    }
  },
}));
