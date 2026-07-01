import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// Laravel Reverb speaks the Pusher protocol. This is the ONLY real-time
// transport in the app — there is no polling anywhere (hard spec constraint).

declare global {
  interface Window {
    Pusher: typeof Pusher;
    Echo?: Echo<'reverb'>;
  }
}

let echo: Echo<'reverb'> | null = null;

/**
 * Lazily construct the Echo client. Private channel auth hits the Laravel
 * broadcasting auth endpoint using the Sanctum session cookie.
 */
export function getEcho(): Echo<'reverb'> {
  if (echo) return echo;

  window.Pusher = Pusher;

  echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
    wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
    forceTLS: import.meta.env.VITE_REVERB_SCHEME === 'https',
    enabledTransports: ['ws', 'wss'],
    authEndpoint: `${import.meta.env.VITE_API_URL}/broadcasting/auth`,
    withCredentials: true,
  });

  window.Echo = echo;
  return echo;
}

/** Tear down the connection on logout so channels don't leak across sessions. */
export function disconnectEcho(): void {
  echo?.disconnect();
  echo = null;
  window.Echo = undefined;
}
