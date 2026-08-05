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

export interface GoogleCompletePayload {
  token: string;
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
  completeGoogle: (payload: GoogleCompletePayload) => Promise<{ ok: boolean; fieldErrors: Record<string, string> }>;
  forgotPassword: (email: string) => Promise<{ ok: boolean; message: string }>;
  resetPassword: (payload: ResetPasswordPayload) => Promise<{ ok: boolean; fieldErrors: Record<string, string>; message: string }>;
  logout: () => Promise<void>;
}

export interface ResetPasswordPayload {
  token: string;
  email: string;
  password: string;
  password_confirmation: string;
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

  completeGoogle: async (payload) => {
    set({ error: null });
    try {
      await ensureCsrf();
      // Finishes the two-step Google sign-up: the backend holds the verified
      // Google profile under `payload.token` and creates company + buyer, then
      // signs in and returns the user - mirroring /register's response shape.
      const { data } = await api.post<{ user: User }>('/auth/google/complete', payload);
      set({ user: data.user, status: 'ready' });
      return { ok: true, fieldErrors: {} };
    } catch (err) {
      const fieldErrors = apiFieldErrors(err);
      set({ error: Object.keys(fieldErrors).length > 0 ? null : apiError(err) });
      return { ok: false, fieldErrors };
    }
  },

  // Pre-auth flows: they don't set `user`/`error` (no session yet), so they
  // return their result to the caller instead of driving the global banner.
  forgotPassword: async (email) => {
    try {
      await ensureCsrf();
      // The endpoint always answers generically (anti-enumeration); surface
      // whatever it returns verbatim.
      const { data } = await api.post<{ message: string }>('/forgot-password', { email });
      return { ok: true, message: data.message };
    } catch (err) {
      return { ok: false, message: apiError(err) };
    }
  },

  resetPassword: async (payload) => {
    try {
      await ensureCsrf();
      const { data } = await api.post<{ message: string }>('/reset-password', payload);
      return { ok: true, fieldErrors: {}, message: data.message };
    } catch (err) {
      return { ok: false, fieldErrors: apiFieldErrors(err), message: apiError(err) };
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
