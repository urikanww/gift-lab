# Catalogue-Gate Improvements (Fresh Redo) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`).

**Goal:** Reimplement the 4 catalogue-gate improvements from the stale branch (commit `16c5849`) fresh on current master, adapted to current code (permission middleware, current schema).

**Reference:** `git show 16c5849 -- <path>` shows the OLD intent for any file — read it for behaviour, but re-implement against CURRENT master (do NOT cherry-pick the old diff; it's 300 commits behind). Gap analysis confirmed all 4 parts are missing on the gate surface.

**The 4 parts + 2 cross-cutting bits:**
1. **Stock waiver:** `CompletenessGate` must not flag `stock_unreadable` for non-STOCKED (make-to-order) blanks — a null estimate is expected there; still gate STOCKED items.
2. **Add-as-blank → fix-popup:** after "Add as blank" commits the draft, open `ResolveBlockersModal` seeded with the gate verdict `add()` returns; `add()` also accepts/validates a `stock_estimate`.
3. **Hide published:** gate `index()` excludes published from the list AND the counts; frontend drops the Published badge, the PUBLISHED filter option, and the Unpublish button/store action.
4. **In-gate delete:** `DELETE /admin/catalogue/{product}` soft-deletes an UNPUBLISHED product (refuses published, 422); `POST /admin/catalogue/bulk-delete` soft-deletes selected unpublished rows (skips published, reports skipped). Gate row checkboxes select ANY row; publish uses the READY subset, delete uses the whole selection. Use `permission:products.edit` middleware (match Product Admin delete), NOT inline isStaff.
- **X1 (cross-cutting):** gate row payload emits `stock_estimate`; `resolveBlockers` accepts a `stock_estimate` (manual fixable, needed by parts 1/2).

**Current anchors (from gap analysis):**
- `app/Services/Catalogue/CompletenessGate.php:39-41` (unconditional `stock_unreadable`); `App\Enums\StockMode`, `Product.php:52,93` (`stock_mode` → `StockMode`).
- `AdminCatalogueController::index()` — `$byState` :95-102, paginator :119-130, `counts` emits `'published'` :108, row transform :132-155; `resolveBlockers` :226-235; `unpublish` :282.
- `routes/api.php:221-248` (`/admin/catalogue*`); Product Admin delete precedent: `routes/api.php:260,266`, `AdminProductController::bulkDestroy` :297, `destroy` :841 (soft-delete + `product.archived` audit, `restore` route :268).
- `AdminBlankRecommendationController::add()` returns only `id`+`publish_state` :101; hardcodes `stockEstimate: null` :87.
- Frontend: `CatalogueAdminPage.tsx` (Published badge :455, PUBLISHED filter :515, Unpublish button :734-742, `eligibleIds`=READY only :354/:380-392); `catalogueAdminStore.ts` (`unpublish` :76/:135); `BlankRecommendationPage.tsx:102-107` (addBlank then stop); `ResolveBlockersModal.tsx:35` (takes `product: AdminCatalogueItem`).

**Tech Stack:** Laravel 11 / PHP 8.3 / Pest v3; React + TS + Zustand + Vitest.

---

### Task 1: Part 1 — make-to-order stock waiver (CompletenessGate)

**Files:** `app/Services/Catalogue/CompletenessGate.php`; Test: `tests/Feature/` (find the existing gate test — `CompletenessGateTest` or `AdminCatalogueTest`; add there).

- [ ] Step 1 — failing test: a MAKE_TO_ORDER/non-STOCKED product with `stock_estimate === null` must NOT carry `stock_unreadable`; a STOCKED product with null estimate STILL carries it. Confirm the exact `StockMode` enum cases (`git show` the enum: `app/Enums/StockMode.php`) — likely `Stocked` vs `MakeToOrder`.
- [ ] Step 2 — implement: guard the `stock_unreadable` push with `$product->stock_mode === StockMode::Stocked` (only STOCKED items require a readable estimate). Import `StockMode`.
- [ ] Step 3 — run the gate test filter → green; run `--filter="Catalogue|Gate|Completeness"` → green.
- [ ] Step 4 — commit `feat(catalogue-gate): waive stock_unreadable for make-to-order blanks`.

---

### Task 2: Part 3 backend — exclude published from gate list + counts

**Files:** `app/Http/Controllers/AdminCatalogueController.php` (`index`); Test: `tests/Feature/AdminCatalogueTest.php`.

- [ ] Step 1 — failing test: seed a published product + an unpublished one; GET `/admin/catalogue` → the list excludes the published product AND the `counts` breakdown does not count it (and drops the `published` count key). Match the current test file's auth/seed pattern.
- [ ] Step 2 — implement: add a `where('publish_state', '!=', PublishState::Published->value)` (confirm the enum/column name from the Product model) to BOTH the `$byState` counts query and the paginator query in `index()`. Remove the `'published'` entry from the emitted `counts`. (Leave `unpublish` controller method intact for now — it's dropped from the UI in Task 5; removing the backend method is optional cleanup, do it only if nothing else references it.)
- [ ] Step 3 — run → green; `--filter=AdminCatalogue` green.
- [ ] Step 4 — commit `feat(catalogue-gate): exclude published products from gate list + counts`.

---

### Task 3: Part 4 backend — in-gate soft-delete + bulk-delete

**Files:** `app/Http/Controllers/AdminCatalogueController.php` (add `destroy`, `bulkDestroy`); `routes/api.php`; Test: `tests/Feature/AdminCatalogueTest.php`.

- [ ] Step 1 — failing tests:
  - `DELETE /admin/catalogue/{product}` on an UNPUBLISHED product → soft-deletes it (row gone from index, `trashed()` true), audited; on a PUBLISHED product → 422 with a clear message, NOT deleted.
  - `POST /admin/catalogue/bulk-delete` `{ids:[...]}` mixing published + unpublished → deletes the unpublished, SKIPS published, returns `{deleted:[...], skipped:[...]}` (or similar). Staff-permitted; a viewer-only user forbidden.
  Reference `git show 16c5849 -- app/Http/Controllers/AdminCatalogueController.php` for the old shape, and `AdminProductController::destroy/bulkDestroy` for the current soft-delete + audit idiom (reuse the same soft-delete mechanism + an audit event, e.g. `product.gate_deleted`).
- [ ] Step 2 — implement `destroy(Product $product)` (guard `publish_state === Published` → `throw DomainRuleException`/422; else `$product->delete()` + audit) and `bulkDestroy(Request)` (validate `ids` array; delete unpublished, collect skipped published; audit each). Routes (permission:products.edit), registered so the literal `/admin/catalogue/bulk-delete` precedes `/admin/catalogue/{product}`:
  ```php
  Route::post('/admin/catalogue/bulk-delete', [AdminCatalogueController::class, 'bulkDestroy'])->middleware('permission:products.edit');
  Route::delete('/admin/catalogue/{product}', [AdminCatalogueController::class, 'destroy'])->middleware('permission:products.edit');
  ```
- [ ] Step 3 — run → green; full `--filter=AdminCatalogue` green.
- [ ] Step 4 — commit `feat(catalogue-gate): in-gate soft-delete + bulk-delete (refuse/skip published)`.

---

### Task 4: X1 + Part 2 backend — stock_estimate payload + add() returns verdict

**Files:** `app/Http/Controllers/AdminCatalogueController.php` (`index` row transform, `resolveBlockers`), `app/Http/Controllers/AdminBlankRecommendationController.php` (`add`); Tests: `AdminCatalogueTest`, `AdminBlankRecommendationTest` (or wherever `add()` is tested).

- [ ] Step 1 — failing tests:
  - gate row payload includes `stock_estimate`; `resolveBlockers` accepts a `stock_estimate` field and persists it (so a null-estimate STOCKED item becomes fixable).
  - `AdminBlankRecommendationController::add()` returns the gate verdict for the new product — its `cannot_publish_reasons` (and whatever the modal needs: `base_cost`/`publish_state`) — not just `id`; and `add()` accepts/validates an optional `stock_estimate` instead of hardcoding null.
- [ ] Step 2 — implement: add `stock_estimate` to the `index()` row transform; add a `stock_estimate` rule to `resolveBlockers` + persist it. In `add()`, accept optional `stock_estimate` (validate numeric/nullable), pass it through instead of the hardcoded null, and after creating the product return the gate verdict (recompute via `CompletenessGate` or reload `cannot_publish_reasons`). Reference `git show 16c5849` for the exact verdict shape the modal expects.
- [ ] Step 3 — run → green.
- [ ] Step 4 — commit `feat(catalogue-gate): expose stock_estimate + return gate verdict on add-as-blank`.

---

### Task 5: Part 3 frontend — drop Published badge/filter + Unpublish

**Files:** `frontend/src/pages/CatalogueAdminPage.tsx`, `frontend/src/stores/catalogueAdminStore.ts`; Tests: `frontend/src/pages/CataloguePage.test.tsx` or the admin page test.

- [ ] Step 1 — failing/updated test: the gate no longer renders a Published badge, a PUBLISHED filter option, or an Unpublish button. Adjust existing assertions that expect them.
- [ ] Step 2 — implement: remove the Published `Badge` (:455), the `PUBLISHED` filter option (:515), the Unpublish button (:734-742), and the `unpublish` store action (:76/:135) + its usage. (Backend `unpublish` route can stay dead or be removed — coordinate with Task 2's note.)
- [ ] Step 3 — `cd frontend && npx vitest run <test> && npx tsc --noEmit` → green.
- [ ] Step 4 — commit `feat(catalogue-gate): remove Published badge/filter + Unpublish from the gate`.

---

### Task 6: Part 4 frontend — delete + bulk-delete UI

**Files:** `frontend/src/stores/catalogueAdminStore.ts`, `frontend/src/pages/CatalogueAdminPage.tsx`; Tests: the store test + page test.

- [ ] Step 1 — failing tests: store `deleteProduct(id)` → `DELETE /admin/catalogue/{id}`; `bulkDelete(ids)` → `POST /admin/catalogue/bulk-delete {ids}`. Page: a row's delete action calls `deleteProduct`; a bulk-delete action over selected rows calls `bulkDelete`; row checkboxes select ANY row (publish action still uses only the READY subset, delete uses the whole selection).
- [ ] Step 2 — implement store actions (mirror the existing `publish`/`unpublish` action shape: ensureCsrf, post/delete, refetch, toast) and the UI: a per-row Delete (confirm), a bulk "Delete selected" button; change `eligibleIds` usage so selection isn't limited to READY (publish still filters to READY internally, delete uses the full selected set). Confirm dialog for deletes.
- [ ] Step 3 — `cd frontend && npx vitest run && npx tsc --noEmit` → green.
- [ ] Step 4 — commit `feat(catalogue-gate): in-gate delete + bulk-delete UI`.

---

### Task 7: Part 2 frontend — add-as-blank opens the fix-popup

**Files:** `frontend/src/pages/BlankRecommendationPage.tsx` (+ its store if any); Test: the page test.

- [ ] Step 1 — failing test: after "Add as blank" resolves, `ResolveBlockersModal` opens seeded with the added product (from `add()`'s verdict), instead of just toasting and stopping.
- [ ] Step 2 — implement: `addBlank(c)` returns the created product/verdict; on success set modal state to open with that product (shape it as the `AdminCatalogueItem` the modal expects — map `add()`'s response). Keep the toast. On modal save, refetch/close.
- [ ] Step 3 — `cd frontend && npx vitest run && npx tsc --noEmit` → green.
- [ ] Step 4 — commit `feat(catalogue-gate): open the fix-popup after add-as-blank`.

---

### Task 8: Full regression + finish

- [ ] `php artisan test` + `cd frontend && npx vitest run && npx tsc --noEmit` → all green.
- [ ] Live (best-effort, staff login permitting): gate hides published; a make-to-order blank isn't stock-flagged; add-as-blank opens the fix popup; delete refuses a published item, bulk-delete skips published.
- [ ] Final full-branch review, then `superpowers:finishing-a-development-branch`.

---

## Self-Review
- All 4 parts + X1 covered (T1 stock waiver, T2/T5 hide published, T3/T6 delete, T4/T7 add-as-blank + stock_estimate). Backend before frontend so the API exists when the UI calls it.
- Uses current conventions: `permission:products.*` middleware, current soft-delete/audit idiom, current enums (StockMode, PublishState) — NOT the 300-commit-old patterns.
- Green at each task boundary; each part isolated.
