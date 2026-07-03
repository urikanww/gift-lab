import { beforeEach, describe, expect, it, vi } from 'vitest';

// echo.ts constructs a real laravel-echo/Pusher client the first time getEcho()
// is called. Mock both so the refcount registry can be exercised without a real
// websocket connection.
const { privateMock, leaveMock, echoInstances } = vi.hoisted(() => {
  const privateMock = vi.fn((name: string) => ({ name, listen: vi.fn(), stopListening: vi.fn() }));
  const leaveMock = vi.fn();
  const echoInstances: unknown[] = [];
  return { privateMock, leaveMock, echoInstances };
});

vi.mock('laravel-echo', () => ({
  default: class MockEcho {
    connector = { pusher: { connection: { bind: vi.fn() } } };
    private = privateMock;
    leave = leaveMock;
    disconnect = vi.fn();
    constructor() {
      echoInstances.push(this);
    }
  },
}));

vi.mock('pusher-js', () => ({
  default: class MockPusher {},
}));

import { disconnectEcho, joinSharedPrivate, leaveSharedPrivate } from './echo';

beforeEach(() => {
  privateMock.mockClear();
  leaveMock.mockClear();
  disconnectEcho();
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
