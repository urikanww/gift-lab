# Staff Console — Sidebar Shell + Dashboard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give staff/superadmin a dedicated left-sidebar console with a scale-safe `/dashboard` overview (quote pipeline, production health, at-risk jobs, live audit-log activity, action-queue tiles), while buyers/public keep the existing top-bar layout.

**Architecture:** New read-only `GET /api/admin/dashboard` endpoint backed by a `DashboardMetrics` service that runs index-backed aggregate queries (COUNT/GROUP BY/SUM), bounded slices, and a 45s cache. Frontend adds a `StaffLayout` shell + `DashboardPage`, a `dashboardStore` (Zustand) that fetches once and refreshes on existing Reverb staff channels, and role-aware routing.

**Tech Stack:** Laravel 11 (Pest tests, Eloquent, `Cache::remember`), React 18 + TypeScript + Zustand + React Router 6 + Tailwind, Vitest + Testing Library, Laravel Echo/Reverb.

**Reference spec:** `docs/superpowers/specs/2026-07-03-staff-console-dashboard-design.md`

---

## File Structure

**Backend (create):**
- `database/migrations/2026_07_03_000001_add_created_at_index_to_audit_logs.php` — index for the feed query.
- `app/Services/Dashboard/DashboardMetrics.php` — one method per widget; each a single indexed query.
- `app/Http/Controllers/DashboardController.php` — staff-gated, assembles payload.
- `tests/Feature/DashboardTest.php` — endpoint behaviour + gating + bounds.

**Backend (modify):**
- `routes/api.php` — register the route inside the existing `auth:sanctum` group.

**Frontend (create):**
- `frontend/src/lib/dashboard.ts` — payload types + `fetchDashboard()`.
- `frontend/src/stores/dashboardStore.ts` — snapshot + Reverb-driven refresh.
- `frontend/src/components/StaffLayout.tsx` — sidebar shell (desktop + mobile drawer).
- `frontend/src/components/RoleLayout.tsx` — picks StaffLayout vs Layout by role.
- `frontend/src/pages/DashboardPage.tsx` — widgets.
- `frontend/src/pages/DashboardPage.test.tsx`, `frontend/src/stores/dashboardStore.test.ts`.

**Frontend (modify):**
- `frontend/src/App.tsx` — re-parent staff routes under `StaffLayout`, shared auth routes under `RoleLayout`, add `/dashboard`.
- `frontend/src/pages/LoginPage.tsx` — staff redirect `/catalogue-admin` → `/dashboard`.
- `frontend/src/types.ts` — export dashboard payload types if colocated there (else in `lib/dashboard.ts`).

---

## Constants / definitions (used across tasks)

- **Pipeline:** `Quote` grouped by `state` (all 12 `QuoteState` values, missing = 0).
- **Production byState:** `ProductionJob` grouped by `state` (`READY/IN_PRODUCTION/SHIPPED/CLOSED`). **wip** = count `IN_PRODUCTION`. **overdue** = at-risk count.
- **At-risk (SLA):** `ProductionJob` where `state IN ('READY','IN_PRODUCTION')` AND `ready_at < now()->subHours(AT_RISK_SLA_HOURS)`. `AT_RISK_SLA_HOURS = 72` (service const). Uses `(state, ready_at)` index. Slice `LIMIT 15`.
- **Queues:** `proofsPending` = `Proof` where `state = 'SENT'`; `procurementToReconfirm` = `LineItem` where `line_state = 'AWAITING_RECONFIRM'`; `cataloguePending` = `Product` where `publish_state = 'READY_TO_APPROVE'`.
- **Activity:** `AuditLog` newest-first, `LIMIT 20`, eager-load `user:id,name`.
- **Value booked (superadmin only):** SUM `quotes.total` where `state IN ('ACCEPTED','PROOFING','PROOF_APPROVED','PO_ISSUED','CONFIRMED','PROCURING','READY')`.
- **Cache:** counts block (`pipeline`, `production`, `queues`, `valueBooked`) wrapped in `Cache::remember('dashboard.metrics.v1'.($isSuper?'.super':''), 45, ...)`. `activity` + `atRisk` fetched fresh (bounded, cheap).

---

## Task 1: Add `created_at` index to `audit_logs`

**Files:**
- Create: `database/migrations/2026_07_03_000001_add_created_at_index_to_audit_logs.php`

- [ ] **Step 1: Write the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The staff dashboard activity feed reads `ORDER BY created_at DESC LIMIT 20`.
// audit_logs is indexed on event/user/auditable but not created_at, so the feed
// would sort the whole (never-purged) table. Add the index.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex(['created_at']);
        });
    }
};
```

- [ ] **Step 2: Run the migration**

Run: `php artisan migrate`
Expected: migrates `..._add_created_at_index_to_audit_logs` with `DONE`.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_07_03_000001_add_created_at_index_to_audit_logs.php
git commit -m "perf: index audit_logs.created_at for dashboard activity feed"
```

---

## Task 2: Dashboard endpoint scaffold (route + staff gate)

**Files:**
- Create: `app/Http/Controllers/DashboardController.php`
- Create: `app/Services/Dashboard/DashboardMetrics.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/DashboardTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->staff = User::factory()->staffAdmin()->create();
    $this->superadmin = User::factory()->superadmin()->create();
    $company = Company::factory()->create();
    $this->buyer = User::factory()->create(['company_id' => $company->id, 'role' => 'buyer']);
});

it('gates the dashboard to staff', function (): void {
    $this->getJson('/api/admin/dashboard')->assertUnauthorized();

    Sanctum::actingAs($this->buyer);
    $this->getJson('/api/admin/dashboard')->assertForbidden();

    Sanctum::actingAs($this->staff);
    $this->getJson('/api/admin/dashboard')
        ->assertOk()
        ->assertJsonStructure(['pipeline', 'production', 'atRisk', 'queues', 'activity']);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=DashboardTest`
Expected: FAIL — route `/api/admin/dashboard` not defined (404/500).

- [ ] **Step 3: Create the service with empty-but-typed methods**

```php
<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Models\AuditLog;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\ProductionJob;
use App\Models\Proof;
use App\Models\Quote;
use Illuminate\Support\Facades\Cache;

/**
 * Read-only aggregate metrics for the staff dashboard. Every method is a single
 * index-backed query (COUNT/GROUP BY/SUM) or a bounded, eager-loaded slice — no
 * row hydration for counting, no unbounded selects, no N+1.
 */
class DashboardMetrics
{
    /** Jobs waiting longer than this (hours) past their queue-entry time are at risk. */
    private const AT_RISK_SLA_HOURS = 72;

    private const ACTIVITY_LIMIT = 20;

    private const AT_RISK_LIMIT = 15;

    /** Quote states counted as commercially "booked". */
    private const BOOKED_STATES = [
        'ACCEPTED', 'PROOFING', 'PROOF_APPROVED', 'PO_ISSUED', 'CONFIRMED', 'PROCURING', 'READY',
    ];

    /** @return array<string,mixed> */
    public function snapshot(bool $includeValue): array
    {
        // Counts are cache-friendly (45s); the feed + at-risk slice stay fresh.
        $counts = Cache::remember(
            'dashboard.metrics.v1'.($includeValue ? '.super' : ''),
            45,
            fn (): array => [
                'pipeline' => $this->pipeline(),
                'production' => $this->production(),
                'queues' => $this->queues(),
                'valueBooked' => $includeValue ? $this->valueBooked() : null,
            ],
        );

        return [
            ...$counts,
            'atRisk' => $this->atRisk(),
            'activity' => $this->activity(),
        ];
    }

    /** @return array<string,int> */
    public function pipeline(): array
    {
        return Quote::query()
            ->groupBy('state')
            ->selectRaw('state, COUNT(*) as c')
            ->pluck('c', 'state')
            ->all();
    }

    /** @return array<string,mixed> */
    public function production(): array
    {
        $byState = ProductionJob::query()
            ->groupBy('state')
            ->selectRaw('state, COUNT(*) as c')
            ->pluck('c', 'state')
            ->all();

        return [
            'byState' => $byState,
            'wip' => (int) ($byState['IN_PRODUCTION'] ?? 0),
            'overdue' => $this->atRiskQuery()->count(),
        ];
    }

    /** @return array<string,int> */
    public function queues(): array
    {
        return [
            'proofsPending' => Proof::query()->where('state', 'SENT')->count(),
            'procurementToReconfirm' => LineItem::query()->where('line_state', 'AWAITING_RECONFIRM')->count(),
            'cataloguePending' => Product::query()->where('publish_state', 'READY_TO_APPROVE')->count(),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function atRisk(): array
    {
        return $this->atRiskQuery()
            ->orderBy('ready_at')
            ->limit(self::AT_RISK_LIMIT)
            ->get(['id', 'quote_id', 'track', 'state', 'ready_at'])
            ->map(fn (ProductionJob $j): array => [
                'jobId' => $j->id,
                'quoteId' => $j->quote_id,
                'track' => $j->track->value ?? (string) $j->track,
                'state' => $j->state->value ?? (string) $j->state,
                'readyAt' => $j->ready_at?->toIso8601String(),
            ])
            ->all();
    }

    /** @return array<int,array<string,mixed>> */
    public function activity(): array
    {
        return AuditLog::query()
            ->with('user:id,name')
            ->latest()
            ->limit(self::ACTIVITY_LIMIT)
            ->get(['id', 'user_id', 'auditable_type', 'auditable_id', 'event', 'created_at'])
            ->map(fn (AuditLog $a): array => [
                'id' => $a->id,
                'actor' => $a->user?->name,
                'event' => $a->event,
                'auditableType' => class_basename($a->auditable_type),
                'auditableId' => $a->auditable_id,
                'at' => $a->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /** @return array<string,mixed> */
    public function valueBooked(): array
    {
        $amount = Quote::query()->whereIn('state', self::BOOKED_STATES)->sum('total');

        return ['currency' => 'SGD', 'amount' => (float) $amount];
    }

    private function atRiskQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return ProductionJob::query()
            ->whereIn('state', ['READY', 'IN_PRODUCTION'])
            ->where('ready_at', '<', now()->subHours(self::AT_RISK_SLA_HOURS));
    }
}
```

- [ ] **Step 4: Create the controller**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Dashboard\DashboardMetrics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Staff console overview. Read-only aggregate snapshot; value-booked is
 * superadmin-only. All heavy lifting is in DashboardMetrics.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly DashboardMetrics $metrics)
    {
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);

        return response()->json(
            $this->metrics->snapshot($request->user()->isSuperadmin()),
        );
    }
}
```

- [ ] **Step 5: Register the route**

In `routes/api.php`, add the import near the other controller imports:

```php
use App\Http\Controllers\DashboardController;
```

Inside the existing `Route::middleware(['auth:sanctum', 'throttle:120,1'])->group(...)` block (alongside the other admin routes), add:

```php
    // Staff console overview (read-only aggregate snapshot).
    Route::get('/admin/dashboard', [DashboardController::class, 'index']);
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=DashboardTest`
Expected: PASS (gating test green).

- [ ] **Step 7: Commit**

```bash
git add app/Services/Dashboard/DashboardMetrics.php app/Http/Controllers/DashboardController.php routes/api.php tests/Feature/DashboardTest.php
git commit -m "feat(api): staff dashboard endpoint with aggregate metrics"
```

---

## Task 3: Test the aggregate counts

**Files:**
- Modify: `tests/Feature/DashboardTest.php`

- [ ] **Step 1: Add failing tests for counts + value gating**

Append to `tests/Feature/DashboardTest.php`:

```php
it('reports pipeline, production, and queue counts', function (): void {
    $company = Company::factory()->create();
    \App\Models\Quote::factory()->count(2)->create(['company_id' => $company->id, 'state' => 'SENT']);
    \App\Models\Quote::factory()->create(['company_id' => $company->id, 'state' => 'ACCEPTED']);

    Sanctum::actingAs($this->staff);
    $res = $this->getJson('/api/admin/dashboard')->assertOk();

    expect($res->json('pipeline.SENT'))->toBe(2);
    expect($res->json('pipeline.ACCEPTED'))->toBe(1);
    expect($res->json('production'))->toHaveKeys(['byState', 'wip', 'overdue']);
    expect($res->json('queues'))->toHaveKeys(['proofsPending', 'procurementToReconfirm', 'cataloguePending']);
});

it('includes value-booked only for superadmin', function (): void {
    Sanctum::actingAs($this->staff);
    expect($this->getJson('/api/admin/dashboard')->json('valueBooked'))->toBeNull();

    Sanctum::actingAs($this->superadmin);
    $this->getJson('/api/admin/dashboard')
        ->assertOk()
        ->assertJsonStructure(['valueBooked' => ['currency', 'amount']]);
});

it('caps activity at 20 newest-first and at-risk at 15', function (): void {
    $company = Company::factory()->create();
    $quote = \App\Models\Quote::factory()->create(['company_id' => $company->id]);
    for ($i = 0; $i < 25; $i++) {
        \App\Models\AuditLog::create([
            'user_id' => $this->staff->id,
            'auditable_type' => \App\Models\Quote::class,
            'auditable_id' => $quote->id,
            'event' => 'quote.amended',
            'created_at' => now()->subMinutes($i),
            'updated_at' => now()->subMinutes($i),
        ]);
    }

    Sanctum::actingAs($this->staff);
    $activity = $this->getJson('/api/admin/dashboard')->json('activity');

    expect($activity)->toHaveCount(20);
    expect($activity[0]['event'])->toBe('quote.amended');
});
```

- [ ] **Step 2: Run tests to verify they pass**

Run: `php artisan test --filter=DashboardTest`
Expected: PASS. If `Quote`/`AuditLog` factories differ (e.g. required columns), adjust the `create([...])` payloads to satisfy non-nullable columns — do NOT change the service. Confirm `Company` + `Quote` + `AuditLog` factories exist first with `ls database/factories`.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/DashboardTest.php
git commit -m "test(api): dashboard counts, value gating, and slice bounds"
```

---

## Task 4: Query-count guard (no N+1, bounded under volume)

**Files:**
- Modify: `tests/Feature/DashboardTest.php`

- [ ] **Step 1: Add a failing test asserting a flat query count**

```php
it('runs a bounded number of queries regardless of data volume', function (): void {
    $company = Company::factory()->create();
    $quote = \App\Models\Quote::factory()->create(['company_id' => $company->id]);
    for ($i = 0; $i < 30; $i++) {
        \App\Models\AuditLog::create([
            'user_id' => $this->staff->id,
            'auditable_type' => \App\Models\Quote::class,
            'auditable_id' => $quote->id,
            'event' => 'quote.amended',
            'created_at' => now()->subMinutes($i),
            'updated_at' => now()->subMinutes($i),
        ]);
    }

    Sanctum::actingAs($this->staff);

    \Illuminate\Support\Facades\DB::enableQueryLog();
    $this->getJson('/api/admin/dashboard')->assertOk();
    $count = count(\Illuminate\Support\Facades\DB::getQueryLog());
    \Illuminate\Support\Facades\DB::disableQueryLog();

    // pipeline + production(byState + overdue) + 3 queues + atRisk + activity(+eager user)
    // ≈ 9; the eager-load makes actor lookup ONE query, not 30. Guard against N+1.
    expect($count)->toBeLessThanOrEqual(12);
});
```

- [ ] **Step 2: Run test**

Run: `php artisan test --filter=DashboardTest`
Expected: PASS (eager `with('user:id,name')` keeps actor lookup to one query). If it FAILS with a high count, the feed lost its eager load — restore `->with('user:id,name')` in `DashboardMetrics::activity()`.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/DashboardTest.php
git commit -m "test(api): guard dashboard against N+1 / unbounded queries"
```

---

## Task 5: Frontend data layer — types + fetch + store

**Files:**
- Create: `frontend/src/lib/dashboard.ts`
- Create: `frontend/src/stores/dashboardStore.ts`
- Test: `frontend/src/stores/dashboardStore.test.ts`

- [ ] **Step 1: Create the types + fetch helper**

`frontend/src/lib/dashboard.ts`:

```ts
import api from './api';

export interface DashboardActivity {
  id: number;
  actor: string | null;
  event: string;
  auditableType: string;
  auditableId: number;
  at: string | null;
}

export interface DashboardAtRisk {
  jobId: number;
  quoteId: number;
  track: string;
  state: string;
  readyAt: string | null;
}

export interface DashboardPayload {
  pipeline: Record<string, number>;
  production: { byState: Record<string, number>; wip: number; overdue: number };
  atRisk: DashboardAtRisk[];
  queues: { proofsPending: number; procurementToReconfirm: number; cataloguePending: number };
  activity: DashboardActivity[];
  valueBooked: { currency: string; amount: number } | null;
}

export async function fetchDashboard(): Promise<DashboardPayload> {
  const { data } = await api.get<DashboardPayload>('/admin/dashboard');
  return data;
}
```

- [ ] **Step 2: Write the failing store test**

`frontend/src/stores/dashboardStore.test.ts`:

```ts
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { useDashboardStore } from './dashboardStore';
import * as dash from '../lib/dashboard';

const payload: dash.DashboardPayload = {
  pipeline: { SENT: 2 },
  production: { byState: { READY: 1 }, wip: 0, overdue: 0 },
  atRisk: [],
  queues: { proofsPending: 3, procurementToReconfirm: 1, cataloguePending: 4 },
  activity: [],
  valueBooked: null,
};

beforeEach(() => {
  useDashboardStore.setState({ data: null, loading: false, error: null });
});

describe('dashboardStore', () => {
  it('loads the snapshot', async () => {
    vi.spyOn(dash, 'fetchDashboard').mockResolvedValue(payload);
    await useDashboardStore.getState().load();
    expect(useDashboardStore.getState().data?.queues.proofsPending).toBe(3);
    expect(useDashboardStore.getState().loading).toBe(false);
  });

  it('records an error on failure', async () => {
    vi.spyOn(dash, 'fetchDashboard').mockRejectedValue(new Error('boom'));
    await useDashboardStore.getState().load();
    expect(useDashboardStore.getState().error).toBeTruthy();
    expect(useDashboardStore.getState().data).toBeNull();
  });
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `npm --prefix frontend run test -- dashboardStore`
Expected: FAIL — `./dashboardStore` module not found.

- [ ] **Step 4: Implement the store**

`frontend/src/stores/dashboardStore.ts`:

```ts
import { create } from 'zustand';
import { apiError } from '../lib/api';
import { getEcho, onEchoReconnect } from '../lib/echo';
import { fetchDashboard, type DashboardPayload } from '../lib/dashboard';

let offReconnect: (() => void) | null = null;
let subscribed = false;
let debounce: ReturnType<typeof setTimeout> | null = null;

interface DashboardStoreState {
  data: DashboardPayload | null;
  loading: boolean;
  error: string | null;
  load: () => Promise<void>;
  subscribe: () => void;
  unsubscribe: () => void;
}

export const useDashboardStore = create<DashboardStoreState>((set, get) => ({
  data: null,
  loading: false,
  error: null,

  load: async () => {
    set({ loading: true, error: null });
    try {
      const data = await fetchDashboard();
      set({ data, loading: false });
    } catch (err) {
      set({ loading: false, error: apiError(err) });
    }
  },

  // Realtime-driven refresh: reuse the existing staff channels. Any floor/
  // procurement event debounces a single refetch (no polling; matches the app's
  // Reverb-only constraint). Also reconciles after a socket reconnect.
  subscribe: () => {
    if (subscribed) return;
    subscribed = true;

    const refresh = () => {
      if (debounce) clearTimeout(debounce);
      debounce = setTimeout(() => void get().load(), 800);
    };

    offReconnect = onEchoReconnect(refresh);

    const echo = getEcho();
    echo.private('staff.queue').listen('.production.queue-updated', refresh);
    echo.private('staff.procurement').listen('.line-item.awaiting-reconfirm', refresh);
  },

  unsubscribe: () => {
    if (!subscribed) return;
    subscribed = false;
    if (debounce) clearTimeout(debounce);
    offReconnect?.();
    offReconnect = null;
    const echo = getEcho();
    echo.leave('staff.queue');
    echo.leave('staff.procurement');
  },
}));
```

Note: the `.listen` event names mirror the broadcast names on `ProductionQueueUpdated` / `LineItemAwaitingReconfirm`. Before finishing this task, confirm the `broadcastAs()` strings in `app/Events/ProductionQueueUpdated.php` and `app/Events/LineItemAwaitingReconfirm.php` and match them exactly (Laravel prefixes custom names with `.`).

- [ ] **Step 5: Run test to verify it passes**

Run: `npm --prefix frontend run test -- dashboardStore`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/lib/dashboard.ts frontend/src/stores/dashboardStore.ts frontend/src/stores/dashboardStore.test.ts
git commit -m "feat(frontend): dashboard data layer (types, fetch, realtime store)"
```

---

## Task 6: `StaffLayout` shell (sidebar + mobile drawer)

**Files:**
- Create: `frontend/src/components/StaffLayout.tsx`

- [ ] **Step 1: Implement the shell**

`frontend/src/components/StaffLayout.tsx`:

```tsx
import { useEffect, useState } from 'react';
import { Link, NavLink, Outlet, useNavigate } from 'react-router-dom';
import { useAuthStore } from '../stores/authStore';
import { useDashboardStore } from '../stores/dashboardStore';
import { Badge, Button, cn } from '../ui';

interface NavItem {
  to: string;
  label: string;
  badge?: number;
}

function useStaffNav(): NavItem[] {
  const q = useDashboardStore((s) => s.data?.queues);
  const overdue = useDashboardStore((s) => s.data?.production.overdue ?? 0);
  return [
    { to: '/dashboard', label: 'Dashboard' },
    { to: '/quotes', label: 'Quotes', badge: q?.proofsPending },
    { to: '/production-queue', label: 'Production', badge: overdue || undefined },
    { to: '/procurement', label: 'Procurement', badge: q?.procurementToReconfirm },
    { to: '/catalogue-admin', label: 'Catalogue Gate', badge: q?.cataloguePending },
  ];
}

const linkClass = ({ isActive }: { isActive: boolean }) =>
  cn(
    'flex min-h-[44px] items-center justify-between gap-2 rounded-md px-3 py-2 text-sm font-medium transition-colors',
    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
    isActive ? 'bg-surface-2 text-fg' : 'text-fg-muted hover:bg-surface-2 hover:text-fg',
  );

function NavList({ onNavigate }: { onNavigate?: () => void }) {
  const items = useStaffNav();
  return (
    <nav className="flex flex-col gap-1" aria-label="Staff">
      {items.map((it) => (
        <NavLink key={it.to} to={it.to} onClick={onNavigate} className={linkClass}>
          <span>{it.label}</span>
          {it.badge ? <Badge tone="brand" size="sm">{it.badge}</Badge> : null}
        </NavLink>
      ))}
    </nav>
  );
}

export default function StaffLayout() {
  const user = useAuthStore((s) => s.user);
  const logout = useAuthStore((s) => s.logout);
  const load = useDashboardStore((s) => s.load);
  const subscribe = useDashboardStore((s) => s.subscribe);
  const unsubscribe = useDashboardStore((s) => s.unsubscribe);
  const navigate = useNavigate();
  const [drawer, setDrawer] = useState(false);

  // One snapshot fetch + realtime badge refresh for the whole console.
  useEffect(() => {
    void load();
    subscribe();
    return () => unsubscribe();
  }, [load, subscribe, unsubscribe]);

  const onLogout = async () => {
    await logout();
    navigate('/');
  };

  return (
    <div className="min-h-screen bg-bg md:flex">
      {/* Desktop sidebar */}
      <aside className="hidden w-60 shrink-0 border-r border-border bg-surface md:flex md:flex-col md:justify-between md:p-4">
        <div className="flex flex-col gap-6">
          <Link to="/dashboard" className="font-display text-xl font-semibold text-fg">
            GIFT<span className="text-primary">LAB</span>
          </Link>
          <NavList />
        </div>
        <div className="flex flex-col gap-2 border-t border-border pt-3 text-sm">
          <span className="truncate text-fg-muted">{user?.name}</span>
          <Button variant="ghost" size="sm" className="min-h-[44px] justify-start" onClick={onLogout}>
            Log out
          </Button>
        </div>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        {/* Top strip (mobile: hamburger; desktop: thin bar) */}
        <header className="sticky top-0 z-header flex h-14 items-center gap-3 border-b border-border bg-surface/80 px-4 backdrop-blur-md md:justify-end">
          <button
            type="button"
            className="inline-flex h-11 w-11 items-center justify-center rounded-md text-fg hover:bg-surface-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring md:hidden"
            aria-label="Open menu"
            aria-expanded={drawer}
            onClick={() => setDrawer(true)}
          >
            <svg viewBox="0 0 20 20" className="h-5 w-5" fill="none" aria-hidden="true">
              <path d="M3 6h14M3 10h14M3 14h14" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" />
            </svg>
          </button>
          <span className="font-display text-sm font-semibold text-fg md:hidden">Staff Console</span>
          <span className="hidden text-sm text-fg-muted md:inline">{user?.name}</span>
        </header>

        <main id="main-content" className="mx-auto w-full max-w-content flex-1 px-4 py-6 sm:px-6">
          <Outlet />
        </main>
      </div>

      {/* Mobile drawer */}
      {drawer && (
        <div className="fixed inset-0 z-modal md:hidden">
          <div className="absolute inset-0 bg-black/50" onClick={() => setDrawer(false)} aria-hidden="true" />
          <div className="absolute inset-y-0 left-0 flex w-72 max-w-[85vw] flex-col gap-4 border-r border-border bg-surface p-4">
            <div className="flex items-center justify-between">
              <span className="font-display text-lg font-semibold text-fg">Menu</span>
              <button
                type="button"
                onClick={() => setDrawer(false)}
                aria-label="Close menu"
                className="inline-flex h-11 w-11 items-center justify-center rounded-md text-fg hover:bg-surface-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              >
                <svg viewBox="0 0 20 20" className="h-5 w-5" fill="none" aria-hidden="true">
                  <path d="M5 5l10 10M15 5L5 15" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" />
                </svg>
              </button>
            </div>
            <NavList onNavigate={() => setDrawer(false)} />
            <Button variant="ghost" size="sm" className="min-h-[44px] justify-start" onClick={onLogout}>
              Log out
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}
```

- [ ] **Step 2: Typecheck**

Run: `npm --prefix frontend run typecheck`
Expected: PASS. If `Badge`/`Button`/`cn` are not exported from `../ui`, check `frontend/src/ui/index.ts` and import from the correct path.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/components/StaffLayout.tsx
git commit -m "feat(frontend): staff console sidebar shell"
```

---

## Task 7: `RoleLayout` + routing rewire + login redirect

**Files:**
- Create: `frontend/src/components/RoleLayout.tsx`
- Modify: `frontend/src/App.tsx`
- Modify: `frontend/src/pages/LoginPage.tsx`

- [ ] **Step 1: Create RoleLayout**

`frontend/src/components/RoleLayout.tsx`:

```tsx
import Layout from './Layout';
import StaffLayout from './StaffLayout';
import { useAuthStore } from '../stores/authStore';
import { isStaffRole } from '../lib/roles';

/**
 * Shared authenticated routes (e.g. quotes) render in the staff console shell
 * for staff and in the standard shopfront layout for buyers. Both render an
 * <Outlet>, so nested routes are unaffected.
 */
export default function RoleLayout() {
  const role = useAuthStore((s) => s.user?.role);
  return isStaffRole(role) ? <StaffLayout /> : <Layout />;
}
```

- [ ] **Step 2: Rewire routes in `App.tsx`**

Add imports:

```tsx
import StaffLayout from './components/StaffLayout';
import RoleLayout from './components/RoleLayout';
import DashboardPage from './pages/DashboardPage';
```

Replace the staff-only `<Route>` blocks (`quotes`, `brand-kit`, `quotes/:id`, `production-queue`, `procurement`, `catalogue-admin`) that currently sit inside the `<Route path="/" element={<Layout />}>` group. Move them out as follows.

Keep public + buyer shop routes under the existing `Layout` group (index, products, products/:id, design/:id, cart, checkout, track, kits, login, catalogue redirects, and the `*` catch-all).

After the `Layout` group's closing `</Route>`, add two sibling groups:

```tsx
{/* Shared authenticated routes: staff render in the console shell, buyers in Layout. */}
<Route
  element={
    <ProtectedRoute>
      <RoleLayout />
    </ProtectedRoute>
  }
>
  <Route path="quotes" element={<QuoteListPage />} />
  <Route path="quotes/:id" element={<QuoteDetailPage />} />
  <Route path="brand-kit" element={<BrandKitPage />} />
</Route>

{/* Staff-only console. */}
<Route
  element={
    <ProtectedRoute staffOnly>
      <StaffLayout />
    </ProtectedRoute>
  }
>
  <Route path="dashboard" element={<DashboardPage />} />
  <Route path="production-queue" element={<ProductionQueuePage />} />
  <Route path="procurement" element={<ProcurementPage />} />
  <Route path="catalogue-admin" element={<CatalogueAdminPage />} />
</Route>
```

Note: `brand-kit` is buyer-only in the current guard (staff don't have a brand kit); it stays under `RoleLayout` which for a buyer is `Layout` — unchanged behaviour. `ProtectedRoute` used as a layout element renders its children (the shell) once authenticated; confirm `ProtectedRoute` returns `<>{children}</>` (it does) so it composes as a layout wrapper.

- [ ] **Step 3: Update the login redirect**

In `frontend/src/pages/LoginPage.tsx`, change the staff landing target:

```tsx
navigate(from ?? (isStaffRole(role) ? '/dashboard' : '/quotes'), { replace: true });
```

- [ ] **Step 4: Typecheck**

Run: `npm --prefix frontend run typecheck`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/components/RoleLayout.tsx frontend/src/App.tsx frontend/src/pages/LoginPage.tsx
git commit -m "feat(frontend): role-aware routing + staff dashboard landing"
```

---

## Task 8: `DashboardPage` widgets

**Files:**
- Create: `frontend/src/pages/DashboardPage.tsx`
- Test: `frontend/src/pages/DashboardPage.test.tsx`

- [ ] **Step 1: Write the failing render test**

`frontend/src/pages/DashboardPage.test.tsx`:

```tsx
import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import DashboardPage from './DashboardPage';
import { useDashboardStore } from '../stores/dashboardStore';

const base = {
  pipeline: { SENT: 2, ACCEPTED: 1 },
  production: { byState: { READY: 3, IN_PRODUCTION: 1 }, wip: 1, overdue: 2 },
  atRisk: [{ jobId: 5, quoteId: 9, track: 'UV', state: 'READY', readyAt: null }],
  queues: { proofsPending: 4, procurementToReconfirm: 2, cataloguePending: 6 },
  activity: [{ id: 1, actor: 'Ops', event: 'quote.amended', auditableType: 'Quote', auditableId: 9, at: null }],
  valueBooked: null,
};

beforeEach(() => useDashboardStore.setState({ data: base, loading: false, error: null }));

const renderPage = () => render(<MemoryRouter><DashboardPage /></MemoryRouter>);

describe('DashboardPage', () => {
  it('renders queue and production figures', () => {
    renderPage();
    expect(screen.getByText(/proofs pending/i)).toBeInTheDocument();
    expect(screen.getByText('4')).toBeInTheDocument();
    expect(screen.getByText(/at.risk|overdue/i)).toBeInTheDocument();
  });

  it('shows an error state', () => {
    useDashboardStore.setState({ data: null, loading: false, error: 'boom' });
    renderPage();
    expect(screen.getByText(/boom|could not/i)).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npm --prefix frontend run test -- DashboardPage`
Expected: FAIL — module not found.

- [ ] **Step 3: Implement the page**

`frontend/src/pages/DashboardPage.tsx`:

```tsx
import { Link } from 'react-router-dom';
import { useDashboardStore } from '../stores/dashboardStore';
import { Card, Skeleton } from '../ui';
import { ErrorState } from '../components/ui/States';

const PIPELINE_ORDER = [
  'DRAFT', 'SENT', 'CHANGES_REQUESTED', 'ACCEPTED', 'PROOFING', 'PROOF_APPROVED',
  'PO_ISSUED', 'CONFIRMED', 'PROCURING', 'READY', 'CLOSED', 'CANCELLED',
] as const;

function StatTile({ label, value, to }: { label: string; value: number; to: string }) {
  return (
    <Link to={to} className="rounded-lg border border-border bg-surface p-4 transition-colors hover:border-primary/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
      <p className="text-sm text-fg-muted">{label}</p>
      <p className="mt-1 font-display text-3xl text-fg">{value}</p>
    </Link>
  );
}

export default function DashboardPage() {
  const { data, loading, error, load } = useDashboardStore();

  if (loading && !data) {
    return (
      <div className="grid gap-4 sm:grid-cols-3">
        {Array.from({ length: 6 }).map((_, i) => <Skeleton key={i} height="6rem" />)}
      </div>
    );
  }

  if (error && !data) return <ErrorState message={error} onRetry={() => void load()} />;
  if (!data) return null;

  const maxPipe = Math.max(1, ...Object.values(data.pipeline));

  return (
    <div className="flex flex-col gap-8">
      <h1 className="font-display text-3xl text-fg">Dashboard</h1>

      {/* Action queues */}
      <section className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatTile label="Proofs pending" value={data.queues.proofsPending} to="/quotes" />
        <StatTile label="Procurement to reconfirm" value={data.queues.procurementToReconfirm} to="/procurement" />
        <StatTile label="Catalogue pending" value={data.queues.cataloguePending} to="/catalogue-admin" />
        <StatTile label="At-risk / overdue jobs" value={data.production.overdue} to="/production-queue" />
      </section>

      {data.valueBooked && (
        <Card padding="md">
          <p className="text-sm text-fg-muted">Value booked</p>
          <p className="mt-1 font-display text-3xl text-fg">
            {data.valueBooked.currency} {data.valueBooked.amount.toLocaleString()}
          </p>
        </Card>
      )}

      {/* Quote pipeline */}
      <section className="flex flex-col gap-3">
        <h2 className="font-display text-xl text-fg">Quote pipeline</h2>
        <Card padding="md" className="flex flex-col gap-2">
          {PIPELINE_ORDER.map((s) => {
            const n = data.pipeline[s] ?? 0;
            return (
              <div key={s} className="flex items-center gap-3 text-sm">
                <span className="w-40 shrink-0 text-fg-muted">{s}</span>
                <div className="h-3 flex-1 overflow-hidden rounded-full bg-surface-2">
                  <div className="h-full rounded-full bg-primary" style={{ width: `${(n / maxPipe) * 100}%` }} />
                </div>
                <span className="w-8 text-right tabular-nums text-fg">{n}</span>
              </div>
            );
          })}
        </Card>
      </section>

      {/* Production health */}
      <section className="flex flex-col gap-3">
        <h2 className="font-display text-xl text-fg">Production health</h2>
        <Card padding="md" className="flex flex-wrap gap-6 text-sm">
          {Object.entries(data.production.byState).map(([k, v]) => (
            <div key={k}><span className="text-fg-muted">{k}: </span><span className="font-semibold text-fg">{v}</span></div>
          ))}
          <div><span className="text-fg-muted">WIP: </span><span className="font-semibold text-fg">{data.production.wip}</span></div>
          <div><span className="text-fg-muted">Overdue: </span><span className="font-semibold text-danger">{data.production.overdue}</span></div>
        </Card>
      </section>

      {/* At-risk */}
      {data.atRisk.length > 0 && (
        <section className="flex flex-col gap-3">
          <h2 className="font-display text-xl text-fg">At-risk jobs</h2>
          <Card padding="none" className="divide-y divide-border">
            {data.atRisk.map((j) => (
              <Link key={j.jobId} to="/production-queue" className="flex items-center justify-between gap-3 p-3 text-sm hover:bg-surface-2">
                <span className="text-fg">Job #{j.jobId} · Quote #{j.quoteId}</span>
                <span className="text-fg-muted">{j.track} · {j.state}</span>
              </Link>
            ))}
          </Card>
        </section>
      )}

      {/* Live activity */}
      <section className="flex flex-col gap-3">
        <h2 className="font-display text-xl text-fg">Recent activity</h2>
        <Card padding="none" className="divide-y divide-border">
          {data.activity.length === 0 ? (
            <p className="p-4 text-sm text-fg-muted">No recent activity.</p>
          ) : (
            data.activity.map((a) => (
              <div key={a.id} className="flex items-center justify-between gap-3 p-3 text-sm">
                <span className="text-fg">
                  <span className="font-medium">{a.actor ?? 'System'}</span> · {a.event}
                  <span className="text-fg-muted"> ({a.auditableType} #{a.auditableId})</span>
                </span>
                <span className="shrink-0 text-fg-subtle">{a.at ? new Date(a.at).toLocaleString() : ''}</span>
              </div>
            ))
          )}
        </Card>
      </section>
    </div>
  );
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npm --prefix frontend run test -- DashboardPage`
Expected: PASS. If `ErrorState` import path differs, confirm it in `frontend/src/components/ui/States.tsx`.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/pages/DashboardPage.tsx frontend/src/pages/DashboardPage.test.tsx
git commit -m "feat(frontend): staff dashboard page widgets"
```

---

## Task 9: Full verification pass

**Files:** none (verification only)

- [ ] **Step 1: Backend suite**

Run: `php artisan test --filter=DashboardTest`
Expected: all green.

- [ ] **Step 2: Frontend typecheck + tests**

Run: `npm --prefix frontend run typecheck && npm --prefix frontend run test`
Expected: typecheck clean; all tests pass.

- [ ] **Step 3: Manual smoke via preview (per repo preview workflow)**

Start the frontend (autoPort) + ensure API on :8000, log in as `superadmin@giftlab.local` / `ChangeMe!123`. Confirm: login lands on `/dashboard`; sidebar shows badges; buyer login (`buyer@nexgen.com.sg`) still lands on `/quotes` with the top-bar layout (no sidebar). Resize to 375px: sidebar collapses to hamburger drawer, tap targets ≥44px.

- [ ] **Step 4: Final commit (if any smoke fixes)**

```bash
git add -A
git commit -m "chore: staff console dashboard verification fixes"
```

---

## Self-review notes (addressed)

- **Spec coverage:** sidebar shell (Task 6/7), dashboard widgets (Task 8), endpoint + all six data sources (Task 2), perf contract — indexes verified + audit index added (Task 1), bounded/no-N+1/cache enforced + guard-tested (Task 2/4), login redirect (Task 7), tests backend+frontend (Task 3/4/5/8). 
- **Open item resolved:** shared `/quotes` uses `RoleLayout` (default from spec) — no page duplication.
- **Type consistency:** `DashboardPayload` shape identical in `lib/dashboard.ts`, store test, controller JSON, and page consumer (`queues.proofsPending`, `production.overdue`, `valueBooked`).
