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

// Realtime is a progressive enhancement, never a hard requirement: if Reverb
// config is missing or the Echo/Pusher client fails to construct, the app
// must keep working with live updates simply inactive rather than crashing
// (see StaffProofAlerts and friends, which are mounted unconditionally in the
// staff shell). Once this trips, every join/leave/reconnect call below
// becomes an inert no-op instead of touching a real client.
let realtimeDisabled = false;
let warnedRealtimeDisabled = false;

function warnRealtimeDisabledOnce(cause: unknown): void {
  if (warnedRealtimeDisabled) return;
  warnedRealtimeDisabled = true;
  console.warn(
    '[echo] Realtime (Reverb) unavailable - continuing without live updates for this session.',
    cause,
  );
}

// Tiny null-object standing in for a subscribed channel once realtime is
// disabled. Mirrors the chainable listen/stopListening shape real channels
// expose (see PusherChannel), since callers chain `.listen(a).listen(b)`.
interface NoopChannel {
  listen: (...args: unknown[]) => NoopChannel;
  stopListening: (...args: unknown[]) => NoopChannel;
}
const noopChannel: NoopChannel = {
  listen: () => noopChannel,
  stopListening: () => noopChannel,
};

// Tiny null-object standing in for the Echo client itself once realtime is
// disabled, covering every method callers in this app invoke on it.
const noopEcho = {
  private: () => noopChannel,
  channel: () => noopChannel,
  leave: () => {},
  leaveChannel: () => {},
  disconnect: () => {},
} as unknown as Echo<'reverb'>;

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
  if (realtimeDisabled) return noopEcho;

  try {
    const key = import.meta.env.VITE_REVERB_APP_KEY;
    if (!key) {
      throw new Error('VITE_REVERB_APP_KEY is not set');
    }

    window.Pusher = Pusher;

    const instance = new Echo({
      broadcaster: 'reverb',
      key,
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
    const connector = instance.connector as unknown as { pusher?: Pusher };
    connector.pusher?.connection.bind('state_change', ({ current }: { current: string }) => {
      if (current === 'connected') {
        if (hasConnectedOnce) {
          reconnectHandlers.forEach((handler) => handler());
        }
        hasConnectedOnce = true;
      }
    });

    echo = instance;
    window.Echo = echo;
    return echo;
  } catch (err) {
    // Missing key or a synchronous construction failure (e.g. pusher-js
    // throwing "You must pass your app key...") must never crash the app -
    // realtime is a progressive enhancement, not a hard dependency.
    realtimeDisabled = true;
    warnRealtimeDisabledOnce(err);
    return noopEcho;
  }
}

/** Tear down the connection on logout so channels don't leak across sessions. */
export function disconnectEcho(): void {
  echo?.disconnect();
  echo = null;
  window.Echo = undefined;
  reconnectHandlers.clear();
  hasConnectedOnce = false;
  sharedRefs.clear();
  realtimeDisabled = false;
  warnedRealtimeDisabled = false;
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
