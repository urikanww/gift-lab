import { useEffect, useState } from 'react';
import api, { API_ORIGIN } from './api';

/**
 * Full-page URL that starts the Google OAuth dance on the BACKEND (not /api - the
 * redirect + callback are browser navigations on the `web` session group). Used
 * with a plain <a href> / window.location, never axios: OAuth needs a real
 * top-level navigation so cookies and the provider redirect work.
 */
export function googleRedirectUrl(): string {
  return `${API_ORIGIN}/auth/google/redirect`;
}

export interface SocialProviders {
  google: boolean;
}

// One probe per page load, shared by every caller (Login + Register both ask).
// A failed probe resolves to "nothing enabled" so the UI simply hides buttons
// rather than throwing.
let providersProbe: Promise<SocialProviders> | null = null;

export function fetchProviders(): Promise<SocialProviders> {
  if (!providersProbe) {
    providersProbe = api
      .get<SocialProviders>('/auth/providers')
      .then((r) => ({ google: !!r.data?.google }))
      .catch(() => ({ google: false }));
  }
  return providersProbe;
}

/** Test seam: reset the module-level probe between tests. */
export function __resetProvidersProbe(): void {
  providersProbe = null;
}

/** True once the backend confirms Google is configured. */
export function useGoogleEnabled(): boolean {
  const [enabled, setEnabled] = useState(false);
  useEffect(() => {
    let mounted = true;
    fetchProviders().then((p) => {
      if (mounted) setEnabled(p.google);
    });
    return () => {
      mounted = false;
    };
  }, []);
  return enabled;
}
