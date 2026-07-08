# Phase 1 - Navigation & Shared Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the staff sidebar sticky and stop the production queue from flashing a loading skeleton / resetting scroll after an action.

**Architecture:** Two independent, frontend-only changes. Task 1 is a CSS/layout change to `StaffLayout` verified in-browser. Task 2 adds a `silent` option to `queueStore.fetchQueue` so the post-mutation safety refetch does not toggle `loading` - mirroring the pattern already in `catalogueAdminStore`.

**Tech Stack:** React, Zustand, Tailwind CSS, Vitest.

**Note on scope:** The spec's "catalogue gate out of main nav" item is deferred to Phase 4, where the replacement "Catalogue gate" button is added to the Products page - removing it now would leave the gate reachable only by raw URL.

---

### Task 1: Sticky staff sidebar

**Files:**
- Modify: `frontend/src/components/StaffLayout.tsx:75`

- [ ] **Step 1: Make the `<aside>` sticky and full-height**

In `frontend/src/components/StaffLayout.tsx`, the sidebar element currently reads:

```tsx
      <aside className="hidden w-60 shrink-0 border-r border-border bg-surface md:flex md:flex-col md:justify-between md:p-4">
```

Change its className to pin it to the viewport and scroll internally when the nav is tall:

```tsx
      <aside className="hidden w-60 shrink-0 border-r border-border bg-surface md:sticky md:top-0 md:flex md:h-screen md:flex-col md:justify-between md:overflow-y-auto md:p-4">
```

- [ ] **Step 2: Verify in the browser**

Start the preview and log in as superadmin (`superadmin@giftlab.local` / `ChangeMe!123`), open a long staff page (e.g. `/catalogue-admin` or `/product-admin`). Scroll the main content to the bottom.

Expected: the left nav (Dashboard / Quotes / … / Products) stays fixed in view at the bottom of the scroll - it does not scroll away. Confirm via `preview_inspect` that the `<aside>` computed `position` is `sticky` and `top` is `0px`.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/components/StaffLayout.tsx
git commit -m "fix(staff): make console sidebar sticky on long pages"
```

---

### Task 2: Production queue silent refetch

**Files:**
- Modify: `frontend/src/stores/queueStore.ts`
- Create: `frontend/src/stores/queueStore.test.ts`

- [ ] **Step 1: Write the failing test**

Create `frontend/src/stores/queueStore.test.ts`:

```ts
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ProductionJob } from '../types';

const { get, post } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock('../lib/api', () => ({
  default: { get, post },
  apiError: (e: unknown) => String(e),
  ensureCsrf: vi.fn(),
}));
// The store wires Reverb channels at import; stub them out for the test.
vi.mock('../lib/echo', () => ({
  joinSharedPrivate: () => ({ listen: vi.fn(), stopListening: vi.fn() }),
  leaveSharedPrivate: vi.fn(),
  onEchoReconnect: () => () => {},
}));

import { useQueueStore } from './queueStore';

const job: ProductionJob = {
  id: 1,
  quote_id: 10,
  track: '3D',
  state: 'READY',
  ready_at: '2026-07-06T00:00:00Z',
  artwork_ref: null,
  print_method: 'FDM',
  qty: 5,
};

beforeEach(() => {
  useQueueStore.setState({ jobs: [], loading: false, error: null });
  get.mockReset();
  post.mockReset();
});

describe('queueStore', () => {
  it('shows loading on a normal (non-silent) fetch', async () => {
    let resolveGet!: (v: unknown) => void;
    get.mockReturnValue(new Promise((r) => { resolveGet = r; }));

    const p = useQueueStore.getState().fetchQueue();
    expect(useQueueStore.getState().loading).toBe(true); // mid-flight

    resolveGet({ data: { data: [job] } });
    await p;
    expect(useQueueStore.getState().loading).toBe(false);
    expect(useQueueStore.getState().jobs).toHaveLength(1);
  });

  it('advance refetches silently - never flips loading true', async () => {
    useQueueStore.setState({ jobs: [job], loading: false });
    post.mockResolvedValue({ data: {} });
    let resolveGet!: (v: unknown) => void;
    get.mockReturnValue(new Promise((r) => { resolveGet = r; }));

    const p = useQueueStore.getState().advance(1, 'IN_PRODUCTION');
    // The safety refetch must not show a skeleton over the existing list.
    expect(useQueueStore.getState().loading).toBe(false);

    resolveGet({ data: { data: [job] } });
    await p;
    expect(post).toHaveBeenCalledWith('/production-jobs/1/advance', { state: 'IN_PRODUCTION' });
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd frontend && npx vitest run src/stores/queueStore.test.ts`
Expected: the second test FAILS - `advance` currently calls `fetchQueue()` which sets `loading: true`, so the mid-flight assertion `expect(loading).toBe(false)` fails.

- [ ] **Step 3: Add a `silent` option to `fetchQueue` and use it in `advance`**

In `frontend/src/stores/queueStore.ts`, change the `fetchQueue` signature in the interface:

```ts
  fetchQueue: (opts?: { silent?: boolean }) => Promise<void>;
```

Change the `fetchQueue` implementation so it only shows the skeleton on a non-silent load:

```ts
  fetchQueue: async (opts) => {
    set({ loading: opts?.silent ? get().loading : true, error: null });
    try {
      const { data } = await api.get<{ data: ProductionJob[] }>('/production-queue');
      set({ jobs: sortQueue(data.data), loading: false });
    } catch (err) {
      set({ loading: false, error: apiError(err) });
    }
  },
```

In `advance`, make both refetches silent (the list is already on screen; a broadcast or this refetch reconciles it without a skeleton):

```ts
      await get().fetchQueue({ silent: true });
    } catch (err) {
      set({ error: apiError(err) });
      await get().fetchQueue({ silent: true });
    }
```

Also update the reconnect subscription in `subscribe()` to refetch silently:

```ts
    offReconnect = onEchoReconnect(() => void get().fetchQueue({ silent: true }));
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd frontend && npx vitest run src/stores/queueStore.test.ts`
Expected: PASS (2 tests).

- [ ] **Step 5: Typecheck**

Run: `cd frontend && npx tsc --noEmit`
Expected: no output (clean).

- [ ] **Step 6: Commit**

```bash
git add frontend/src/stores/queueStore.ts frontend/src/stores/queueStore.test.ts
git commit -m "fix(production): silent refetch after advance - no skeleton flash or scroll jump"
```

---

## Self-review

- **Spec coverage:** Phase 1 items 1.1 (sticky sidebar → Task 1) and 1.2 (production silent refetch → Task 2) are covered. Item 1.3 (gate out of nav) intentionally deferred to Phase 4 alongside its replacement button - noted at the top.
- **Placeholder scan:** none - all steps carry exact classNames, code, commands, and expected output.
- **Type consistency:** `fetchQueue(opts?: { silent?: boolean })` matches interface and both call sites (`advance`, `subscribe`); `ProductionJob` fixture matches the interface in `types.ts` (`track: '3D'`, `state: 'READY'`, `print_method: 'FDM'`).
