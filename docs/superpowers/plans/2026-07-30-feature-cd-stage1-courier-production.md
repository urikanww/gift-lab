# Features C+D Stage 1 — Courier/Production Rework Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Unblock returned-parcel resolution (F10 🔴) and resurface the production page into 4 tabs, plus dialog/PO UX fixes — with no DB schema change.

**Architecture:** Backend adds a `needs_attention` derived flag + a `needsAttention()` query and route, and excludes needs-attention parcels from the in-transit list. Frontend gains a `resolveReturn` store action, a Needs-attention panel, a 4-tab production page (Make / Ship desk / In-transit / Needs-attention), a scrollable modal with sticky footer, SG-locked address fields, and a required PO marker. The NinjaVan courier core (webhook keyed on `consignment_ref`) is untouched and guarded by a test.

**Tech Stack:** Laravel 11 / PHP 8.3 / Pest v3 (SQLite test DB, RefreshDatabase); React + TypeScript + Zustand + Vitest/RTL + Vite.

**Scope:** Stage 1 only (per spec `docs/superpowers/specs/2026-07-30-giftlab-feature-cd-courier-production-design.md`). Stage 2 (Shipment entity + grouping + split fee) is a separate future plan.

---

## File Structure

**Backend (modify):**
- `app/Services/QueueService.php` — add `needsAttention()`, narrow `inTransit()`.
- `app/Http/Controllers/ProductionQueueController.php` — add `needsAttention()` action.
- `app/Http/Resources/ProductionJobResource.php` — add `needs_attention` key.
- `routes/api.php` — add `GET /production-jobs/needs-attention`.
- `tests/Feature/NeedsAttentionSurfaceTest.php` (create), `tests/Feature/NinjaVanWebhookTest.php` (append guard).

**Frontend (modify/create):**
- `frontend/src/types.ts` — add `needs_attention` to `ProductionJob`.
- `frontend/src/stores/queueStore.ts` — add `needsAttention` state, `fetchNeedsAttention`, `resolveReturn`.
- `frontend/src/stores/queueStore.test.ts` (create).
- `frontend/src/components/production/NeedsAttentionPanel.tsx` (create) + `.test.tsx`.
- `frontend/src/pages/ProductionQueuePage.tsx` — 4-tab restructure, move courier actions to Ship desk.
- `frontend/src/ui/Modal.tsx` — scrollable body + sticky footer.
- `frontend/src/pages/QuoteDetailPage.tsx` — PO field `required`.
- Relevant `.test.tsx` updates.

---

### Task 1: Backend — needs-attention surface split

**Files:**
- Modify: `app/Http/Resources/ProductionJobResource.php`
- Modify: `app/Services/QueueService.php:493` (`inTransit`), add `needsAttention()`
- Modify: `app/Http/Controllers/ProductionQueueController.php`
- Modify: `routes/api.php:193` (register near `in-transit`)
- Test: `tests/Feature/NeedsAttentionSurfaceTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/NeedsAttentionSurfaceTest.php`. Mirror the setup style of `tests/Feature/ReturnResolutionTest.php` (a SHIPPED job whose `last_courier_status` is a needs-attention label). Use existing factories.

```php
<?php

declare(strict_types=1);

use App\Enums\JobState;
use App\Models\ProductionJob;
use App\Models\Quote;
use App\Models\User;
use App\Services\Courier\NinjaVanStatusMapper;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

/** A staff user permitted to view the production surfaces. */
function prodStaff(): User
{
    return User::factory()->create()->assignRole('staff');
}

function shippedJob(?string $courierStatus = null): ProductionJob
{
    $quote = Quote::factory()->create();
    $job = ProductionJob::factory()->for($quote)->create([
        'state' => JobState::Shipped->value,
        'consignment_ref' => 'NVSG'.fake()->unique()->numerify('#########'),
        'last_courier_status' => $courierStatus,
        'last_courier_status_at' => $courierStatus ? now() : null,
    ]);

    return $job;
}

it('lists a returned/failed parcel in needs-attention and not in in-transit', function (): void {
    $returned = shippedJob(NinjaVanStatusMapper::LABEL_RETURNED);
    $plain = shippedJob('In transit');

    actingAs(prodStaff());

    $needs = getJson('/api/production-jobs/needs-attention')->assertOk()->json('data');
    $transit = getJson('/api/production-jobs/in-transit')->assertOk()->json('data');

    expect(collect($needs)->pluck('id'))->toContain($returned->id)->not->toContain($plain->id);
    expect(collect($transit)->pluck('id'))->toContain($plain->id)->not->toContain($returned->id);
});

it('exposes a needs_attention flag on the resource', function (): void {
    $returned = shippedJob(NinjaVanStatusMapper::LABEL_ATTEMPT_FAILED);

    actingAs(prodStaff());

    $row = collect(getJson('/api/production-jobs/needs-attention')->json('data'))
        ->firstWhere('id', $returned->id);

    expect($row['needs_attention'])->toBeTrue();
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=NeedsAttentionSurface`
Expected: FAIL — route `/api/production-jobs/needs-attention` not defined (404) / missing `needs_attention` key.

> If `Quote::factory` / `ProductionJob::factory` field names differ, align the helper with `ReturnResolutionTest.php`'s existing setup (it already builds this exact shape). Adjust the role name if the project uses a different staff role (check `ReturnResolutionTest`).

- [ ] **Step 3: Add the resource key**

In `app/Http/Resources/ProductionJobResource.php`, add `use App\Services\Courier\NinjaVanStatusMapper;` and, next to the courier context keys (after `last_courier_status_at`):

```php
            // Derived: is this a returned/failed parcel awaiting staff
            // resolution (drives the Needs-attention surface + gates the
            // resolve-return actions client-side). Mirrors the server guard in
            // QueueService::resolveReturn.
            'needs_attention' => NinjaVanStatusMapper::isNeedsAttentionLabel($this->last_courier_status),
```

- [ ] **Step 4: Add the query + narrow inTransit**

In `app/Services/QueueService.php`, add `needsAttention()` and exclude needs-attention labels from `inTransit()`.

Replace the body of `inTransit()` query to add the label exclusion:

```php
    public function inTransit(): Collection
    {
        return ProductionJob::query()
            ->where('state', JobState::Shipped->value)
            // Returned/failed parcels leave this list for the Needs-attention
            // surface - they can't be "marked delivered" (the backend rejects
            // it), so they don't belong on the awaiting-delivery board.
            ->where(fn ($q) => $q
                ->whereNull('last_courier_status')
                ->orWhereNotIn('last_courier_status', [
                    NinjaVanStatusMapper::LABEL_ATTEMPT_FAILED,
                    NinjaVanStatusMapper::LABEL_RETURNED,
                ]))
            ->whereHas('quote')
            ->with(['quote', 'lineItems.product'])
            ->orderByDesc('updated_at')
            ->get();
    }

    /**
     * Parcels NinjaVan reported returned/attempt-failed: SHIPPED jobs whose
     * last courier status is one of NinjaVanStatusMapper's needsAttention
     * labels. The Needs-attention surface; each is resolved via resolveReturn
     * (reship / close / cancel-credit). Cancelled/soft-deleted quotes excluded.
     *
     * @return Collection<int, ProductionJob>
     */
    public function needsAttention(): Collection
    {
        return ProductionJob::query()
            ->where('state', JobState::Shipped->value)
            ->whereIn('last_courier_status', [
                NinjaVanStatusMapper::LABEL_ATTEMPT_FAILED,
                NinjaVanStatusMapper::LABEL_RETURNED,
            ])
            ->whereHas('quote')
            ->with(['quote', 'lineItems.product'])
            ->orderByDesc('last_courier_status_at')
            ->get();
    }
```

Add `use App\Services\Courier\NinjaVanStatusMapper;` if not already imported (it imports `NinjaVanStatusMapper` already — confirm; if present, skip).

- [ ] **Step 5: Add the controller action + route**

In `app/Http/Controllers/ProductionQueueController.php`, after `inTransit()`:

```php
    /**
     * Returned/failed parcels awaiting staff resolution (reship/close/
     * cancel-credit). Read-only; separate from the in-transit board, which now
     * excludes these. Staff-gated like the rest of the queue.
     */
    public function needsAttention(Request $request): AnonymousResourceCollection
    {
        $this->authorize('manageProduction', Quote::class);

        return ProductionJobResource::collection($this->queue->needsAttention());
    }
```

In `routes/api.php`, immediately after the `in-transit` line (`:193`), add (must precede the `/{job}` wildcards):

```php
    Route::get('/production-jobs/needs-attention', [ProductionQueueController::class, 'needsAttention'])->middleware('permission:production.view');
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=NeedsAttentionSurface`
Expected: PASS (2 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Resources/ProductionJobResource.php app/Services/QueueService.php app/Http/Controllers/ProductionQueueController.php routes/api.php tests/Feature/NeedsAttentionSurfaceTest.php
git commit -m "feat(production): needs-attention surface split (query, route, resource flag)"
```

---

### Task 2: Backend — courier-independence guard test

Stage 1 does not touch the webhook; this test locks in that the webhook resolves a parcel by `consignment_ref` only, never by the order reference or job id.

**Files:**
- Test: `tests/Feature/NinjaVanWebhookTest.php` (append)

- [ ] **Step 1: Write the test**

Open `tests/Feature/NinjaVanWebhookTest.php` and reuse its existing helpers (a shipped-job factory + a signed-webhook POST helper — check the top of the file for their exact names; the pattern below assumes `ninjaVanShippedJob()` and `postNinjaVanWebhook(array $payload)`, matching the Feature B guard test added earlier). Append:

```php
it('resolves a NinjaVan webhook only by consignment_ref, never the order reference or job id', function (): void {
    $job = ninjaVanShippedJob(); // SHIPPED, real consignment_ref set

    // Posting the order reference (or the job id) as the tracking number must
    // NOT resolve the parcel - the courier keys off consignment_ref alone.
    postNinjaVanWebhook([
        'tracking_number' => $job->quote->reference,
        'status' => 'Delivered',
    ])->assertOk();
    postNinjaVanWebhook([
        'tracking_number' => (string) $job->id,
        'status' => 'Delivered',
    ])->assertOk();

    expect($job->fresh()->state)->toBe(\App\Enums\JobState::Shipped);

    // The real consignment_ref resolves and closes it.
    postNinjaVanWebhook([
        'tracking_number' => $job->consignment_ref,
        'status' => 'Delivered',
    ])->assertOk();

    expect($job->fresh()->state)->toBe(\App\Enums\JobState::Closed);
});
```

- [ ] **Step 2: Run to verify it passes**

Run: `php artisan test --filter=NinjaVanWebhook`
Expected: PASS (the behaviour already holds; this guards it). If the helper names differ, align them with the file's existing helpers before running.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/NinjaVanWebhookTest.php
git commit -m "test(webhook): assert NinjaVan resolves by consignment_ref, not order id"
```

---

### Task 3: Frontend store — resolveReturn + needs-attention state

**Files:**
- Modify: `frontend/src/types.ts:513` (`ProductionJob`)
- Modify: `frontend/src/stores/queueStore.ts`
- Test: `frontend/src/stores/queueStore.test.ts` (create)

- [ ] **Step 1: Add the type field**

In `frontend/src/types.ts`, inside `interface ProductionJob`, after `last_courier_status_at`:

```ts
  /** True when the courier reported this parcel returned/failed (Needs-attention surface). */
  needs_attention?: boolean;
```

- [ ] **Step 2: Write the failing store test**

Create `frontend/src/stores/queueStore.test.ts`. Mirror `frontend/src/stores/procurementStore.test.ts` for the axios mock setup.

```ts
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useQueueStore } from './queueStore';
import api from '../lib/api';

vi.mock('../lib/api', async () => {
  const actual = await vi.importActual<typeof import('../lib/api')>('../lib/api');
  return {
    ...actual,
    default: { get: vi.fn(), post: vi.fn(), put: vi.fn() },
    ensureCsrf: vi.fn().mockResolvedValue(undefined),
  };
});

const mockApi = api as unknown as { get: ReturnType<typeof vi.fn>; post: ReturnType<typeof vi.fn> };

describe('queueStore returned-parcel resolution', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    useQueueStore.setState({ needsAttention: [], jobs: [], inTransit: [] });
    mockApi.get.mockResolvedValue({ data: { data: [] } });
    mockApi.post.mockResolvedValue({ data: {} });
  });

  it('fetchNeedsAttention loads the needs-attention list', async () => {
    mockApi.get.mockResolvedValueOnce({ data: { data: [{ id: 7 }] } });
    await useQueueStore.getState().fetchNeedsAttention();
    expect(mockApi.get).toHaveBeenCalledWith('/production-jobs/needs-attention');
    expect(useQueueStore.getState().needsAttention).toEqual([{ id: 7 }]);
  });

  it('resolveReturn posts the disposition + note and refetches', async () => {
    await useQueueStore.getState().resolveReturn(7, 'reship', 'damaged box');
    expect(mockApi.post).toHaveBeenCalledWith('/production-jobs/7/resolve-return', {
      disposition: 'reship',
      note: 'damaged box',
    });
    // Refetches needs-attention (+ queue) after resolving.
    expect(mockApi.get).toHaveBeenCalledWith('/production-jobs/needs-attention');
  });
});
```

- [ ] **Step 3: Run to verify it fails**

Run (from `frontend/`): `npx vitest run src/stores/queueStore.test.ts`
Expected: FAIL — `fetchNeedsAttention`/`resolveReturn` not a function.

- [ ] **Step 4: Implement the store additions**

In `frontend/src/stores/queueStore.ts`:

Add to `QueueStoreState` interface (after the `inTransit` fields):

```ts
  /** Parcels the courier reported returned/failed - the Needs-attention surface. */
  needsAttention: ProductionJob[];
  needsAttentionLoading: boolean;
  fetchNeedsAttention: (opts?: { silent?: boolean }) => Promise<void>;
  /** Staff resolve a returned/failed parcel (reship / close / cancel_credit). */
  resolveReturn: (
    jobId: number,
    disposition: 'reship' | 'close' | 'cancel_credit',
    note?: string,
  ) => Promise<boolean>;
```

Add to the initial state (after `inTransitLoading: false,`):

```ts
  needsAttention: [],
  needsAttentionLoading: false,
```

Add the two actions (after `markDelivered`):

```ts
  fetchNeedsAttention: async (opts) => {
    if (!opts?.silent) set({ needsAttentionLoading: true });
    try {
      const { data } = await api.get<{ data: ProductionJob[] }>('/production-jobs/needs-attention');
      set({ needsAttention: data.data });
    } catch {
      // Non-critical: the panel stays as-is; the rest of the board is unaffected.
    } finally {
      set({ needsAttentionLoading: false });
    }
  },

  resolveReturn: async (jobId, disposition, note) => {
    await ensureCsrf();
    try {
      await api.post(`/production-jobs/${jobId}/resolve-return`, {
        disposition,
        ...(note ? { note } : {}),
      });
      // The parcel leaves the needs-attention list; a reship re-enters the make
      // queue, so reconcile both against server truth.
      await get().fetchNeedsAttention({ silent: true });
      await get().fetchQueue({ silent: true });
      return true;
    } catch (err) {
      set({ error: apiError(err) });
      return false;
    }
  },
```

- [ ] **Step 5: Run tests to verify they pass**

Run (from `frontend/`): `npx vitest run src/stores/queueStore.test.ts`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add frontend/src/types.ts frontend/src/stores/queueStore.ts frontend/src/stores/queueStore.test.ts
git commit -m "feat(queue): resolveReturn + needs-attention store state"
```

---

### Task 4: Frontend — NeedsAttentionPanel component

**Files:**
- Create: `frontend/src/components/production/NeedsAttentionPanel.tsx`
- Test: `frontend/src/components/production/NeedsAttentionPanel.test.tsx`

- [ ] **Step 1: Write the failing test**

Mirror `AwaitingDeliveryPanel.test.tsx` for the store-mock harness.

```tsx
import { describe, expect, it, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import NeedsAttentionPanel from './NeedsAttentionPanel';
import { useQueueStore } from '../../stores/queueStore';

describe('NeedsAttentionPanel', () => {
  beforeEach(() => {
    useQueueStore.setState({
      needsAttention: [
        {
          id: 9,
          quote_id: 3,
          quote_reference: 'GL-ABC1234567',
          state: 'SHIPPED',
          track: 'UV',
          ready_at: null,
          print_method: null,
          qty: 1,
          consignment_ref: 'NVSG123',
          last_courier_status: 'Delivery unsuccessful — returned',
        } as never,
      ],
      fetchNeedsAttention: vi.fn().mockResolvedValue(undefined),
      resolveReturn: vi.fn().mockResolvedValue(true),
    });
  });

  it('renders returned parcels and reship calls resolveReturn', async () => {
    render(<NeedsAttentionPanel />);
    expect(screen.getByText(/GL-ABC1234567/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /reship/i }));
    await waitFor(() =>
      expect(useQueueStore.getState().resolveReturn).toHaveBeenCalledWith(9, 'reship', undefined),
    );
  });

  it('cancel & credit opens a confirm before calling resolveReturn', async () => {
    render(<NeedsAttentionPanel />);
    fireEvent.click(screen.getByRole('button', { name: /cancel & credit/i }));
    // Confirm dialog appears; resolveReturn not yet called.
    expect(useQueueStore.getState().resolveReturn).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: /confirm/i }));
    await waitFor(() =>
      expect(useQueueStore.getState().resolveReturn).toHaveBeenCalledWith(9, 'cancel_credit', undefined),
    );
  });

  it('renders nothing when empty', () => {
    useQueueStore.setState({ needsAttention: [] });
    const { container } = render(<NeedsAttentionPanel />);
    expect(container).toBeEmptyDOMElement();
  });
});
```

- [ ] **Step 2: Run to verify it fails**

Run (from `frontend/`): `npx vitest run src/components/production/NeedsAttentionPanel.test.tsx`
Expected: FAIL — module not found.

- [ ] **Step 3: Implement the component**

Create `frontend/src/components/production/NeedsAttentionPanel.tsx`:

```tsx
import { useEffect, useState } from 'react';
import { useQueueStore } from '../../stores/queueStore';
import { Badge, Button, Card, Modal, Textarea, useToast } from '../../ui';
import type { ProductionJob } from '../../types';

type Disposition = 'reship' | 'close' | 'cancel_credit';

/**
 * Returned/failed parcels (F10). Each shows the courier status and three
 * resolutions calling the existing POST /production-jobs/{job}/resolve-return:
 *   - Reship: re-queue for a fresh consignment (job → IN_PRODUCTION).
 *   - Close (write off): accept the loss, close the job.
 *   - Cancel & credit: void this parcel's share + credit what was collected
 *     (money-moving, so gated behind a confirm dialog).
 * Renders nothing when there are no returned parcels.
 */
export default function NeedsAttentionPanel() {
  const needsAttention = useQueueStore((s) => s.needsAttention);
  const fetchNeedsAttention = useQueueStore((s) => s.fetchNeedsAttention);
  const resolveReturn = useQueueStore((s) => s.resolveReturn);
  const { toast } = useToast();

  const [confirm, setConfirm] = useState<{ job: ProductionJob; disposition: Disposition } | null>(null);
  const [note, setNote] = useState('');
  const [busyId, setBusyId] = useState<number | null>(null);

  useEffect(() => {
    void fetchNeedsAttention();
  }, [fetchNeedsAttention]);

  if (needsAttention.length === 0) return null;

  const run = async (job: ProductionJob, disposition: Disposition, withNote?: string) => {
    if (busyId !== null) return;
    setBusyId(job.id);
    const ok = await resolveReturn(job.id, disposition, withNote || undefined);
    setBusyId(null);
    if (ok) {
      toast({ title: `Parcel ${disposition === 'reship' ? 'reshipped' : disposition === 'close' ? 'closed' : 'cancelled & credited'} — ${job.quote_reference ?? 'order'}`, tone: 'success' });
      setConfirm(null);
      setNote('');
    } else {
      toast({ title: 'Could not resolve parcel', description: 'Please try again.', tone: 'danger' });
    }
  };

  return (
    <Card padding="md" className="flex flex-col gap-3 border-l-4 border-l-danger">
      <div className="flex flex-wrap items-center gap-2">
        <h2 className="font-display text-xl text-fg">Needs attention</h2>
        <Badge tone="danger" size="sm">{needsAttention.length}</Badge>
      </div>
      <p className="text-sm text-fg-muted">
        Parcels the courier returned or couldn’t deliver. Reship for a fresh consignment, close to
        write off, or cancel &amp; credit the buyer.
      </p>

      <ul className="flex list-none flex-col divide-y divide-border p-0">
        {needsAttention.map((j) => (
          <li key={j.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
            <div className="min-w-0">
              <p className="font-medium text-fg">{j.quote_reference ?? `Order #${j.quote_id}`}</p>
              <p className="mt-0.5 text-xs text-fg-subtle">
                {j.carrier_label ?? j.carrier ?? 'Courier'}
                {j.consignment_ref ? ` · ${j.consignment_ref}` : ''}
                {j.last_courier_status ? ` · ${j.last_courier_status}` : ''}
              </p>
            </div>
            <div className="flex flex-wrap gap-2">
              <Button variant="secondary" size="sm" loading={busyId === j.id} onClick={() => void run(j, 'reship')}>
                Reship
              </Button>
              <Button variant="ghost" size="sm" disabled={busyId !== null} onClick={() => void run(j, 'close')}>
                Close (write off)
              </Button>
              <Button variant="ghost" size="sm" disabled={busyId !== null} onClick={() => { setConfirm({ job: j, disposition: 'cancel_credit' }); setNote(''); }}>
                Cancel &amp; credit
              </Button>
            </div>
          </li>
        ))}
      </ul>

      <Modal
        open={confirm !== null}
        onClose={() => (busyId !== null ? undefined : setConfirm(null))}
        title={`Cancel & credit — ${confirm?.job.quote_reference ?? 'order'}?`}
        footer={
          <>
            <Button variant="ghost" disabled={busyId !== null} onClick={() => setConfirm(null)}>
              Cancel
            </Button>
            <Button
              variant="primary"
              loading={busyId !== null}
              onClick={() => confirm && void run(confirm.job, 'cancel_credit', note.trim())}
            >
              Confirm
            </Button>
          </>
        }
      >
        <div className="flex flex-col gap-3">
          <p className="text-sm text-fg-muted">
            Voids this parcel’s share and credits what was collected. On a multi-parcel order only this
            parcel is affected; the order stays live. This can’t be undone.
          </p>
          <Textarea label="Note (optional)" value={note} onChange={(e) => setNote(e.target.value)} rows={2} />
        </div>
      </Modal>
    </Card>
  );
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run (from `frontend/`): `npx vitest run src/components/production/NeedsAttentionPanel.test.tsx`
Expected: PASS (3 tests). If the toast/ui import path differs, match `AwaitingDeliveryPanel.tsx`'s imports exactly.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/components/production/NeedsAttentionPanel.tsx frontend/src/components/production/NeedsAttentionPanel.test.tsx
git commit -m "feat(production): returned-parcel resolution panel (F10)"
```

---

### Task 5: Frontend — 4-tab production page restructure

Reorganize `ProductionQueuePage.tsx` into 4 tabs. `jobs` (from `/production-queue`) holds READY + IN_PRODUCTION. Filter per tab; move courier actions from the make card to Ship desk.

**Files:**
- Modify: `frontend/src/pages/ProductionQueuePage.tsx`
- Test: `frontend/src/pages/ProductionQueuePage.test.tsx` (create if absent; else extend)

**Tab → data mapping:**
| Tab | Source | Job filter |
|-----|--------|-----------|
| Make queue | `jobs` | all (`READY` + `IN_PRODUCTION`) |
| Ship desk | `jobs` | `state === 'IN_PRODUCTION'` |
| In-transit | `<AwaitingDeliveryPanel />` | (panel self-fetches) |
| Needs-attention | `<NeedsAttentionPanel />` | (panel self-fetches) |

- [ ] **Step 1: Write the failing test**

Create/extend `frontend/src/pages/ProductionQueuePage.test.tsx`. Seed the store with one READY and one IN_PRODUCTION job; mock the panels' fetches to no-op.

```tsx
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import ProductionQueuePage from './ProductionQueuePage';
import { useQueueStore } from '../stores/queueStore';

beforeEach(() => {
  useQueueStore.setState({
    jobs: [
      { id: 1, quote_id: 1, quote_reference: 'GL-AAA0000001', state: 'READY', track: 'UV', ready_at: '2026-07-30T00:00:00Z', print_method: null, qty: 1 } as never,
      { id: 2, quote_id: 2, quote_reference: 'GL-BBB0000002', state: 'IN_PRODUCTION', track: 'UV', ready_at: '2026-07-30T01:00:00Z', print_method: null, qty: 1 } as never,
    ],
    loading: false,
    error: null,
    inTransit: [],
    needsAttention: [],
    fetchQueue: vi.fn().mockResolvedValue(undefined),
    fetchInTransit: vi.fn().mockResolvedValue(undefined),
    fetchNeedsAttention: vi.fn().mockResolvedValue(undefined),
    subscribe: vi.fn(),
    unsubscribe: vi.fn(),
  });
});

describe('ProductionQueuePage tabs', () => {
  it('Ship desk tab shows only IN_PRODUCTION jobs and offers the courier action', () => {
    render(<ProductionQueuePage />);
    fireEvent.click(screen.getByRole('tab', { name: /ship desk/i }));
    expect(screen.getByText(/GL-BBB0000002/)).toBeInTheDocument();
    expect(screen.queryByText(/GL-AAA0000001/)).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: /create ninjavan shipment/i })).toBeInTheDocument();
  });

  it('Make queue tab does not offer courier actions', () => {
    render(<ProductionQueuePage />);
    // Make queue is default; the IN_PRODUCTION job shows here but without a ship button.
    expect(screen.getByText(/GL-BBB0000002/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /create ninjavan shipment/i })).not.toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run to verify it fails**

Run (from `frontend/`): `npx vitest run src/pages/ProductionQueuePage.test.tsx`
Expected: FAIL — no tab roles yet; courier action present on make card.

- [ ] **Step 3: Add tab state + tab bar**

In `ProductionQueuePage.tsx`, add a tab state and a tab bar. Introduce:

```tsx
type ProdTab = 'make' | 'ship' | 'transit' | 'attention';
```

and `const [tab, setTab] = useState<ProdTab>('make');`. Also read the two counts for badges:

```tsx
const inTransitCount = useQueueStore((s) => s.inTransit.length);
const needsAttentionCount = useQueueStore((s) => s.needsAttention.length);
```

Render a tab bar (ARIA `role="tablist"` / `role="tab"`), just under the header:

```tsx
<div role="tablist" className="flex flex-wrap gap-1 border-b border-border">
  {([
    ['make', 'Make queue'],
    ['ship', 'Ship desk'],
    ['transit', 'In-transit'],
    ['attention', 'Needs attention'],
  ] as [ProdTab, string][]).map(([key, label]) => (
    <button
      key={key}
      role="tab"
      aria-selected={tab === key}
      onClick={() => setTab(key)}
      className={`-mb-px border-b-2 px-4 py-2 text-sm font-medium ${
        tab === key ? 'border-brand text-fg' : 'border-transparent text-fg-muted hover:text-fg'
      }`}
    >
      {label}
      {key === 'attention' && needsAttentionCount > 0 && (
        <span className="ml-1.5 rounded-full bg-danger px-1.5 text-2xs text-white">{needsAttentionCount}</span>
      )}
      {key === 'transit' && inTransitCount > 0 && (
        <span className="ml-1.5 rounded-full bg-brand px-1.5 text-2xs text-white">{inTransitCount}</span>
      )}
    </button>
  ))}
</div>
```

Keep the `useEffect` that calls `fetchQueue()` + `subscribe()`. Add `void fetchNeedsAttention()` to it (so the badge count is live); `AwaitingDeliveryPanel` and `NeedsAttentionPanel` also self-fetch.

- [ ] **Step 4: Gate content by tab**

Wrap the existing board (scan input + skeleton/empty/error + bulk actions + `motion.ul` of cards) so it renders on `tab === 'make'` or `tab === 'ship'`, with the job list filtered:

```tsx
const boardJobs = tab === 'ship' ? jobs.filter((j) => j.state === 'IN_PRODUCTION') : jobs;
```

Use `boardJobs` in place of `jobs` for the `.map`, empty check, and skeleton gate within the board. Render the scan input only on `tab === 'make'`.

Move the two panels out of the board and gate them:

```tsx
{tab === 'transit' && <AwaitingDeliveryPanel />}
{tab === 'attention' && <NeedsAttentionPanel />}
```

Import `NeedsAttentionPanel` alongside `AwaitingDeliveryPanel`.

- [ ] **Step 5: Split the card actions by surface**

Pass the active tab into the card render. In the card:
- On `tab === 'make'`: keep "Start production" for `READY` (i.e. render the `next` button ONLY when `next.to === 'IN_PRODUCTION'`). Keep print-file downloads, customization, print label. **Remove** the "Create NinjaVan shipment", the manual consignment/carrier form, and the `next.to === 'SHIPPED'` button from the make surface.
- On `tab === 'ship'`: render the courier actions for `IN_PRODUCTION` — "Create NinjaVan shipment" (opens `confirmShipJobId` modal), the manual consignment/carrier "Mark shipped" form, "Print label", and the "Delivery address" panel. Do **not** render "Start production".

Concretely, guard the existing action blocks:
- The `{j.state === 'IN_PRODUCTION' && (<Button ...>Create NinjaVan shipment</Button>)}` block → `{tab === 'ship' && j.state === 'IN_PRODUCTION' && ( ... )}`.
- The `next && next.to === 'SHIPPED' && shippingId === j.id ? (...) : (next && <Button>{next.label}</Button>)` block → split: on `make`, only show the button when `next?.to === 'IN_PRODUCTION'`; on `ship`, only show the SHIPPED confirm flow.
- "Delivery address" + "Print label" buttons → show on `ship` (Print label may stay on both; keep on `ship`).

> `NEXT_STATE[SHIPPED] = Mark delivered` is now unreachable from the board (SHIPPED jobs live in In-transit/Needs-attention, not `jobs`). Leave the map entry; it's harmless.

- [ ] **Step 6: Run tests to verify they pass**

Run (from `frontend/`): `npx vitest run src/pages/ProductionQueuePage.test.tsx`
Expected: PASS. Also run the full frontend suite for regressions: `npx vitest run` — fix any snapshot/assertion drift from the restructure.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/pages/ProductionQueuePage.tsx frontend/src/pages/ProductionQueuePage.test.tsx
git commit -m "feat(production): 4-tab surface split (make / ship / in-transit / needs-attention)"
```

---

### Task 6: Frontend — scrollable modal + sticky footer (F12) + SG address lock (F13)

**Files:**
- Modify: `frontend/src/ui/Modal.tsx`
- Modify: `frontend/src/pages/ProductionQueuePage.tsx` (`DeliveryAddressPanel`)
- Test: `frontend/src/ui/Modal.test.tsx` (create if absent) + assertion in `ProductionQueuePage.test.tsx`

- [ ] **Step 1: Modal — scroll body + sticky footer**

In `frontend/src/ui/Modal.tsx`, make the panel a flex column capped to the viewport, the body scroll, and the footer stay pinned. Change the panel `className` to add `flex max-h-[90vh] flex-col` (append to the existing `cn(...)` string), and update the three inner sections:

```tsx
<div className="flex shrink-0 items-start justify-between gap-4 p-6 pb-2">
  {/* header (unchanged inner content) */}
</div>
{children && <div className="min-h-0 flex-1 overflow-y-auto px-6 py-3 text-base text-fg">{children}</div>}
{footer && <div className="flex shrink-0 justify-end gap-2 border-t border-border p-6 pt-3">{footer}</div>}
```

- [ ] **Step 2: DeliveryAddressPanel — SG prefill + disable (F13)**

In `ProductionQueuePage.tsx`'s `DeliveryAddressPanel`, after the address loads, force the SG fields and lock them. In the `.then(({ address: addr, saved }) => { setForm({...}) })` block, set:

```ts
          city: 'Singapore',
          state: 'Singapore',
          country: 'SG',
```

(instead of reading `addr.city`/`addr.state`/`addr.country`). Then render those three inputs disabled:

```tsx
      <div className="grid grid-cols-2 gap-2">
        <Input label="City" value={form.city} disabled onChange={update('city')} />
        <Input label="State" value={form.state} disabled onChange={update('state')} />
      </div>
      <div className="grid grid-cols-2 gap-2">
        <Input label="Postal code" required value={form.postal_code} onChange={update('postal_code')} />
        <Input label="Country" value={form.country} maxLength={2} disabled onChange={update('country')} />
      </div>
```

The existing `onSave` sends optional fields when non-empty, so `Singapore/Singapore/SG` are persisted.

- [ ] **Step 3: Write/adjust a test**

Add to `ProductionQueuePage.test.tsx` (Ship desk tab, open the delivery address panel) an assertion that City is `Singapore` and disabled:

```tsx
it('locks SG city/state/country in the delivery address form', async () => {
  // ... render, go to Ship desk, expand Delivery address for the IN_PRODUCTION job ...
  const city = await screen.findByLabelText(/city/i);
  expect(city).toHaveValue('Singapore');
  expect(city).toBeDisabled();
});
```

(If wiring the address fetch mock is heavy, assert F13 via a focused `DeliveryAddressPanel` render instead, mocking `fetchShippingAddress` to resolve an empty saved address.)

- [ ] **Step 4: Run tests**

Run (from `frontend/`): `npx vitest run src/ui/Modal.test.tsx src/pages/ProductionQueuePage.test.tsx`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/ui/Modal.tsx frontend/src/pages/ProductionQueuePage.tsx frontend/src/pages/ProductionQueuePage.test.tsx frontend/src/ui/Modal.test.tsx
git commit -m "fix(ui): scrollable modal + sticky footer (F12); lock SG address fields (F13)"
```

---

### Task 7: Frontend — PO field required (F8)

**Files:**
- Modify: `frontend/src/pages/QuoteDetailPage.tsx:731`
- Test: `frontend/src/pages/QuoteDetailPage.test.tsx`

- [ ] **Step 1: Mark the PO Input required**

In `QuoteDetailPage.tsx`, the "PO reference" `Input` (~line 731): add `required` and a clearer hint.

```tsx
                <Input
                  label="PO reference"
                  required
                  placeholder="PO number"
                  hint="Required to raise the invoice and commit the order to production."
                  value={poRef}
                  error={poRefError}
                  onChange={(e) => {
                    setPoRef(e.target.value);
                    setPoRefError(undefined);
                  }}
                />
```

(`Input` renders an asterisk when `required` is set — see `frontend/src/ui/Input.tsx:33`.)

- [ ] **Step 2: Add/adjust a test**

In `QuoteDetailPage.test.tsx`, for a `PROOF_APPROVED` quote, assert the PO field is marked required:

```tsx
it('marks the PO reference field as required at the commit step', () => {
  // render a PROOF_APPROVED quote (mirror the existing commit-step test setup)
  const po = screen.getByLabelText(/PO reference/i);
  expect(po).toBeRequired();
});
```

Match the existing file's render/setup helpers for a `PROOF_APPROVED` quote.

- [ ] **Step 3: Run tests**

Run (from `frontend/`): `npx vitest run src/pages/QuoteDetailPage.test.tsx`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/pages/QuoteDetailPage.tsx frontend/src/pages/QuoteDetailPage.test.tsx
git commit -m "fix(quote): mark commit PO reference field required (F8)"
```

---

### Task 8: Full-suite regression + live verify

- [ ] **Step 1: Backend suite**

Run: `php artisan test`
Expected: all green (new NeedsAttentionSurface + webhook guard pass; no regressions).

- [ ] **Step 2: Frontend suite + types**

Run (from `frontend/`): `npx vitest run` then `npx tsc --noEmit`
Expected: all tests pass; tsc clean.

- [ ] **Step 3: grep for leftover courier actions on the make surface**

Confirm the make card no longer renders shipment booking. Manually review the Ship-desk vs Make gating.

- [ ] **Step 4: Live verify**

Start `preview_start api` + `frontend` (+ `reverb` if broadcasts are exercised); open `http://localhost:5173` (NOT 127.0.0.1). As staff:
- Production page shows 4 tabs; Make has no courier buttons; Ship desk has "Create NinjaVan shipment" for IN_PRODUCTION jobs.
- To simulate a returned parcel: POST a signed NinjaVan webhook (see `docs/flow-audit-2026-07-28/WALKTHROUGH-PLAN.md` §1e; secret `NINJAVAN_WEBHOOK_SECRET`, header `X-Ninja-Hmac` = HMAC-SHA256 of the raw body) with status `Returned to Sender` for a SHIPPED job's `consignment_ref`. Confirm the parcel appears under **Needs attention** (not In-transit) with Reship / Close / Cancel & credit; each drives the right outcome.
- Book-shipment dialog: Book button reachable (scrollable body / sticky footer) at a laptop viewport.
- Delivery address: City/State = Singapore, Country = SG, all disabled.
- Order detail (PROOF_APPROVED): PO field shows the required asterisk.

Don't delete seeded/walkthrough data.

---

## Self-Review

- **Spec coverage:** D1 (T5 4 tabs), D2/F10 (T1 backend + T3 store + T4 panel + T5 wiring), D3/F12 (T6 modal), D3/F13 (T6 address), D4/F8 (T7 PO), courier-independence guard (T2). ✅
- **Green at each boundary:** T1 backend self-contained; T2 test-only; T3 store isolated; T4 component isolated; T5 wires panels + tabs (full frontend suite run); T6/T7 UX; T8 regression. ✅
- **No schema change** (Stage 1). Stage 2 deferred. ✅
- **Type consistency:** `resolveReturn(jobId, 'reship'|'close'|'cancel_credit', note?)` matches `ResolveReturnRequest` `in:close,reship,cancel_credit`. `needs_attention` bool added to both resource and TS type. ✅
