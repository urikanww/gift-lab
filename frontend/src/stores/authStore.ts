import { create } from 'zustand';
import api, { apiError, ensureCsrf } from '../lib/api';
import { disconnectEcho } from '../lib/echo';
import type { User } from '../types';

export interface RegisterPayload {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
  company_name: string;
  company_registration_no?: string;
  company_phone?: string;
  company_address?: string;
}

interface AuthState {
  user: User | null;
  status: 'idle' | 'loading' | 'ready';
  error: string | null;
  fetchUser: () => Promise<void>;
  login: (email: string, password: string) => Promise<boolean>;
  register: (payload: RegisterPayload) => Promise<boolean>;
  logout: () => Promise<void>;
}

export const useAuthStore = create<AuthState>((set) => ({
  user: null,
  status: 'idle',
  error: null,

  fetchUser: async () => {
    set({ status: 'loading', error: null });
    try {
      const { data } = await api.get<User>('/user');
      set({ user: data, status: 'ready' });
    } catch {
      // 401 is expected for anonymous visitors browsing the public catalogue.
      set({ user: null, status: 'ready' });
    }
  },

  login: async (email, password) => {
    set({ error: null });
    try {
      await ensureCsrf();
      // /login already returns the authenticated user - trust its body instead of
      // a second /user round-trip, which also removes a failure window where a
      // flaky follow-up request would flip a successful login to "unauthenticated".
      const { data } = await api.post<{ user: User }>('/login', { email, password });
      set({ user: data.user, status: 'ready' });
      return true;
    } catch (err) {
      set({ error: apiError(err) });
      return false;
    }
  },

  register: async (payload) => {
    set({ error: null });
    try {
      await ensureCsrf();
      // /register signs the new buyer in and returns the user, mirroring /login.
      const { data } = await api.post<{ user: User }>('/register', payload);
      set({ user: data.user, status: 'ready' });
      return true;
    } catch (err) {
      set({ error: apiError(err) });
      return false;
    }
  },

  logout: async () => {
    try {
      await api.post('/logout');
    } finally {
      disconnectEcho();
      set({ user: null });
    }
  },
}));
