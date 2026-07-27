import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

// echo.ts constructs a real laravel-echo/Pusher client the first time getEcho()
// is called. Mock both so the refcount registry can be exercised without a real
// websocket connection.
const { privateMock, leaveMock, echoInstances, echoCtorState } = vi.hoisted(() => {
  const privateMock = vi.fn((name: string) => ({ name, listen: vi.fn(), stopListening: vi.fn() }));
  const leaveMock = vi.fn();
  const echoInstances: unknown[] = [];
  // Lets individual tests simulate the Echo/Pusher constructor throwing
  // synchronously (the real-world failure mode this suite guards against),
  // without having to re-mock the module per test.
  const echoCtorState = { shouldThrow: false };
  return { privateMock, leaveMock, echoInstances, echoCtorState };
});

vi.mock('laravel-echo', () => ({
  default: class MockEcho {
    connector = { pusher: { connection: { bind: vi.fn() } } };
    private = privateMock;
    leave = leaveMock;
    disconnect = vi.fn();
    constructor() {
      if (echoCtorState.shouldThrow) {
        throw new Error('You must pass your app key when you instantiate Pusher.');
      }
      echoInstances.push(this);
    }
  },
}));

vi.mock('pusher-js', () => ({
  default: class MockPusher {},
}));

import { disconnectEcho, getEcho, joinSharedPrivate, leaveSharedPrivate } from './echo';

beforeEach(() => {
  // Tests that exercise the real (mocked) Echo client need a key present.
  // Do NOT rely on an ambient .env value (there is none in CI / a fresh
  // clone) — stub it here so the suite is self-contained. The graceful-
  // degradation tests override this to '' per-case.
  vi.stubEnv('VITE_REVERB_APP_KEY', 'test-reverb-key');
  privateMock.mockClear();
  leaveMock.mockClear();
  echoInstances.length = 0;
  echoCtorState.shouldThrow = false;
  disconnectEcho();
});

afterEach(() => {
  vi.unstubAllEnvs();
});

describe('shared private channel refcounting', () => {
  it('does not leave the underlying channel while other refholders remain', () => {
    joinSharedPrivate('staff.queue'); // dashboardStore joins
    joinSharedPrivate('staff.queue'); // queueStore joins

    leaveSharedPrivate('staff.queue'); // dashboardStore leaves
    expect(leaveMock).not.toHaveBeenCalled();

    leaveSharedPrivate('staff.queue'); // queueStore leaves (last one)
    expect(leaveMock).toHaveBeenCalledWith('staff.queue');
    expect(leaveMock).toHaveBeenCalledTimes(1);
  });

  it('tracks separate channels independently', () => {
    joinSharedPrivate('staff.queue');
    joinSharedPrivate('staff.procurement');
    joinSharedPrivate('staff.procurement');

    leaveSharedPrivate('staff.queue');
    expect(leaveMock).toHaveBeenCalledWith('staff.queue');

    leaveSharedPrivate('staff.procurement');
    expect(leaveMock).not.toHaveBeenCalledWith('staff.procurement');

    leaveSharedPrivate('staff.procurement');
    expect(leaveMock).toHaveBeenCalledWith('staff.procurement');
  });

  it('disconnectEcho clears refcounts so a fresh session starts clean', () => {
    joinSharedPrivate('staff.queue');
    joinSharedPrivate('staff.queue');

    disconnectEcho();
    leaveMock.mockClear();

    // A single leave after reconnect should now tear the channel down since
    // the refcount was reset, not decremented from 2.
    leaveSharedPrivate('staff.queue');
    expect(leaveMock).toHaveBeenCalledWith('staff.queue');
  });
});

describe('happy path (Reverb key present)', () => {
  it('constructs a real Echo client and subscribes through it', () => {
    const channel = joinSharedPrivate('staff.queue');

    expect(echoInstances).toHaveLength(1);
    expect(privateMock).toHaveBeenCalledWith('staff.queue');
    expect(channel).toBeDefined();
  });
});

describe('graceful degradation when realtime cannot initialize', () => {
  afterEach(() => {
    vi.unstubAllEnvs();
  });

  it('getEcho() does not throw when VITE_REVERB_APP_KEY is missing', () => {
    vi.stubEnv('VITE_REVERB_APP_KEY', '');

    expect(() => getEcho()).not.toThrow();
    // No real Echo client should have been constructed.
    expect(echoInstances).toHaveLength(0);
  });

  it('joinSharedPrivate returns a callable no-op channel when the key is missing', () => {
    vi.stubEnv('VITE_REVERB_APP_KEY', '');

    let channel: ReturnType<typeof joinSharedPrivate> | undefined;
    expect(() => {
      channel = joinSharedPrivate('staff.queue');
    }).not.toThrow();

    expect(channel).toBeDefined();
    // Callers (e.g. StaffProofAlerts, dashboardStore) call .listen(...) and
    // .stopListening(...) on the returned channel and even chain calls - the
    // no-op must support that shape without ever hitting the real client.
    const noopHandler = vi.fn();
    expect(() => channel!.listen('.proof.changes-requested', noopHandler)).not.toThrow();
    expect(() => channel!.listen('.a', noopHandler).listen('.b', noopHandler)).not.toThrow();
    expect(() => channel!.stopListening('.proof.changes-requested', noopHandler)).not.toThrow();
    expect(privateMock).not.toHaveBeenCalled();

    expect(() => leaveSharedPrivate('staff.queue')).not.toThrow();
    expect(leaveMock).not.toHaveBeenCalled();
  });

  it('does not throw when the underlying Echo/Pusher constructor throws synchronously', () => {
    echoCtorState.shouldThrow = true;

    expect(() => getEcho()).not.toThrow();

    let channel: ReturnType<typeof joinSharedPrivate> | undefined;
    expect(() => {
      channel = joinSharedPrivate('staff.queue');
    }).not.toThrow();
    expect(channel).toBeDefined();
    expect(() => channel!.listen('.some-event', vi.fn())).not.toThrow();
    expect(() => leaveSharedPrivate('staff.queue')).not.toThrow();
  });

  it('warns only once, not per call, while realtime stays disabled', () => {
    vi.stubEnv('VITE_REVERB_APP_KEY', '');
    const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});

    getEcho();
    getEcho();
    joinSharedPrivate('staff.queue');
    leaveSharedPrivate('staff.queue');

    expect(warnSpy).toHaveBeenCalledTimes(1);
    warnSpy.mockRestore();
  });
});
