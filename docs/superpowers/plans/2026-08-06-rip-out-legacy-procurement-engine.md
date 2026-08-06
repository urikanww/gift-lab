# Rip Out the Legacy Procurement Engine Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Delete the dormant automatic procurement engine (sourcing strategies, `procure`/`reconfirm`/`confirm-stock`, the reconfirm desk) with no database changes and no behaviour change to the live app.

**Architecture:** Deletion-only. Order the work tests-first so every commit leaves the full suite green: (1) prune tests that pin the dead code, (2) delete the backend engine + routes + wiring, (3) remove the frontend reconfirm-desk slice. Shared code the buy list relies on (`ProcurementManager::markBought`, `tryQueue`, `isReadyForProduction`, the stock ledger, cancel-refund) is kept.

**Tech Stack:** Laravel 11 (Pest), React + TypeScript (Vitest).

Spec: `docs/superpowers/specs/2026-08-06-rip-out-legacy-procurement-engine-design.md`
Branch: `chore/rip-out-legacy-procurement` (off master).

---

### Task 1: Prune tests that pin the dead engine

Delete tests-first so that after the code is removed in Task 2 the suite is already consistent. The engine still exists during this task, so removing these tests leaves the suite green.

**Files:**
- Delete: `tests/Feature/ProcurementTest.php`, `tests/Feature/Model3dProcurementTest.php`, `tests/Feature/ScrapedUvProcurementTest.php`, `tests/Feature/ProcurementDeskTest.php`
- Delete: `tests/Harness/Scenarios/HappyPathTest.php`, `tests/Harness/Scenarios/AcceptAsIsRetotalsTest.php`, `tests/Harness/Scenarios/Cancel3dFilamentReturnTest.php`
- Modify: `tests/Harness/Agents/StaffAgent.php`, `tests/Feature/QuoteGstPersistenceTest.php`, `tests/Feature/QuoteHistoryTest.php`, `tests/Feature/BroadcastChannelAuthTest.php`

- [ ] **Step 1: Delete the strategy/desk test files**

```bash
cd "D:/work/NexGen/gift-lab"
git rm tests/Feature/ProcurementTest.php tests/Feature/Model3dProcurementTest.php \
       tests/Feature/ScrapedUvProcurementTest.php tests/Feature/ProcurementDeskTest.php \
       tests/Harness/Scenarios/HappyPathTest.php tests/Harness/Scenarios/AcceptAsIsRetotalsTest.php \
       tests/Harness/Scenarios/Cancel3dFilamentReturnTest.php
```

- [ ] **Step 2: Trim the harness staff agent**

In `tests/Harness/Agents/StaffAgent.php`, delete the `procure()` method (posts to `/procure`) and the `confirmStock()` method (posts to `/confirm-stock`). Leave `createDraft`, `send`, `stageProof`, `sendProofs`, `issueInvoice`, `cancel`. If any reconfirm/accept-as-is helper exists, delete it too.

- [ ] **Step 3: Remove the reconfirm-drop tests from QuoteGstPersistenceTest**

In `tests/Feature/QuoteGstPersistenceTest.php`, delete the two `it(...)` blocks that call `app(QuoteService::class)->reconfirmLine(...)` (the "drops a customized lines fee-inclusive contribution ... on reconfirm-drop" test near line 99, and the VOID re-anchor reconfirm test near line 199). Keep the create/amend/invoice GST tests. Update the file docblock (line ~19) to drop the "reconfirm" mention.

- [ ] **Step 4: Remove the procure-rollback test from QuoteHistoryTest**

In `tests/Feature/QuoteHistoryTest.php`, delete the `it('rolls the state back when the audit insert fails during procure', ...)` block (near line 51). Keep the rest.

- [ ] **Step 5: Remove the staff.procurement cases from BroadcastChannelAuthTest**

In `tests/Feature/BroadcastChannelAuthTest.php`, delete the `it(...)` blocks asserting `staffChannelCallback('staff.procurement')` behaviour (the deny/allow/superadmin/buyer cases). If that empties the file, `git rm` the whole file; otherwise keep the other channels' tests.

- [ ] **Step 6: Run the full backend suite — still green with the engine present**

Run: `php artisan test`
Expected: PASS (fewer tests; no failures). Fix any smoke scenario that indirectly called a removed `StaffAgent` method by re-seeding the state it needed.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "test: drop tests pinning the dead procurement engine"
```

---

### Task 2: Delete the backend engine, routes, and wiring

**Files:**
- Delete: `app/Services/Procurement/CoreProcurement.php`, `ScrapedUvProcurement.php`, `Model3dProcurement.php`, `Contracts/ProcurementStrategy.php`, `Contracts/MarketplaceRechecker.php`, `FixtureMarketplaceRechecker.php`, `ProcurementResult.php`, `app/Enums/ProcurementOutcome.php`, `app/Events/LineItemAwaitingReconfirm.php`, `app/Http/Requests/ReconfirmLineItemRequest.php`
- Modify: `app/Services/Procurement/ProcurementManager.php`, `app/Services/QuoteService.php`, `app/Providers/AppServiceProvider.php`, `app/Http/Controllers/QuoteController.php`, `app/Http/Controllers/ProcurementController.php`, `routes/api.php`, `routes/channels.php`

- [ ] **Step 1: Trim `ProcurementManager` to the buy-list surface**

Edit `app/Services/Procurement/ProcurementManager.php`. Replace the constructor and remove `procureLine`, `strategyFor`, `onReconfirm`, `onAdvisory`, `blocksOnQtyShort`. Keep `markBought` and `onProcured`. The class should reduce to (imports pruned to what remains — `LineItemState`, `LineItem`, `DomainRuleException`, `AuditLogger`, `DB`):

```php
final class ProcurementManager
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {
    }

    // markBought(LineItem $lineItem): void   — unchanged, keep as-is
    // onProcured(LineItem $lineItem): void    — private, unchanged, keep as-is
}
```

Remove now-unused imports (`ProductClass`, `ProcurementOutcome`, `LineItemAwaitingReconfirm`, `PricingConfig`, `Broadcasting`, the strategy contracts) — keep only those `markBought`/`onProcured` reference.

- [ ] **Step 2: Delete the dead `QuoteService` methods**

In `app/Services/QuoteService.php`, delete these methods entirely: `procure()`, `reconfirmLine()`, `retotalAfterReconfirm()`, `confirmStock()`. Keep `markLineBought`, `markQuoteBought`, `markProductBought`, `tryQueue`, `isReadyForProduction`, `cancelIfNothingLeftToProduce`, `returnConsumedStock`/`returnConsumedFilament`. After deleting, remove any import left unused ONLY by the deleted methods (e.g. `ProcurementOutcome` if referenced) — verify with the editor/`grep` before removing an import; leave shared ones.

- [ ] **Step 3: Delete the strategy/support files**

```bash
git rm app/Services/Procurement/CoreProcurement.php app/Services/Procurement/ScrapedUvProcurement.php \
       app/Services/Procurement/Model3dProcurement.php app/Services/Procurement/Contracts/ProcurementStrategy.php \
       app/Services/Procurement/Contracts/MarketplaceRechecker.php app/Services/Procurement/FixtureMarketplaceRechecker.php \
       app/Services/Procurement/ProcurementResult.php app/Enums/ProcurementOutcome.php \
       app/Events/LineItemAwaitingReconfirm.php app/Http/Requests/ReconfirmLineItemRequest.php
```

- [ ] **Step 4: Remove the rechecker bindings from AppServiceProvider**

In `app/Providers/AppServiceProvider.php`, delete the two imports (`use App\Services\Procurement\Contracts\MarketplaceRechecker;` and `use App\Services\Procurement\FixtureMarketplaceRechecker;`, lines ~25-26) and the two bindings (lines ~76-77):

```php
        $this->app->singleton(FixtureMarketplaceRechecker::class);
        $this->app->singleton(MarketplaceRechecker::class, fn ($app) => $app->make(FixtureMarketplaceRechecker::class));
```

- [ ] **Step 5: Delete the dead controller actions**

- `app/Http/Controllers/QuoteController.php`: delete the `procure()` and `confirmStock()` actions.
- `app/Http/Controllers/ProcurementController.php`: delete the `index()` and `reconfirm()` actions. Keep `buyList`, `markBought`, `markProductBought`, and the `BuyListQuery`/`QuoteService` constructor deps. Remove imports now unused only by the deleted actions (`ReconfirmLineItemRequest`, and `LineItemState` if only `index()` used it).

- [ ] **Step 6: Delete the dead routes**

In `routes/api.php`, delete these four lines:

```php
    Route::post('/quotes/{quote}/procure', [QuoteController::class, 'procure'])->middleware('permission:quotes.edit');
    Route::post('/quotes/{quote}/confirm-stock', [QuoteController::class, 'confirmStock'])->middleware('permission:quotes.edit');
    Route::get('/procurement/awaiting-reconfirm', [ProcurementController::class, 'index'])->middleware('permission:procurement.view');
    Route::post('/line-items/{lineItem}/reconfirm', [ProcurementController::class, 'reconfirm'])->middleware('permission:procurement.manage');
```

Keep `/procurement/buy-list`, `/line-items/{lineItem}/mark-bought`, `/procurement/buy-list/mark-product/{product}`.

- [ ] **Step 7: Delete the broadcast channel**

In `routes/channels.php`, delete the `Broadcast::channel('staff.procurement', ...)` block (and its comment, lines ~30-34).

- [ ] **Step 8: Grep for stragglers**

Run: `grep -rnE "CoreProcurement|ScrapedUvProcurement|Model3dProcurement|ProcurementStrategy|MarketplaceRechecker|ProcurementResult|ProcurementOutcome|LineItemAwaitingReconfirm|ReconfirmLineItemRequest|->procure\(|reconfirmLine|->confirmStock\(|awaiting-reconfirm|staff\.procurement" app routes`
Expected: no output (all references gone). Fix any straggler.

- [ ] **Step 9: Run the full backend suite**

Run: `php artisan test`
Expected: PASS. If a test errors on a missing class/route, it was a straggler from Task 1 — fix it here.

- [ ] **Step 10: Commit**

```bash
git add -A
git commit -m "refactor(procurement): delete the dormant legacy procurement engine"
```

---

### Task 3: Remove the frontend reconfirm-desk slice

**Files:**
- Modify: `frontend/src/stores/procurementStore.ts`, `frontend/src/stores/procurementStore.test.ts`

- [ ] **Step 1: Strip the reconfirm slice from the store**

In `frontend/src/stores/procurementStore.ts`, remove: the `alerts` state, `fetchAlerts`, `subscribe`, `unsubscribe`, `reconfirm`; the `ReconfirmAlert`, `ReconfirmedLine`, `ReconfirmOutcome`, `AwaitingReconfirmLine` interfaces; the module-level `procurementChannel`/`reconfirmListener` vars; and the `joinSharedPrivate`/`leaveSharedPrivate` echo import if now unused. Keep `BuyListRow`, `buyList`, `fetchBuyList`, `markBought`, `markProductBought`, and their interface entries.

- [ ] **Step 2: Remove the reconfirm tests from the store test**

In `frontend/src/stores/procurementStore.test.ts`, delete the tests exercising `fetchAlerts`/`reconfirm`/`subscribe`/`alerts` and the `alert()` helper + `ReconfirmAlert` import + the `echo` mock if now unused. Keep the three buy-list tests and the `get`/`post` api mock. Update the `beforeEach` `setState` to drop `alerts`.

- [ ] **Step 3: Typecheck + run the store test**

Run: `cd frontend && npx tsc --noEmit`
Expected: EXIT 0. If a component still imports a removed export, that component was part of the old desk — it was already replaced in #26; fix the import.

Run: `cd frontend && npx vitest run src/stores/procurementStore.test.ts`
Expected: PASS.

- [ ] **Step 4: Full frontend suite**

Run: `cd frontend && npx vitest run`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
cd "D:/work/NexGen/gift-lab"
git add frontend/src/stores/procurementStore.ts frontend/src/stores/procurementStore.test.ts
git commit -m "refactor(procurement): remove the frontend reconfirm-desk store slice"
```

---

## Self-Review Notes

- **Spec coverage:** strategies + support types (T2 s3), ProcurementManager trim (T2 s1), QuoteService methods (T2 s2), controllers/routes/event/request/channel (T2 s5-7), provider bindings (T2 s4), frontend slice (T3), all test handling (T1). All spec deletions have a task.
- **Kept, verified untouched:** `markBought`/`onProcured`, `tryQueue`/`isReadyForProduction`/`cancelIfNothingLeftToProduce`, `markLineBought`/`markQuoteBought`/`markProductBought`, stock ledger, cancel-refund, `ProductClass`, `AwaitingReconfirm` enum case, supplier-reorder + filament (out of scope).
- **Ordering safety:** tests pruned first (T1) so T2's deletions land on a suite that no longer asserts the removed behaviour; grep gate (T2 s8) catches stragglers.
- **No DB migrations** — pure code deletion, no behaviour change to any reachable path.
