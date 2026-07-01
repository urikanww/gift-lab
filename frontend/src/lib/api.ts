import axios, { AxiosError } from 'axios';

// Sanctum SPA cookie auth: withCredentials + XSRF cookie/header. The API and
// SPA must share a top-level domain in production for the cookie to apply.
const api = axios.create({
  baseURL: `${import.meta.env.VITE_API_URL}/api`,
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
  await axios.get(`${import.meta.env.VITE_API_URL}/sanctum/csrf-cookie`, {
    withCredentials: true,
  });
  csrfInitialized = true;
}

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
