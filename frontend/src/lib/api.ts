import axios, { AxiosError } from 'axios';

// Resolve the API origin from env, falling back to the local dev host. Without a
// fallback, a missing VITE_API_URL silently produces `undefined/api/...` requests
// that 404 against the SPA dev server instead of hitting the backend.
const API_ORIGIN = import.meta.env.VITE_API_URL ?? 'http://localhost:8000';

// Sanctum SPA cookie auth: withCredentials + XSRF cookie/header. The API and
// SPA must share a top-level domain in production for the cookie to apply.
const api = axios.create({
  baseURL: `${API_ORIGIN}/api`,
  withCredentials: true,
  withXSRFToken: true,
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
});

let csrfInitialized = false;

/**
 * Prime the CSRF cookie before the first mutating request (Sanctum requirement).
 */
export async function ensureCsrf(): Promise<void> {
  if (csrfInitialized) return;
  await axios.get(`${API_ORIGIN}/sanctum/csrf-cookie`, {
    withCredentials: true,
  });
  csrfInitialized = true;
}

// Global 401 handling: a session that expires mid-session should land the user
// on a clean re-auth, not scatter generic "Something went wrong" toasts. The
// /user probe legitimately 401s for anonymous visitors browsing the public
// catalogue, and /login failures are handled inline — those are excluded.
api.interceptors.response.use(
  (response) => response,
  (error: unknown) => {
    if (error instanceof AxiosError && error.response?.status === 401) {
      const url = error.config?.url ?? '';
      const isAuthProbe = url.includes('/user') || url.includes('/login');
      if (!isAuthProbe && !window.location.pathname.startsWith('/login')) {
        csrfInitialized = false;
        window.location.assign('/login');
      }
    }
    return Promise.reject(error);
  },
);

/** Normalize an axios error into a human-readable message. */
export function apiError(err: unknown): string {
  if (err instanceof AxiosError) {
    const data = err.response?.data as { message?: string; errors?: Record<string, string[]> } | undefined;
    if (data?.errors) {
      return Object.values(data.errors).flat().join(' ');
    }
    if (data?.message) return data.message;
    if (err.message) return err.message;
  }
  return 'Something went wrong. Please try again.';
}

export default api;
