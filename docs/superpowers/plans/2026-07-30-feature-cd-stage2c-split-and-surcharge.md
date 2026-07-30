# Stage 2c — Split Shipment + Delivery Surcharge Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Let staff split an order's items into separate shipments (each books its own consignment), make the multi-shipment `cancel_credit` path correct, and surface a delivery surcharge on split (applied via the existing amend path — no new post-confirm pricing mechanism).

**Architecture:** A staff "ship this item separately" action moves a job out of its (unbooked) shipment into a new shipment; booking each shipment then produces a distinct consignment (Stage 2b's `createForShipment` already books per shipment). The `resolveReturnCancelCredit` "sibling still live" branch — dead in 2b (one shipment/order) — becomes reachable, so `QuoteService::returnParcel` is reworked from job-scope to **shipment-scope** (restock + proportional credit across all the returned shipment's lines). The surcharge is surfaced in the UI as an informational prompt; the actual delivery bump uses the existing `PATCH /quotes/{quote}/amend` (superadmin post-confirm), audited — 2c does not add a new staff-facing post-confirm delivery editor.

**Tech Stack:** Laravel 11 / PHP 8.3 / Pest v3; React + TS + Zustand + Vitest.

**Depends on:** Stage 2a + 2b (branch `feature/shipment-entity`).

**Key current-state facts:**
- One shipment per order (2b); `ProductionJobResource` exposes `shipment_id`; ship desk dedupes to one card per shipment (`uniqueByShipment` in `ProductionQueuePage.tsx`).
- `ShipmentService::createForShipment` books one consignment per shipment for all member jobs.
- `QueueService::resolveReturnCancelCredit` (`app/Services/QueueService.php` ~:730): `siblingStillLive` = the quote has a non-Returned job NOT in this shipment's `memberJobIds`; true → `QuoteService::returnParcel($quote, $job, $note)` (currently job-scoped, restocks/credits only `$job`'s lines — the flagged rework); false → whole-order `cancel`.
- `QuoteService::returnParcel(Quote, ProductionJob, ?reason)` (`:1280`): job-scoped restock + proportional credit + job→Returned.
- `QuoteService::amend(Quote, lineAmendments, ?delivery, ?notes, removedLineIds, ?adjustments, ?remark)` (`:320`): DRAFT-only unless superadmin; sets `quotes.delivery` and re-anchors the invoice. Route `PATCH /quotes/{quote}/amend` (`permission:quotes.edit`).

---

### Task 1: Split a job into its own shipment (backend)

**Files:** `app/Services/QueueService.php` (new `splitJobToOwnShipment`); `app/Http/Controllers/ProductionQueueController.php`; `routes/api.php`; Test: `tests/Feature/ShipmentSplitTest.php`.

- [ ] **Step 1: Failing test** (`tests/Feature/ShipmentSplitTest.php`)

Build a 2-job-one-shipment order (reuse the ShipmentGroupingTest fixture). Split one job. Assert: the order now has 2 shipments; the split job is alone on a new shipment; the other job stays on the original; both shipments unbooked. Then a negative: splitting a job whose shipment is already booked (has a consignment_ref) → 422 `DomainRuleException`; and splitting the only job of a single-job shipment → 422 (nothing to separate).

```php
it('moves a job into its own new shipment', function (): void {
    // ... 2-job order sharing one shipment ...
    $original = $jobA->shipment_id;
    $newShipment = app(QueueService::class)->splitJobToOwnShipment($jobA);

    expect($jobA->fresh()->shipment_id)->toBe($newShipment->id)->not->toBe($original)
        ->and($jobB->fresh()->shipment_id)->toBe($original)
        ->and($quote->fresh()->shipments()->count())->toBe(2);
});
```

Run `php artisan test --filter=ShipmentSplit` → FAIL.

- [ ] **Step 2: Service method**

```php
/**
 * Move a job out of its shipment into a fresh one so it ships as a separate
 * parcel (its own consignment). Only allowed while the current shipment is
 * unbooked (no consignment_ref) - splitting after booking would strand the
 * parcel - and only when the shipment groups more than one job (a lone job is
 * already its own parcel). The new shipment inherits nothing but the quote.
 */
public function splitJobToOwnShipment(ProductionJob $job): Shipment
{
    $shipment = $job->shipment;
    if ($shipment === null) {
        // No group to split from - give it its own shipment idempotently.
        $fresh = Shipment::create(['quote_id' => $job->quote_id]);
        $job->shipment()->associate($fresh)->save();
        return $fresh;
    }
    if ($shipment->consignment_ref !== null) {
        throw new DomainRuleException('This shipment is already booked and cannot be split.');
    }
    if ($shipment->jobs()->count() < 2) {
        throw new DomainRuleException('This item is already its own shipment.');
    }

    return DB::transaction(function () use ($job, $shipment): Shipment {
        $fresh = Shipment::create(['quote_id' => $shipment->quote_id]);
        $job->shipment()->associate($fresh)->save();
        DB::afterCommit(fn () => Broadcasting::dispatch(fn () => ProductionQueueUpdated::dispatch($job->fresh(), 'started')));
        return $fresh;
    });
}
```

- [ ] **Step 3: Controller + route**

Controller:
```php
public function split(Request $request, ProductionJob $job): ProductionJobResource
{
    $this->authorize('manageProduction', Quote::class);
    $this->queue->splitJobToOwnShipment($job);
    return new ProductionJobResource($job->fresh()->load('shipment'));
}
```
Route (near the other `/production-jobs/{job}/...` POSTs), `permission:production.manage`:
```php
Route::post('/production-jobs/{job}/split', [ProductionQueueController::class, 'split'])->middleware('permission:production.manage');
```

- [ ] **Step 4:** `php artisan test --filter=ShipmentSplit` → PASS; `--filter="CreateShipment|ProductionQueue"` green (splitting then booking each shipment yields 2 consignments — add that assertion to the split test if convenient).

- [ ] **Step 5: Commit**
```bash
git add app/Services/QueueService.php app/Http/Controllers/ProductionQueueController.php routes/api.php tests/Feature/ShipmentSplitTest.php
git commit -m "feat(shipment): split a job into its own shipment (staff, pre-booking)"
```

---

### Task 2: Multi-shipment cancel_credit correctness (returnParcel → shipment scope)

**Files:** `app/Services/QuoteService.php` (`returnParcel`), `app/Services/QueueService.php` (`resolveReturnCancelCredit` call site); Test: `tests/Feature/ReturnResolutionTest.php`.

- [ ] **Step 1: Failing test**

Build an order split into 2 shipments (shipment A = 1 job, shipment B = 1 job), both booked to SHIPPED, invoice paid. Flag shipment A returned; `resolveReturn(jobA, 'cancel_credit')`. Assert: the order stays LIVE (not cancelled) because shipment B still stands; exactly ONE credit note for shipment A's proportional value; only shipment A's lines restocked; jobA RETURNED, jobB still SHIPPED. (This exercises the previously-dead `siblingStillLive` branch, now reachable via split.)

Also add: a split order where BOTH shipments are returned and cancel_credit is run on the last remaining one → falls back to whole-order cancel (existing behavior).

- [ ] **Step 2: Rework `returnParcel` to shipment scope**

Change signature to operate on a shipment's whole line set:
```php
public function returnParcel(Quote $quote, Shipment $shipment, ?string $reason): void
```
- Aggregate the lines of ALL member jobs of the shipment: `$shipment->loadMissing('jobs.lineItems.variant', 'jobs.lineItems.product'); $parcelLines = $shipment->jobs->flatMap->lineItems;`
- `$parcelLinesValue` = sum of `lineSubtotalContribution` over `$parcelLines`; `$fraction` = parcel/all as today.
- Restock `$parcelLines` (stock + filament) — all member jobs' lines, not one.
- Credit proportional slice ONCE (same invoice loop), audit `scope => 'parcel'`, `shipment_id => $shipment->id`.
- Transition EVERY member job to `JobState::Returned`.

Update the `resolveReturnCancelCredit` call site: the `siblingStillLive` branch calls `app(QuoteService::class)->returnParcel($quote, $job->shipment, $note)` (pass the shipment). The whole-order `cancel` branch is unchanged. Remove/adjust the now-stale "NEEDS REWORK in 2c" comment — it's reworked.

> Keep the single-job path correct: a 1-job shipment returnParcel behaves exactly as the old job-scoped version (its member set is one job).

- [ ] **Step 3:** `php artisan test --filter=ReturnResolution` → PASS (incl. the existing single-shipment cancel_credit and the new 2-shipment isolation). Full `php artisan test` → green.

- [ ] **Step 4: Commit**
```bash
git add app/Services/QuoteService.php app/Services/QueueService.php tests/Feature/ReturnResolutionTest.php
git commit -m "feat(shipment): scope cancel_credit returnParcel to the whole returned shipment"
```

---

### Task 3: Frontend — split action + surcharge hint

**Files:** `frontend/src/stores/queueStore.ts`, `frontend/src/pages/ProductionQueuePage.tsx`, tests.

- [ ] **Step 1: Store action**

`queueStore`: add `splitShipment(jobId: number): Promise<boolean>` → `POST /production-jobs/{jobId}/split`, then `fetchQueue({silent:true})`; toast/return pattern like `resolveReturn`.

- [ ] **Step 2: Ship-desk card — list member items + "Ship separately"**

On the Ship desk, a card represents a shipment (deduped). Show its member jobs (find siblings in the store by `shipment_id`): for each member job beyond the first, offer a "Ship separately" button that calls `splitShipment(memberJobId)`. Only when the shipment has >1 member and is unbooked. After a split, the board refetches and the item becomes its own card.

- [ ] **Step 3: Surcharge hint**

When a shipment has been split (the order shows >1 shipment on the ship desk), show an informational note on the card: e.g. "Shipping as N separate parcels — a manager can add a delivery charge via order amend." Purely informational (the surcharge is applied through the existing amend flow, superadmin). No new pricing control here.

- [ ] **Step 4: Tests**

`ProductionQueuePage.test.tsx`: two IN_PRODUCTION jobs sharing a `shipment_id` render one ship-desk card that offers a "Ship separately" action for the second item; clicking it calls `splitShipment(secondJobId)`. Add a `queueStore` test for `splitShipment` posting the right URL.

- [ ] **Step 5:** `cd frontend && npx vitest run && npx tsc --noEmit` → green.

- [ ] **Step 6: Commit**
```bash
git add frontend/src/stores/queueStore.ts frontend/src/pages/ProductionQueuePage.tsx frontend/src/pages/ProductionQueuePage.test.tsx frontend/src/stores/queueStore.test.ts
git commit -m "feat(production): ship-separately split action + surcharge hint"
```

---

### Task 4: Full regression + live verify + finish

- [ ] **Step 1:** `php artisan test` (backend) + `cd frontend && npx vitest run && npx tsc --noEmit` → all green.
- [ ] **Step 2: Live** (best-effort, staff login permitting): a 2-item order → one shipment; "ship separately" → two shipments; booking each → two consignments; returning one → only that parcel credited, order stays live.
- [ ] **Step 3:** Final full-branch review, then `superpowers:finishing-a-development-branch`.

---

## Self-Review

- **Split** (T1): move an unbooked job to its own shipment; guards on booked/lone-job; each shipment books its own consignment via 2b's `createForShipment`.
- **cancel_credit multi-shipment** (T2): `returnParcel` reworked to shipment scope so the now-reachable `siblingStillLive` branch credits/restocks the whole returned shipment and leaves the order live; single-shipment behavior unchanged.
- **Surcharge** (T3): surfaced as a hint; applied via the existing audited amend path — deliberately no new post-confirm pricing mechanism (out of scope, risk).
- **Invariants:** booking/webhook/milestone/idempotency untouched from 2a/2b; split only pre-booking so no consignment is ever stranded.

## Open questions / notes
- If the owner wants a dedicated staff (non-superadmin) post-confirm delivery-surcharge control, that's a follow-up (new audited pricing path); 2c intentionally reuses amend.
