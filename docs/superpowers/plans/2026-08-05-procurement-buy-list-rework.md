# Procurement Buy-List Rework Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the Procurement menu into a manual buy list of items to purchase for approved orders, where clicking "Bought" raises the order's bill and pushes the item to the production floor in one action.

**Architecture:** A shared eligibility query (`BuyListQuery`) drives both a read-only list endpoint and the "mark bought" actions. "Mark bought" reuses `QuoteService::issueInvoice` (bill) + the existing line-state chain to `Ready` + `tryQueue` (build jobs), but bypasses the stock/marketplace/filament procurement strategies entirely. No new order-state edges — the existing `PROOF_APPROVED → INVOICED → CONFIRMED → PROCURING → READY` path is driven from the new trigger.

**Tech Stack:** Laravel 11 (PHP), Pest tests; React + TypeScript + Zustand frontend, Vitest.

Spec: `docs/superpowers/specs/2026-08-05-procurement-buy-list-rework-design.md`

---

### Task 1: Shared buy-list eligibility query

**Files:**
- Create: `app/Services/Procurement/BuyListQuery.php`
- Test: `tests/Feature/BuyListQueryTest.php`

Eligibility: line `Pending`/`Amended`, on a quote in `PROOF_APPROVED`, `INVOICED`, `CONFIRMED`, or `PROCURING`. Excludes `ARTWORK_APPROVED` (price not agreed), floor/closed/cancelled orders, and already-bought lines.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/BuyListQueryTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\LineItemState;
use App\Enums\QuoteState;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Quote;
use App\Services\Procurement\BuyListQuery;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('includes pending lines on proof-approved orders and excludes others', function (): void {
    $product = Product::factory()->create();

    $approved = Quote::factory()->create(['state' => QuoteState::ProofApproved->value]);
    $onBuyList = LineItem::factory()->for($approved, 'quote')->for($product)
        ->create(['line_state' => LineItemState::Pending->value]);

    // Excluded: artwork-only (price not agreed)
    $artworkOnly = Quote::factory()->create(['state' => QuoteState::ArtworkApproved->value]);
    LineItem::factory()->for($artworkOnly, 'quote')->for($product)
        ->create(['line_state' => LineItemState::Pending->value]);

    // Excluded: already bought (Ready)
    LineItem::factory()->for($approved, 'quote')->for($product)
        ->create(['line_state' => LineItemState::Ready->value]);

    $ids = app(BuyListQuery::class)->lines()->pluck('id')->all();

    expect($ids)->toContain($onBuyList->id)->toHaveCount(1);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/BuyListQueryTest.php`
Expected: FAIL — class `BuyListQuery` not found.

- [ ] **Step 3: Implement `BuyListQuery`**

Create `app/Services/Procurement/BuyListQuery.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Enums\LineItemState;
use App\Enums\QuoteState;
use App\Models\LineItem;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single source of truth for "which line items are waiting to be bought".
 * Shared by the buy-list read endpoint and the mark-bought actions so the list
 * a staffer sees and the set an action mutates can never drift apart.
 */
final class BuyListQuery
{
    private const OPEN_LINE_STATES = [
        LineItemState::Pending,
        LineItemState::Amended,
    ];

    private const ELIGIBLE_QUOTE_STATES = [
        QuoteState::ProofApproved,
        QuoteState::Invoiced,
        QuoteState::Confirmed,
        QuoteState::Procuring,
    ];

    /**
     * @return Builder<LineItem>
     */
    public function lines(): Builder
    {
        return LineItem::query()
            ->whereIn('line_state', array_map(fn (LineItemState $s) => $s->value, self::OPEN_LINE_STATES))
            ->whereHas('quote', function (Builder $q): void {
                $q->whereIn('state', array_map(fn (QuoteState $s) => $s->value, self::ELIGIBLE_QUOTE_STATES));
            });
    }

    /**
     * @return Builder<LineItem>
     */
    public function linesForProduct(int $productId): Builder
    {
        return $this->lines()->where('product_id', $productId);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/BuyListQueryTest.php`
Expected: PASS. (If `LineItem`/`Quote` factories lack a `state`/`line_state` override path, set the columns directly in the test as shown.)

- [ ] **Step 5: Commit**

```bash
git add app/Services/Procurement/BuyListQuery.php tests/Feature/BuyListQueryTest.php
git commit -m "feat(procurement): shared buy-list eligibility query"
```

---

### Task 2: `ProcurementManager::markBought` — advance a line without sourcing

**Files:**
- Modify: `app/Services/Procurement/ProcurementManager.php`
- Test: `tests/Feature/MarkBoughtLineTest.php`

Mirrors the private `onProcured` chain (`Procuring → Purchased → Inbound → Received → Ready`) but is driven by a staff "bought" click: no strategy, no stock/marketplace/filament effect. Records the ordered qty and the quoted price.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/MarkBoughtLineTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\LineItemState;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\Procurement\ProcurementManager;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('advances a pending line to READY with no stock movement', function (): void {
    $product = Product::factory()->create();
    $line = LineItem::factory()->for($product)->create([
        'line_state' => LineItemState::Pending->value,
        'qty' => 12,
        'unit_price' => 4.50,
    ]);

    app(ProcurementManager::class)->markBought($line);

    expect($line->fresh()->line_state)->toBe(LineItemState::Ready)
        ->and((int) $line->fresh()->procured_qty)->toBe(12)
        ->and(StockMovement::query()->where('line_item_id', $line->id)->exists())->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/MarkBoughtLineTest.php`
Expected: FAIL — `Call to undefined method ...::markBought()`.

- [ ] **Step 3: Implement `markBought`**

In `app/Services/Procurement/ProcurementManager.php`, add a public method (reuse the existing `onProcured` transition chain and `audit`):

```php
    /**
     * Staff have physically bought (or printed) this line. Advance it straight
     * to READY through the same state chain procurement uses, but WITHOUT any
     * sourcing strategy: no stock decrement, no marketplace re-check, no
     * filament draw. The bill and job-build are driven by QuoteService.
     */
    public function markBought(LineItem $lineItem): void
    {
        if ($lineItem->line_state !== LineItemState::Pending && $lineItem->line_state !== LineItemState::Amended) {
            throw new DomainRuleException(
                "Line item {$lineItem->id} is not in a buyable state ({$lineItem->line_state->value})."
            );
        }

        DB::transaction(function () use ($lineItem): void {
            $lineItem->transitionTo(LineItemState::Procuring);
            $lineItem->procured_qty = $lineItem->qty;
            $lineItem->procured_price = $lineItem->unit_price;
            $this->onProcured($lineItem);

            $this->audit->log($lineItem, 'line_item.bought', null, [
                'procured_qty' => $lineItem->procured_qty,
                'procured_price' => $lineItem->procured_price,
            ]);
        });
    }
```

(`onProcured` already saves and runs `Purchased → Inbound → Received → Ready` + its own `line_item.procured` audit; the extra `line_item.bought` entry records the manual action.)

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/MarkBoughtLineTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Procurement/ProcurementManager.php tests/Feature/MarkBoughtLineTest.php
git commit -m "feat(procurement): mark a line bought without sourcing side effects"
```

---

### Task 3: `QuoteService` orchestration — bill + advance + queue

**Files:**
- Modify: `app/Services/QuoteService.php` (add `markLineBought`, `markProductBought`; inject `BuyListQuery`)
- Test: `tests/Feature/MarkBoughtOrderTest.php`

`markLineBought` ensures the bill exists (auto-`issueInvoice` with the order reference as PO), drives `CONFIRMED → PROCURING`, marks the line bought, then `tryQueue`. When the last line is bought the quote rolls to `READY`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/MarkBoughtOrderTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\LineItemState;
use App\Enums\QuoteState;
use App\Models\Invoice;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Quote;
use App\Services\QuoteService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('raises the bill once and moves the order to the floor when the last line is bought', function (): void {
    $product = Product::factory()->create();
    $quote = Quote::factory()->create([
        'state' => QuoteState::ProofApproved->value,
        'accepted_at' => now(), // price agreed — issueInvoice precondition
    ]);
    $line = LineItem::factory()->for($quote, 'quote')->for($product)->create([
        'line_state' => LineItemState::Pending->value,
        'qty' => 3,
        'unit_price' => 6.00,
    ]);

    app(QuoteService::class)->markLineBought($line->fresh());

    expect($quote->fresh()->state)->toBe(QuoteState::Ready)
        ->and(Invoice::query()->where('quote_id', $quote->id)->count())->toBe(1)
        ->and($line->fresh()->line_state)->toBe(LineItemState::Ready);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/MarkBoughtOrderTest.php`
Expected: FAIL — `Call to undefined method ...::markLineBought()`.

- [ ] **Step 3: Implement the orchestration**

Confirm `QuoteService` already depends on `ProcurementManager` (property `$this->procurement`, used by `procure()`). Inject `BuyListQuery` in the constructor (add a `private readonly BuyListQuery $buyList` parameter). Then add:

```php
    /**
     * Staff have bought this line. Guarantee the order is billed (auto-issue the
     * invoice with the order reference as PO), drive it into PROCURING, mark the
     * line bought (no sourcing), then attempt to queue. The last line bought
     * rolls the order to READY. Idempotent: a line already past PENDING/AMENDED
     * is skipped, and issueInvoice returns the existing invoice on retry.
     */
    public function markLineBought(LineItem $line): Quote
    {
        $quote = $line->quote()->firstOrFail();

        if ($quote->state === QuoteState::ProofApproved) {
            $this->issueInvoice($quote, $quote->reference, null, null);
            $quote = $quote->fresh();
        }

        if ($quote->state === QuoteState::Confirmed) {
            DB::transaction(function () use ($quote): void {
                $previous = $quote->state->value;
                $quote->transitionTo(QuoteState::Procuring);
                DB::afterCommit(fn () => Broadcasting::dispatch(fn () => QuoteStateChanged::dispatch($quote, $previous)));
            });
            $quote = $quote->fresh();
        }

        if ($line->line_state === LineItemState::Pending || $line->line_state === LineItemState::Amended) {
            $this->procurement->markBought($line);
        }

        $this->tryQueue($quote->fresh(['lineItems']));

        return $quote->fresh(['lineItems', 'jobs.shipment']);
    }

    /**
     * Bulk "mark all bought" for one product across every eligible order (the
     * grouped buy-list view). Returns the number of lines advanced.
     */
    public function markProductBought(int $productId): int
    {
        $lines = $this->buyList->linesForProduct($productId)->get();

        foreach ($lines as $line) {
            $this->markLineBought($line);
        }

        return $lines->count();
    }
```

Ensure the `use` statements for `QuoteState`, `LineItemState`, `Broadcasting`, `QuoteStateChanged`, and `App\Services\Procurement\BuyListQuery` are present (most already are — add `BuyListQuery`).

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/MarkBoughtOrderTest.php`
Expected: PASS.

- [ ] **Step 5: Add the multi-line + idempotency test**

Append to `tests/Feature/MarkBoughtOrderTest.php`:

```php
it('does not double-bill when a second line is bought and is a no-op on re-click', function (): void {
    $product = Product::factory()->create();
    $quote = Quote::factory()->create([
        'state' => QuoteState::ProofApproved->value,
        'accepted_at' => now(),
    ]);
    $a = LineItem::factory()->for($quote, 'quote')->for($product)->create(['line_state' => LineItemState::Pending->value, 'qty' => 1, 'unit_price' => 2]);
    $b = LineItem::factory()->for($quote, 'quote')->for($product)->create(['line_state' => LineItemState::Pending->value, 'qty' => 1, 'unit_price' => 2]);

    $svc = app(QuoteService::class);
    $svc->markLineBought($a->fresh());
    expect($quote->fresh()->state)->toBe(QuoteState::Procuring); // still one line open

    $svc->markLineBought($b->fresh());
    $svc->markLineBought($b->fresh()); // re-click: no-op

    expect(Invoice::query()->where('quote_id', $quote->id)->count())->toBe(1)
        ->and($quote->fresh()->state)->toBe(QuoteState::Ready);
});
```

Run: `php artisan test tests/Feature/MarkBoughtOrderTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/QuoteService.php tests/Feature/MarkBoughtOrderTest.php
git commit -m "feat(procurement): mark-bought orchestration (auto-bill + queue)"
```

---

### Task 4: Endpoints — buy-list read + mark-bought actions

**Files:**
- Modify: `app/Http/Controllers/ProcurementController.php` (add `buyList`, `markBought`, `markProductBought`; inject `BuyListQuery`)
- Modify: `routes/api.php` (~line 213, near the existing procurement routes)
- Test: `tests/Feature/BuyListEndpointTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/BuyListEndpointTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\LineItemState;
use App\Enums\QuoteState;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Quote;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('lists buy-list lines and marks one bought', function (): void {
    Sanctum::actingAs(User::factory()->staff()->create()); // adjust to the project's staff factory helper

    $product = Product::factory()->create();
    $quote = Quote::factory()->create(['state' => QuoteState::ProofApproved->value, 'accepted_at' => now()]);
    $line = LineItem::factory()->for($quote, 'quote')->for($product)->create([
        'line_state' => LineItemState::Pending->value, 'qty' => 1, 'unit_price' => 3,
    ]);

    $this->getJson('/api/procurement/buy-list')
        ->assertOk()
        ->assertJsonFragment(['id' => $line->id]);

    $this->postJson("/api/line-items/{$line->id}/mark-bought")
        ->assertOk();

    expect($line->fresh()->line_state)->toBe(LineItemState::Ready);
});
```

(Match the staff-authentication helper the other feature tests use — see `tests/Feature/ProcurementTest.php` `beforeEach`.)

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/BuyListEndpointTest.php`
Expected: FAIL — 404 on the new routes.

- [ ] **Step 3: Add controller actions**

In `app/Http/Controllers/ProcurementController.php`, inject `BuyListQuery` into the constructor and add:

```php
    public function buyList(Request $request): AnonymousResourceCollection
    {
        $this->authorize('manageProduction', Quote::class);

        $lines = $this->buyList->lines()
            ->with(['product', 'quote'])
            ->orderBy('product_id')
            ->get();

        return LineItemResource::collection($lines);
    }

    public function markBought(LineItem $lineItem): LineItemResource
    {
        $this->authorize('manageProduction', Quote::class);
        $this->quotes->markLineBought($lineItem);

        return new LineItemResource($lineItem->fresh()->load(['product', 'quote']));
    }

    public function markProductBought(Request $request, int $product): JsonResponse
    {
        $this->authorize('manageProduction', Quote::class);
        $count = $this->quotes->markProductBought($product);

        return response()->json(['marked' => $count]);
    }
```

Add imports: `use App\Services\Procurement\BuyListQuery;` and `use Illuminate\Http\JsonResponse;`.

- [ ] **Step 4: Add routes**

In `routes/api.php`, near the existing procurement block (~line 213):

```php
    // Buy list — the manual purchase worklist for approved orders.
    Route::get('/procurement/buy-list', [ProcurementController::class, 'buyList'])->middleware('permission:procurement.view');
    Route::post('/line-items/{lineItem}/mark-bought', [ProcurementController::class, 'markBought'])->middleware('permission:procurement.manage');
    Route::post('/procurement/buy-list/mark-product/{product}', [ProcurementController::class, 'markProductBought'])->middleware('permission:procurement.manage');
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Feature/BuyListEndpointTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/ProcurementController.php routes/api.php tests/Feature/BuyListEndpointTest.php
git commit -m "feat(procurement): buy-list read + mark-bought endpoints"
```

---

### Task 5: Frontend store — buy list + mark actions

**Files:**
- Modify: `frontend/src/stores/procurementStore.ts`
- Test: `frontend/src/stores/procurementStore.test.ts`

Add a `buyList` slice alongside the existing reconfirm state (leave the reconfirm code — it is removed with the page in Task 7 cleanup, but keeping it here avoids a broken intermediate build).

- [ ] **Step 1: Write the failing test**

Extend `frontend/src/stores/procurementStore.test.ts` with a mocked-api test:

```ts
import { describe, it, expect, vi, beforeEach } from 'vitest';
import api from '../lib/api';
import { useProcurementStore } from './procurementStore';

vi.mock('../lib/api');

describe('buy list', () => {
  beforeEach(() => { useProcurementStore.setState({ buyList: [] }); });

  it('fetchBuyList populates rows', async () => {
    (api.get as unknown as vi.Mock).mockResolvedValue({
      data: { data: [{ id: 1, product_id: 9, qty: 2, quote_reference: 'GL-1', product: { name: 'Mug', class: 'SCRAPED_UV', source_url: 'x', affiliate_url: 'y' } }] },
    });
    await useProcurementStore.getState().fetchBuyList();
    expect(useProcurementStore.getState().buyList).toHaveLength(1);
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd frontend && npx vitest run src/stores/procurementStore.test.ts`
Expected: FAIL — `fetchBuyList` / `buyList` undefined.

- [ ] **Step 3: Implement the slice**

Add a `BuyListRow` interface and extend the store:

```ts
export interface BuyListRow {
  id: number;
  product_id: number;
  quote_id: number;
  quote_reference?: string | null;
  qty: number;
  product: {
    name: string;
    class: 'CORE' | 'SCRAPED_UV' | 'MODEL_3D';
    source_url?: string | null;
    affiliate_url?: string | null;
  };
}
```

Add to `ProcurementStoreState`: `buyList: BuyListRow[]`, and the three methods:

```ts
  buyList: [],

  fetchBuyList: async () => {
    set({ loading: true, error: null });
    try {
      const { data } = await api.get<{ data: BuyListRow[] }>('/procurement/buy-list');
      set({ buyList: data.data, loading: false });
    } catch (err) {
      set({ loading: false, error: apiError(err) });
    }
  },

  markBought: async (lineItemId: number) => {
    await ensureCsrf();
    await api.post(`/line-items/${lineItemId}/mark-bought`);
    set((s) => ({ buyList: s.buyList.filter((r) => r.id !== lineItemId) }));
  },

  markProductBought: async (productId: number) => {
    await ensureCsrf();
    await api.post(`/procurement/buy-list/mark-product/${productId}`);
    set((s) => ({ buyList: s.buyList.filter((r) => r.product_id !== productId) }));
  },
```

Add the matching method signatures to the `ProcurementStoreState` interface.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd frontend && npx vitest run src/stores/procurementStore.test.ts`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/stores/procurementStore.ts frontend/src/stores/procurementStore.test.ts
git commit -m "feat(procurement): buy-list store slice + mark actions"
```

---

### Task 6: Frontend page — grouped buy list with buy + mark controls

**Files:**
- Modify: `frontend/src/pages/ProcurementPage.tsx` (replace the reconfirm-desk body with the buy list)
- Test: `frontend/src/pages/ProcurementPage.test.tsx`

Two views via a toggle: **by product** (group rows by `product_id`; each group shows a buy link + a "Mark all bought") and **by order** (group by `quote_reference`; each row a per-line "Bought"). Buy link: `affiliate_url ?? source_url` for `SCRAPED_UV`, `source_url` for `MODEL_3D`.

- [ ] **Step 1: Write the failing test**

Replace the reconfirm-focused assertions in `frontend/src/pages/ProcurementPage.test.tsx` with:

```tsx
import { render, screen } from '@testing-library/react';
import { describe, it, expect, beforeEach } from 'vitest';
import { MemoryRouter } from 'react-router-dom';
import ProcurementPage from './ProcurementPage';
import { useProcurementStore } from '../stores/procurementStore';

describe('ProcurementPage buy list', () => {
  beforeEach(() => {
    useProcurementStore.setState({
      buyList: [{ id: 1, product_id: 9, quote_id: 5, quote_reference: 'GL-5', qty: 4,
        product: { name: 'Blue Mug', class: 'SCRAPED_UV', source_url: 's', affiliate_url: 'https://shopee/x' } }],
      loading: false, error: null,
    } as never);
  });

  it('renders a buy-list row with a buy link and a bought control', () => {
    render(<MemoryRouter><ProcurementPage /></MemoryRouter>);
    expect(screen.getByText('Blue Mug')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /buy/i })).toHaveAttribute('href', 'https://shopee/x');
    expect(screen.getByRole('button', { name: /bought/i })).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd frontend && npx vitest run src/pages/ProcurementPage.test.tsx`
Expected: FAIL — old page renders the reconfirm desk, not the buy list.

- [ ] **Step 3: Rewrite the page body**

Replace `ProcurementPage.tsx`'s data wiring and render. Core skeleton (keep the existing `Card`/`Button`/`Badge` imports and `ListFilters` if desired):

```tsx
import { useEffect, useState } from 'react';
import { useProcurementStore, type BuyListRow } from '../stores/procurementStore';
import { Badge, Button, Card, EmptyState, useToast } from '../ui';

const buyLink = (p: BuyListRow['product']): string | null =>
  p.class === 'MODEL_3D' ? (p.source_url ?? null) : (p.affiliate_url ?? p.source_url ?? null);

export default function ProcurementPage() {
  const { toast } = useToast();
  const { buyList, loading, error, fetchBuyList, markBought, markProductBought } = useProcurementStore();
  const [view, setView] = useState<'product' | 'order'>('product');

  useEffect(() => { void fetchBuyList(); }, [fetchBuyList]);

  const groupKey = (r: BuyListRow) => (view === 'product' ? String(r.product_id) : (r.quote_reference ?? String(r.quote_id)));
  const groups = buyList.reduce<Record<string, BuyListRow[]>>((acc, r) => {
    (acc[groupKey(r)] ??= []).push(r); return acc;
  }, {});

  const onBought = async (id: number) => { await markBought(id); toast({ title: 'Marked bought' }); };
  const onProductBought = async (pid: number) => { await markProductBought(pid); toast({ title: 'Marked all bought' }); };

  if (loading) return <p>Loading…</p>;
  if (error) return <p role="alert">{error}</p>;
  if (buyList.length === 0) return <EmptyState title="Nothing to buy" />;

  return (
    <div>
      <div role="tablist" aria-label="Buy list view">
        <Button aria-pressed={view === 'product'} onClick={() => setView('product')}>By product</Button>
        <Button aria-pressed={view === 'order'} onClick={() => setView('order')}>By order</Button>
      </div>

      {Object.entries(groups).map(([key, rows]) => (
        <Card key={key} padding="md">
          <h3>{view === 'product' ? rows[0].product.name : `Order ${rows[0].quote_reference ?? rows[0].quote_id}`}</h3>
          <ul>
            {rows.map((r) => {
              const href = buyLink(r.product);
              return (
                <li key={r.id}>
                  <span>{r.product.name} × {r.qty}</span>
                  {view === 'order' && <Badge>{r.product.name}</Badge>}
                  {href
                    ? <a href={href} target="_blank" rel="noopener noreferrer">Buy</a>
                    : <span>No source link</span>}
                  {view === 'order' && <Button onClick={() => onBought(r.id)}>Bought</Button>}
                </li>
              );
            })}
          </ul>
          {view === 'product' && (
            <Button onClick={() => onProductBought(rows[0].product_id)}>Mark all bought</Button>
          )}
        </Card>
      ))}
    </div>
  );
}
```

Adjust to the project's actual `ui` component props (e.g. `Button` variant names). Keep the buy link opening in a new tab.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd frontend && npx vitest run src/pages/ProcurementPage.test.tsx`
Expected: PASS.

- [ ] **Step 5: Verify in the preview**

Start the dev server, seed an approved order with a pending line, open the Procurement menu, toggle both views, confirm the buy link points at the right URL and "Bought" removes the row. Screenshot both views.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/pages/ProcurementPage.tsx frontend/src/pages/ProcurementPage.test.tsx
git commit -m "feat(procurement): grouped buy-list page with buy links + mark controls"
```

---

### Task 7: Retire the old order-page buying buttons

**Files:**
- Modify: `frontend/src/pages/QuoteDetailPage.tsx` (remove the "Issue invoice" and "Run procurement" staff controls)
- Test: `frontend/src/pages/QuoteDetailPage.test.tsx`

The bill and procurement are now driven by the buy list's "Bought". Remove the manual staff buttons so the two paths can't diverge. Leave the backend `/quotes/{quote}/invoice` and `/quotes/{quote}/procure` routes in place (used by tests/other flows); only the UI entry points go.

- [ ] **Step 1: Write the failing test**

In `frontend/src/pages/QuoteDetailPage.test.tsx`, add:

```tsx
it('no longer shows Issue invoice / Run procurement staff buttons', () => {
  // render QuoteDetailPage for a PROOF_APPROVED / CONFIRMED quote as staff (mirror existing render helpers in this file)
  expect(screen.queryByRole('button', { name: /issue invoice/i })).toBeNull();
  expect(screen.queryByRole('button', { name: /run procurement/i })).toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd frontend && npx vitest run src/pages/QuoteDetailPage.test.tsx`
Expected: FAIL — buttons still present.

- [ ] **Step 3: Remove the controls**

In `QuoteDetailPage.tsx`, locate the staff action controls that call the invoice and procure endpoints (search for `issueInvoice`, `/invoice`, `procure`) and remove those buttons and their handlers. Keep everything else (proof approval, cancel, etc.) intact.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd frontend && npx vitest run src/pages/QuoteDetailPage.test.tsx`
Expected: PASS.

- [ ] **Step 5: Full frontend + backend suites**

Run: `cd frontend && npx vitest run` then `php artisan test`
Expected: PASS. Fix any test that asserted the old buttons or the old auto-procure UI flow.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/pages/QuoteDetailPage.tsx frontend/src/pages/QuoteDetailPage.test.tsx
git commit -m "feat(procurement): retire manual invoice/procure buttons (folded into Bought)"
```

---

## Self-Review Notes

- **Spec coverage:** list read model (Task 1, 4), manual mark-bought without sourcing (Task 2), auto-bill + one-push-to-floor (Task 3), endpoints (Task 4), store (Task 5), grouped views + buy links + mark controls (Task 6), removal of old buttons/steps (Task 7). Price-warning flag intentionally absent (spec dropped it).
- **Type consistency:** `markBought` used consistently (`ProcurementManager::markBought`, store `markBought`); orchestration named `markLineBought`/`markProductBought` in `QuoteService` and store `markBought`/`markProductBought`. Endpoint paths match between Task 4 and Task 5.
- **Known adaptation points flagged inline:** staff auth helper in feature tests, `ui` component prop names, and the QuoteDetailPage render helper — these follow existing per-file patterns and must be matched to the real code during execution.
- **Out of scope (not tasks):** deleting `ProcurementManager` strategy classes, removing the Buy-list (supplier reorder) menu, filament-tracking removal — separate follow-ups per the spec.
```
