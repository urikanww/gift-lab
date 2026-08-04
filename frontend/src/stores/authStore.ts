import { create } from 'zustand';
import api, { apiError, apiFieldErrors, ensureCsrf } from '../lib/api';
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
  consent: boolean;
}

interface AuthState {
  user: User | null;
  status: 'idle' | 'loading' | 'ready';
  error: string | null;
  fetchUser: () => Promise<void>;
  login: (email: string, password: string) => Promise<boolean>;
  register: (payload: RegisterPayload) => Promise<{ ok: boolean; fieldErrors: Record<string, string> }>;
  logout: () => Promise<void>;
}

// In-flight guard for the session probe. Concurrent callers - React 18
// StrictMode double-invokes the boot effect in dev, and any future component
// could fetchUser() alongside App - share ONE /api/user request instead of each
// firing their own. This is what a busy access log reads as "/api/user hit
// twice in the same second"; collapse it to one.
let userProbe: Promise<void> | null = null;

export const useAuthStore = create<AuthState>((set) => ({
  user: null,
  status: 'idle',
  error: null,

  fetchUser: async () => {
    // Coalesce onto the request already in flight rather than starting another.
    if (userProbe) return userProbe;

    set({ status: 'loading', error: null });
    userProbe = (async () => {
      try {
        const { data } = await api.get<User>('/user');
        set({ user: data, status: 'ready' });
      } catch {
        // 401 is expected for anonymous visitors browsing the public catalogue.
        set({ user: null, status: 'ready' });
      } finally {
        userProbe = null;
      }
    })();

    return userProbe;
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
      return { ok: true, fieldErrors: {} };
    } catch (err) {
      // Field-level messages render inline on the form (F1); only a non-field
      // failure (network, 500) falls back to the general error banner.
      const fieldErrors = apiFieldErrors(err);
      set({ error: Object.keys(fieldErrors).length > 0 ? null : apiError(err) });
      return { ok: false, fieldErrors };
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
