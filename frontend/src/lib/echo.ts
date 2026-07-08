import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// Laravel Reverb speaks the Pusher protocol. This is the ONLY real-time
// transport in the app - there is no polling anywhere (hard spec constraint).

declare global {
  interface Window {
    Pusher: typeof Pusher;
    Echo?: Echo<'reverb'>;
  }
}

let echo: Echo<'reverb'> | null = null;

// Reconnect reconciliation: because the write-path UI treats Reverb pushes as
// the primary update channel, events missed during a socket drop must be
// reconciled once the socket comes back. Stores register a refetch here; it
// fires only on a RE-connect (not the first connect).
type ReconnectHandler = () => void;
const reconnectHandlers = new Set<ReconnectHandler>();
let hasConnectedOnce = false;

/**
 * Register a callback to run when the websocket reconnects after a drop.
 * Returns an unregister function.
 */
export function onEchoReconnect(handler: ReconnectHandler): () => void {
  reconnectHandlers.add(handler);
  return () => reconnectHandlers.delete(handler);
}

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

  // Detect reconnects and fan out to registered refetch handlers so views can
  // reconcile any events missed while the socket was down.
  const connector = echo.connector as unknown as { pusher?: Pusher };
  connector.pusher?.connection.bind('state_change', ({ current }: { current: string }) => {
    if (current === 'connected') {
      if (hasConnectedOnce) {
        reconnectHandlers.forEach((handler) => handler());
      }
      hasConnectedOnce = true;
    }
  });

  window.Echo = echo;
  return echo;
}

/** Tear down the connection on logout so channels don't leak across sessions. */
export function disconnectEcho(): void {
  echo?.disconnect();
  echo = null;
  window.Echo = undefined;
  reconnectHandlers.clear();
  hasConnectedOnce = false;
  sharedRefs.clear();
}

// Refcounted private-channel membership. laravel-echo keeps ONE channel per name
// across the whole app, so multiple stores listening on the same channel must
// NOT each call echo.leave() - the first leaver would tear the channel out from
// under the others. Callers join/leave through this registry; the underlying
// subscription is torn down only when the last participant leaves.
const sharedRefs = new Map<string, number>();

export function joinSharedPrivate(name: string) {
  sharedRefs.set(name, (sharedRefs.get(name) ?? 0) + 1);
  return getEcho().private(name);
}

export function leaveSharedPrivate(name: string): void {
  const next = (sharedRefs.get(name) ?? 1) - 1;
  if (next <= 0) {
    sharedRefs.delete(name);
    getEcho().leave(name);
  } else {
    sharedRefs.set(name, next);
  }
}
