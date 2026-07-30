# Features C+D Stage 2 — Shipment Entity — Design Spec

**Date:** 2026-07-30
**Supersedes** the high-level "STAGE 2" section of `docs/superpowers/specs/2026-07-30-giftlab-feature-cd-courier-production-design.md` with a concrete, buildable design.
**Owner decisions (confirmed):** (1) Shipment **derives** status from its member jobs — no own state machine. (2) **No `PRODUCED` job state** — keep the Stage-1 soft make/ship seam. (3) Split-fee: one delivery fee by default; staff **explicitly add** a per-parcel surcharge on split.

---

## Problem

Today every courier field lives on `production_jobs` (`consignment_ref` UNIQUE, `carrier`, `label_url`, `last_courier_status`, `last_courier_status_at`, `delivered_at`), the tracking number is deterministic **per job** (`NinjaVanTrackingNumber::forJob(quoteId, jobId)`), and every "shipment" surface (`OrderTracker::shipments`, `QuoteResource` summary, `ProductionJobResource`, the broadcast) is derived live from job rows with `consignment_ref` not null. A multi-line order therefore books **one consignment per job** — split-by-default. The owner wants **one shipment per order by default**, staff able to split per item. That needs a real grouping entity.

## Target model

- New table **`shipments`**: `id`, `quote_id` (fk, cascade), `consignment_ref` (string 128, nullable, **unique**), `carrier` (string 32, nullable, cast `Carrier`), `label_url` (string 2048, nullable), `last_courier_status` (string 255, nullable), `last_courier_status_at` (timestamp nullable), `delivered_at` (timestamp nullable), timestamps. **No `state` column** — status derives from member jobs.
- **`production_jobs.shipment_id`** (fk, nullable, `nullOnDelete`). Many jobs → one shipment.
- The 6 courier fields **move off `production_jobs`** onto `shipments`.
- Relations: `Quote hasMany shipments`; `Shipment belongsTo quote`, `hasMany jobs (ProductionJob)`; `ProductionJob belongsTo shipment`.
- Tracking number: **`NinjaVanTrackingNumber::forShipment(int $quoteId, int $shipmentId)`** (keyed off shipment id; keep `forJob` only if still referenced, else remove).

## Phased build (each phase = own plan, suite green at every commit)

### Phase 2a — Introduce entity, relocate fields (behavior-preserving, 1:1)

The large mechanical move. **No behavior change:** one shipment per job (1:1), so each job still books its own consignment — only the *storage location* of the courier fields changes.

- Migration: create `shipments`; add `production_jobs.shipment_id`; **backfill one shipment row per existing job that has any courier data (or per job), copy the 6 fields, set `shipment_id`**; then **drop the 6 courier columns + the unique index from `production_jobs`**. `down()` reverses (re-add columns, copy back, drop table).
- `buildJobsForQuote`: for each job created, create its own shipment (1:1) and set `shipment_id`. (Grouping to one-per-quote is Phase 2b.)
- Repoint (per the touch-point map in the plan): `ShipmentService::createForJob` writes the job's shipment; `QueueService::advance` (SHIPPED write), `inTransit`, `needsAttention`, `markDelivered`, `resolveReturnClose/Reship/CancelCredit` read/write via `$job->shipment`; `NinjaVanWebhookController::findJobForTrackingNumber` → find the **shipment** by `consignment_ref` (exact + unambiguous suffix) and act on its job(s); `OrderTracker::shipments`, `ProductionJobResource`, `QuoteResource` summary, `OrderTrackingUpdated` read shipment fields; `NinjaVanTrackingNumber::forShipment`.
- Milestone "on its way" dedup (M19) stays keyed on the quote's jobs (unchanged in 2a since 1:1).
- Tests: update the ~54 "all-courier" tests + others to assert courier fields on the shipment (via `$job->shipment` / the shipment row). Add a migration-backfill test (existing shipped job → one shipment row, webhook still resolves). Full suite green.

### Phase 2b — Default grouping (the inversion)

- `buildJobsForQuote`: create **one** shipment per quote, assign all the quote's jobs to it.
- Booking: `createForShipment(Shipment)` books **one** NinjaVan consignment (`forShipment`) covering all member jobs; advances **every member job** to SHIPPED; writes the courier fields on the shipment once.
- Webhook: a delivered event on `shipments.consignment_ref` closes **all** member jobs (each SHIPPED→CLOSED; quote closes when all jobs closed — existing logic, now driven for every member).
- `markDelivered` / `resolveReturn` operate at **shipment** granularity (a returned parcel = a shipment; reship re-queues all its jobs and clears the shipment's courier footprint).
- Surfaces: Ship desk / In-transit / Needs-attention become **shipment-grouped** (backend returns shipments with their member jobs; the UI lists one card per shipment). Make queue stays job-level.
- Milestone dedup: naturally per-shipment (still one "on its way" email per order — first shipment to ship).
- Tests: combined-by-default (1 consignment for N jobs); webhook closes all member jobs; grouped surfaces.

### Phase 2c — Split control + delivery surcharge

- Backend: endpoint to **move a job into its own new shipment** (split), allowed pre-booking; refuses once the job's shipment is booked. Re-groups the UI.
- Delivery surcharge: `quotes.delivery` is a weight-tiered decimal column today (`PricingService::deliveryFor`). On split, **surface** the added parcel's courier cost and let staff **explicitly add** a delivery bump (raise `quotes.delivery` via the existing amend path, audited) — never auto-applied.
- Tests: split → N shipments, N consignments on booking; staff surcharge raises the delivery total via amend; no auto-charge.

## Non-goals / invariants

- Public tracker keeps its email gate + PII-free payload (unchanged).
- Webhook stays **fail-closed on signature** and keyed on `consignment_ref` (now the shipment's) — no order id / reference / job id ever resolves a parcel (Stage-1 guard test generalizes to the shipment).
- TOCTOU lock + monotonic courier-status guard + event idempotency preserved (now on the shipment row / its member jobs).
- No change to `JobState` (soft seam kept; `RETURNED` reused).

## Open questions (resolve in the relevant phase plan)

1. 2a backfill: create a shipment for **every** job, or only jobs with courier data? (Recommend: every job, so `shipment_id` is always set and grouping in 2b is uniform.)
2. 2b: when the quote's single shipment is partially split later (2c), how the "one on-its-way email per order" dedup composes (still first-shipment-to-ship per quote).
3. 2c: surcharge as a raise to `quotes.delivery` vs a distinct fee line — recommend raising `delivery` via amend (fewer surfaces), decided in the 2c plan after reviewing the amend path.
