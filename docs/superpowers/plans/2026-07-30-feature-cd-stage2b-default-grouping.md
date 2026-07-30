# Stage 2b — Default One-Shipment-Per-Order Grouping Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Invert the default from one-consignment-per-job to **one shipment per order**: all of a quote's jobs share one shipment; booking books a single consignment covering them all; delivery/return act on the whole shipment.

**Architecture:** `buildJobsForQuote` creates ONE shipment per quote (was 1:1 per job). `ShipmentService::createForShipment` books a single consignment and advances every member job to SHIPPED, writing the courier fields once on the shipment. The webhook already closes every SHIPPED member job (Stage 2a made it a loop). `markDelivered`/`resolveReturn` cascade to all member SHIPPED jobs. The in-transit and needs-attention surfaces dedupe to one representative job per shipment; the resource exposes `shipment_id` so the client can group. Milestone "on its way" already dedupes to one email per order.

**Tech Stack:** Laravel 11 / PHP 8.3 / Pest v3; React + TS + Zustand + Vitest.

**Depends on:** Stage 2a (commit on branch `feature/shipment-entity`). Build on that branch.

**Key current-state facts (post-2a):**
- `QueueService::buildJobsForQuote` creates a `Shipment` per job inside the job loop (`app/Services/QueueService.php:101-102`).
- `ShipmentService::createForJob` (`app/Services/ShipmentService.php:31`) books per-job; weight via `weightKgForJob`. `advance($job, Shipped, ref, carrier, labelUrl)` writes the job's shipment.
- `markDelivered(ProductionJob)` and `resolveReturn(ProductionJob, disposition)` (`QueueService.php:465`, `:585`) act on a single job (reading/writing its shipment).
- `inTransit()`/`needsAttention()` (`QueueService.php:543`,`:569`) return one row per SHIPPED job.
- Webhook (`NinjaVanWebhookController`) resolves a shipment and already loops `each SHIPPED member job → Closed`.
- Milestone M19 dedup (`QueueService.php` ~:313-336) keys on the quote's other shipped/closed jobs → already one email per order.
- `ProductionQueueController::createShipment` (`app/Http/Controllers/ProductionQueueController.php:176`) calls `shipment->createForJob($job)`; route `POST /production-jobs/{job}/create-shipment`.
- Frontend: `queueStore.createShipment(jobId)`, `markDelivered(jobId)`, `resolveReturn(jobId, ...)`; ship desk books from an IN_PRODUCTION job card; `AwaitingDeliveryPanel`/`NeedsAttentionPanel` list per-job.

---

### Task 1: One shipment per quote at build time

**Files:** `app/Services/QueueService.php` (`buildJobsForQuote`); Test: `tests/Feature/ShipmentGroupingTest.php`.

- [ ] **Step 1: Failing test**

```php
<?php
declare(strict_types=1);

use App\Models\Quote;
use App\Services\QueueService;
// Build a quote with >1 job-producing line (one UV + one 3D) via the repo's
// existing helper/factory pattern (mirror ProductionQueueTest's quote setup).

it('groups all of an order''s jobs into a single shipment', function (): void {
    $quote = /* a confirmed quote whose lines produce 2+ jobs */;
    $jobs = app(QueueService::class)->buildJobsForQuote($quote);

    expect($jobs)->toHaveCount(2)
        ->and($jobs->pluck('shipment_id')->unique())->toHaveCount(1)
        ->and($quote->fresh()->shipments()->count())->toBe(1);
});
```
Run: `php artisan test --filter=ShipmentGrouping` → FAIL (currently 2 shipments).

> Reuse `ProductionQueueTest`/`SlimQuoteFlowTest` setup to get a quote that produces 2 jobs (one UV-track line + one MODEL_3D line). If that scaffolding is heavy, build the two jobs through the same path those tests use.

- [ ] **Step 2: Implement — one shipment before the loop**

In `buildJobsForQuote`, move the shipment creation OUT of the per-group loop. Inside the `DB::transaction`, before `foreach ($groups as $lines)`, create one shipment:
```php
$shipment = Shipment::create(['quote_id' => $quote->id]);
```
Then in the loop, replace the per-job `Shipment::create(...) + associate` with:
```php
$job->shipment()->associate($shipment)->save();
```
Update the stale 2a comment accordingly ("one shipment per order — every job on this quote shares it").

- [ ] **Step 3:** `php artisan test --filter=ShipmentGrouping` → PASS. Run `--filter="ProductionQueue|SlimQuoteFlow|QuoteFlow"` → green.

- [ ] **Step 4: Commit**
```bash
git add app/Services/QueueService.php tests/Feature/ShipmentGroupingTest.php
git commit -m "feat(shipment): group all of an order's jobs into one shipment at build"
```

---

### Task 2: Book one consignment per shipment (all member jobs → SHIPPED)

**Files:** `app/Services/ShipmentService.php`, `app/Http/Controllers/ProductionQueueController.php`; Test: `tests/Feature/CreateShipmentTest.php` (extend).

- [ ] **Step 1: Failing test**

Extend `CreateShipmentTest`: a quote with 2 jobs on one shipment; book via the create-shipment endpoint for one of its jobs; assert (a) exactly ONE courier `createShipment` call happened (the fake courier records calls), (b) BOTH jobs are now SHIPPED, (c) the shipment has one `consignment_ref`. Mirror the file's existing courier-fake + endpoint-call setup.

- [ ] **Step 2: `createForShipment`**

Add `ShipmentService::createForShipment(Shipment $shipment): Shipment`:
- Idempotency: `if ($shipment->consignment_ref !== null) throw DomainRuleException('This shipment is already booked.');`
- Load member jobs: `$jobs = $shipment->jobs()->with('lineItems.product')->get();` — throw if empty.
- Address: `$quote = $shipment->quote; $addr = $quote->shippingAddress;` (same null guard + `assertShipToComplete`).
- State guard: every member job must be shippable — `if ($jobs->contains(fn($j) => ! $j->state->canTransitionTo(JobState::Shipped))) throw DomainRuleException('Every item in this shipment must be produced before it can ship.');`
- `$trackingNumber = NinjaVanTrackingNumber::forShipment((int)$quote->id, (int)$shipment->id);`
- Weight: `weightKgForShipment($shipment)` = sum of `weightKgForJob` logic across ALL member jobs (extract the per-job gram sum into a helper; fall back to config default if any product weight missing).
- Build `CourierShipment` (reference `(string)($quote->tracking_code ?? $quote->id)`, `parcelCount: 1`, the tracking number + weight), call `$this->courier->createShipment(...)`.
- In a `DB::transaction`: write `consignment_ref/carrier/label_url` onto `$shipment` and save; then advance EVERY member job to SHIPPED. Since the shipment already carries the consignment, call `$this->queue->advance($job, JobState::Shipped)` per job (no consignment args → advance won't re-write the shipment; it still audits/broadcasts and the milestone M19 dedup fires ONE email). Return the fresh `$shipment`.

Refactor `createForJob($job)` to delegate: `return $this->createForShipment($this->shipmentFor($job))` (or resolve the job's shipment and call through), preserving its `ProductionJob` return if any caller needs it — simplest: keep `createForJob` as a thin wrapper that books the job's shipment then returns `$job->fresh()`.

> `advance($job, Shipped)` with no consignment: confirm the Stage-2a `advance` only writes shipment fields when `$consignmentRef !== null`, and still fires the SHIPPED broadcast + milestone. If `advance` currently REQUIRES a consignment for the Shipped transition, relax it to allow the shipment already carrying one (guard: the job's shipment has a consignment_ref).

- [ ] **Step 3: Controller/route**

Keep `POST /production-jobs/{job}/create-shipment` (books the job's whole shipment now) for frontend compatibility; internally call `createForShipment`. Optionally add `POST /shipments/{shipment}/create-shipment`. The response shape stays (consignment_ref/carrier/tracking_url/label_url from the shipment).

- [ ] **Step 4:** `php artisan test --filter=CreateShipment` → PASS. `--filter="NinjaVanWebhook|ManualDelivery|ReturnResolution"` → green.

- [ ] **Step 5: Commit**
```bash
git add app/Services/ShipmentService.php app/Http/Controllers/ProductionQueueController.php tests/Feature/CreateShipmentTest.php
git commit -m "feat(shipment): book one consignment per shipment, shipping all member jobs"
```

---

### Task 3: Delivery + return cascade to the whole shipment; surfaces dedupe per shipment

**Files:** `app/Services/QueueService.php`, `app/Http/Resources/ProductionJobResource.php`; Tests: `ManualDeliveryTest`, `ReturnResolutionTest`, `NeedsAttentionSurfaceTest` (extend).

- [ ] **Step 1: Failing tests**
- Manual deliver: a 2-job shipment, mark one job delivered → BOTH jobs CLOSED (the parcel = the shipment), quote closes, ONE delivered email.
- Reship: a 2-job returned shipment, reship → BOTH member jobs back to IN_PRODUCTION, shipment courier footprint cleared once.
- Needs-attention/in-transit dedupe: a 2-job shipment flagged returned → the needs-attention list has ONE row for that shipment (not two).

- [ ] **Step 2: Cascade `markDelivered`**

Make `markDelivered(ProductionJob $job, ?string $note)` operate on the job's whole shipment: lock the shipment first (as today), then advance EVERY member job that is still SHIPPED to CLOSED (loop, each with its own re-read/lock + idempotent no-op). Stamp the manual marker on the shipment once. The quote-close + delivered-milestone fire from `advance`'s existing "all jobs closed" edge — now reached when the last member job closes. Keep the return type (return the passed job, freshened) for the controller/resource.

- [ ] **Step 3: Cascade `resolveReturn`**

`resolveReturn(ProductionJob $job, disposition, note)` → apply to all member jobs of `$job->shipment`:
- `close`: advance every SHIPPED member job → CLOSED.
- `reship`: clear the shipment footprint once (as today), then transition every member job → IN_PRODUCTION.
- `cancel_credit`: unchanged semantics but scoped to the shipment — the existing M15 sibling logic keys on the quote's other jobs; with one shipment per order, cancel_credit on the shipment cancels the order (or credits this shipment when a future split leaves siblings — 2c). For 2b, route through the existing `returnParcel`/`cancel` with the shipment's job set; ensure it doesn't double-credit across the shipment's jobs (credit once per shipment). Audit once per shipment.

> Keep each per-job transition inside the transaction with its lock; the cascade is a loop over the shipment's members. In 2b every order has one shipment, so this closes the whole order — matching "one parcel per order".

- [ ] **Step 4: Dedupe the surfaces + expose shipment_id**

- `ProductionJobResource`: add `'shipment_id' => $this->shipment_id,`.
- `inTransit()` / `needsAttention()`: return ONE representative job per shipment. Simplest: after the existing query `->get()`, `->unique('shipment_id')->values()` (a job with a null shipment_id stays as itself — legacy). Keep newest-first ordering (needsAttention already sorts in PHP; apply unique after the sort so the newest representative wins).

- [ ] **Step 5:** targeted filters green, then `php artisan test` full → green.

- [ ] **Step 6: Commit**
```bash
git add app/Services/QueueService.php app/Http/Resources/ProductionJobResource.php tests/Feature/ManualDeliveryTest.php tests/Feature/ReturnResolutionTest.php tests/Feature/NeedsAttentionSurfaceTest.php
git commit -m "feat(shipment): cascade deliver/return to the whole shipment; dedupe surfaces per shipment"
```

---

### Task 4: Frontend — shipment-aware surfaces

**Files:** `frontend/src/types.ts`, `frontend/src/pages/ProductionQueuePage.tsx` (Ship desk dedupe), `frontend/src/components/production/AwaitingDeliveryPanel.tsx` + `NeedsAttentionPanel.tsx` (copy), tests.

- [ ] **Step 1:** `types.ts` `ProductionJob`: add `shipment_id?: number | null`.
- [ ] **Step 2: Ship desk dedupe.** In `ProductionQueuePage`, the Ship-desk `boardJobs` (IN_PRODUCTION) should show ONE card per shipment so staff book once per order. Dedupe: `boardJobs = tab === 'ship' ? uniqueByShipment(jobs.filter(IN_PRODUCTION)) : ...` where `uniqueByShipment` keeps the first job per `shipment_id` (jobs with null shipment_id each stay). The "Create NinjaVan shipment" action already books the whole shipment (Task 2). Update the card copy for ship desk to say it ships the whole order's items.
- [ ] **Step 3:** In-transit + needs-attention panels already receive one row per shipment from the backend (Task 3 dedupe). Update copy to read "parcel/order" rather than "job" where it says job. No structural change.
- [ ] **Step 4: Tests.** Extend `ProductionQueuePage.test.tsx`: two IN_PRODUCTION jobs sharing `shipment_id` show ONE ship-desk card. Keep existing panel tests green (they use single-job fixtures — still one row).
- [ ] **Step 5:** `cd frontend && npx vitest run && npx tsc --noEmit` → green.
- [ ] **Step 6: Commit**
```bash
git add frontend/src/types.ts frontend/src/pages/ProductionQueuePage.tsx frontend/src/components/production/*.tsx frontend/src/pages/ProductionQueuePage.test.tsx
git commit -m "feat(production): ship-desk one card per shipment; shipment-aware surface copy"
```

---

### Task 5: Full regression + live verify

- [ ] **Step 1:** `php artisan test` → green (backend). `cd frontend && npx vitest run && npx tsc --noEmit` → green.
- [ ] **Step 2: Live** (`preview_start api/frontend`, http://localhost:5173): a multi-line order → one shipment; booking once ships all its jobs (one consignment); a returned webhook shows ONE needs-attention row; reship re-queues all its jobs. (Staff login required — if unavailable, rely on the suites, note it.)

---

## Self-Review

- **Inversion delivered:** one shipment/order (T1), one consignment booked for all jobs (T2), deliver/return cascade to the shipment (T3), surfaces dedupe per shipment (T3/T4). Webhook + milestone already per-shipment/order from 2a.
- **Green boundaries:** T1 self-contained (grouping); T2 booking; T3 cascade+dedupe; T4 frontend; T5 regression. Each commits green.
- **2c-ready:** `shipment_id` exposed; surfaces keyed on shipment; `createForShipment` is the split-aware booking entry point; cancel_credit already sibling-aware for the future partial-split case.
- **Invariants preserved:** one "on its way" email per order (M19); webhook fail-closed + TOCTOU + idempotency (2a); unique consignment on the shipment; `forShipment` deterministic per shipment.
