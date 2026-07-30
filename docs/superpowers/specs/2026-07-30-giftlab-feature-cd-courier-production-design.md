# Gift-Lab — Features C + D: Courier / Production / Shipment Rework — Design Spec

**Date:** 2026-07-30
**Source:** post-walkthrough roadmap (`docs/superpowers/specs/2026-07-29-giftlab-post-walkthrough-roadmap.md`, section "Feature C + D"), owner decisions confirmed 2026-07-30.
**Absorbs findings:** F10 (🔴 returned-parcel resolution has no UI), F8 (commit PO not marked required), F12 (book-shipment dialog can't scroll to the Book button), F13 (SG address city/state/country should prefill + disable), owner feedback #5/#8 (separate push-to-courier from make-queue) and #9 (shipment grouping).

---

## Owner decisions (confirmed)

1. **Shipment grouping build approach:** phased. **Stage 1** ships the F10 unblock + UX + surface split with **no DB schema change**. **Stage 2** introduces the real Shipment entity (1 shipment : many jobs). *This spec scopes the current build to Stage 1;* Stage 2 is fully specified here for its own next plan/build cycle.
2. **Delivery fee on split (Stage 2):** keep **one** delivery fee by default; when staff split an order into N shipments, **surface the extra-parcel cost and let staff explicitly add a per-parcel delivery charge**. No silent re-billing of an already-accepted order.
3. **Production surfaces:** **4 tabs** — Make queue / Ship desk / In-transit / Needs-attention.
4. **Make↔Ship seam (Stage 1):** state-based; `IN_PRODUCTION` appears in both Make and Ship (make it / send it — two lenses). No new `PRODUCED` state in Stage 1.

---

## Current behaviour (grounding facts, from code)

- Production jobs are built **one UV job + one job per 3D line** (`QueueService::buildJobsForQuote`). A multi-line order already produces multiple jobs.
- **Consignment/courier fields live on `ProductionJob`**: `consignment_ref` (unique), `carrier`, `label_url`, `last_courier_status`, `last_courier_status_at`, `delivered_at`. Each job books its **own** NinjaVan consignment (`NinjaVanTrackingNumber::forJob(quoteId, jobId)`, `ShipmentService::createForJob`). → today the system **splits by default**; the owner decision inverts that (Stage 2).
- The **NinjaVan webhook matches on `consignment_ref`** (`NinjaVanWebhookController::findJobForTrackingNumber`, exact or unambiguous trailing-suffix). This is the courier's authoritative key and **must keep working** across both stages.
- `QueueService::inTransit()` returns **all** `SHIPPED` jobs — **including** returned/failed ones. `AwaitingDeliveryPanel` (frontend) offers only "Mark delivered", which the backend **rejects (422)** for a needs-attention parcel. This is the F10 muddle.
- Backend `QueueService::resolveReturn($job, $disposition, $note)` (close / reship / cancel_credit; M15 per-parcel cancel) is **fully built + tested**; route `POST /production-jobs/{job}/resolve-return` exists. **No frontend calls it** → F10 🔴.
- `JobState` cases: `READY`, `IN_PRODUCTION`, `SHIPPED`, `CLOSED`, `RETURNED`. Terminal `RETURNED` reused as-is.
- `OrderTracker::shipments($quote)` already derives one tracker entry **per shipped/closed job with a consignment_ref** — the read model already treats each consignment as a "shipment", so the Stage 2 entity formalizes what the tracker already exposes.
- Needs-attention labels: `NinjaVanStatusMapper::LABEL_ATTEMPT_FAILED`, `LABEL_RETURNED` (`NinjaVanStatusMapper::isNeedsAttentionLabel()`).
- `Modal` (frontend `ui`) already takes a `footer` prop; the book-shipment confirm modal uses `size="lg"` with a `DeliveryAddressPanel` body.
- `DeliveryAddressPanel` (inside `ProductionQueuePage.tsx`) renders City / State (editable, blank) and Country (`placeholder="SG"`, editable).

---

# STAGE 1 — Unblock + resurface (no DB schema change)

## S1.A — Surfaces (D1)

Reorganize the production page into **4 tabbed surfaces**, each fed by existing per-job data. No state-machine change.

| Tab | Jobs | Actions |
|-----|------|---------|
| **Make queue** | `READY` + `IN_PRODUCTION` | scan-to-advance, start / advance production, print files, view customization. **No courier actions.** |
| **Ship desk** | `IN_PRODUCTION` | book NinjaVan shipment / enter manual consignment → `SHIPPED`; print label; delivery-address panel. |
| **In-transit** | `SHIPPED` **and not** needs-attention | mark delivered (silent-webhook fallback). |
| **Needs-attention** | `SHIPPED` **and** needs-attention | reship / close / cancel-&-credit (S1.B). |

- The existing single board's job card is the basis; courier actions ("Create NinjaVan shipment", manual consignment entry) move to the **Ship desk** tab and are **removed from the Make queue** card.
- Bulk floor actions (start/close selected) stay on Make queue.

## S1.B — Returned-parcel resolution UI (F10 🔴, D2)

**Backend:**
- Add `QueueService::needsAttention(): Collection` — `SHIPPED` jobs whose `last_courier_status` is a needs-attention label (`NinjaVanStatusMapper::isNeedsAttentionLabel`), `whereHas('quote')`, eager-load `['quote','lineItems.product']`, newest first.
- Change `QueueService::inTransit()` to **exclude** needs-attention jobs (so a returned parcel no longer shows in the plain awaiting-delivery list). This is a behaviour change with existing tests — update them.
- Add `ProductionQueueController::needsAttention()` + route `GET /production-jobs/needs-attention` (registered **before** the `/{job}` wildcards, like `in-transit`; `permission:production.view`).
- `ProductionJobResource`: add derived `needs_attention` (bool) = `NinjaVanStatusMapper::isNeedsAttentionLabel($job->last_courier_status)`. (Keeps the courier-status fields it already exposes.)

**Frontend:**
- `queueStore`: add `needsAttention: ProductionJob[]`, `fetchNeedsAttention()`, and `resolveReturn(jobId, disposition: 'reship'|'close'|'cancel_credit', note?)` → `POST /production-jobs/{jobId}/resolve-return` body `{ disposition, note }`. On success, refetch needs-attention (and in-transit / queue as relevant).
- New `NeedsAttentionPanel` component: lists needs-attention parcels (order ref, carrier, consignment, `last_courier_status`), each with three actions:
  - **Reship** — re-queue for a fresh consignment (job → `IN_PRODUCTION`).
  - **Close (write off)** — accept the loss, close the job.
  - **Cancel & credit** — void this parcel's share, credit what was collected (M15; on a multi-parcel order only this parcel, order stays live). Confirm dialog (irreversible-ish; money) with optional note.
- Remove the misleading "Mark delivered" affordance for needs-attention parcels (they're no longer in `inTransit`).

## S1.C — Book-shipment dialog fixes (F12, F13, D3)

- **F12:** `Modal` body scrolls independently with a **sticky action footer**, so the primary "Book shipment" button is always reachable at a laptop viewport. Fix in the shared `Modal` (or the book-shipment modal) — cap body height (`max-h`), `overflow-y:auto` body, footer pinned.
- **F13:** `DeliveryAddressPanel` — prefill **City = Singapore**, **State = Singapore**, **Country = SG**, and render those three **read-only/disabled** (SG-only courier). Existing saved values still load; the three SG fields are locked. Required-field logic unaffected.

## S1.D — Commit PO required hint (F8, D4)

- On the order-detail commit control (locate the PO field; `frontend/src/pages/QuoteDetailPage.tsx` area), mark the PO field **required** (asterisk + helper "PO required to raise the invoice"). Keep Commit disabled until PO entered, but now the requirement is visible. (Copy/UX only — no backend change.)

## S1 — Courier-independence guard (must stay true)

- The NinjaVan webhook keys off `consignment_ref`, **not** any order id / reference / job id. Add/keep a test asserting a webhook posted with `tracking_number` = the order reference (or job id) does **not** resolve, while the real `consignment_ref` does. Courier side untouched by Stage 1.

## S1 — Testing

- **F10 dispositions:** each of reship / close / cancel_credit from the new UI drives the correct backend call and resulting job state; multi-parcel cancel_credit isolates one parcel (order stays live); a needs-attention parcel is **absent** from in-transit and **present** in needs-attention.
- **Surfaces:** each tab filters to the right states; courier actions absent from Make queue, present on Ship desk.
- **inTransit change:** returned/failed job excluded; plain shipped job still listed.
- **Dialog:** Book button reachable (scroll/sticky footer) at laptop viewport.
- **SG fields:** City/State/Country prefilled + disabled; save still works.
- **PO:** required marker shown; Commit gated as before.
- **Webhook guard** (above).
- Backend Pest + frontend Vitest/RTL. Full suite green.

---

# STAGE 2 — Shipment entity + grouping (own plan/build cycle)

*Specified for the next cycle; not built in the current one.*

## S2.A — Data model

- New table **`shipments`**: `id`, `quote_id` (fk), `consignment_ref` (string, nullable, **unique**), `carrier`, `label_url`, `last_courier_status`, `last_courier_status_at`, `delivered_at`, `state`, timestamps.
- **`production_jobs.shipment_id`** (nullable fk) — **many jobs → one shipment**.
- **Migrate** the per-job consignment/courier fields onto `shipments`; backfill: each existing shipped/closed job's consignment becomes one shipment row, `shipment_id` set. Then drop (or deprecate) the job-level courier columns.
- Default grouping: when jobs are built for a quote (or at ship time), all of an order's jobs belong to **one** shipment → one NinjaVan booking.

## S2.B — Grouping + split behaviour

- **Default (combined):** book one consignment covering all the order's jobs.
- **Split:** staff action "Ship this item separately" moves a job to its **own** new shipment → its own booking.
- `NinjaVanTrackingNumber::forJob(quoteId, jobId)` → **`forShipment(quoteId, shipmentId)`** (deterministic per shipment).
- `ShipmentService::createForJob` → **`createForShipment`** (books one consignment per shipment).

## S2.C — Repoint the courier core to shipment

- **Webhook** matches `shipments.consignment_ref`; a delivered event advances **all jobs in that shipment** (SHIPPED→CLOSED). TOCTOU lock + monotonic status guard + idempotency preserved.
- `resolveReturn` / `markDelivered` operate at the **shipment** level (a returned parcel = a shipment).
- "On its way" milestone dedup becomes naturally per-shipment (still one email per order — first shipment to ship notifies).
- `OrderTracker::shipments()` reads real `shipments` rows.

## S2.D — Delivery fee on split (owner decision 2)

- One delivery fee by default. On split, **surface** the added per-parcel courier cost and let staff **explicitly add** a per-parcel delivery charge (a quote line / fee). No automatic re-bill.

## S2 — Testing

- Combined-by-default: 1 consignment for N jobs. Staff split → N consignments, N shipments.
- Webhook advances **all** jobs in a shipment on delivered.
- resolveReturn/markDelivered per shipment; multi-shipment isolation.
- Fee-on-split surfaced + staff-added; not auto-applied.
- Migration backfill: existing shipped/closed jobs map 1:1 to shipment rows, webhook still resolves.

---

## Open questions (deferred to Stage 2 plan)

1. Exact `shipments.state` machine vs deriving from member jobs (candidate: derive; jobs remain the unit of production state).
2. Whether to add a `PRODUCED` job state for a hard Make↔Ship seam (Stage 1 uses `IN_PRODUCTION` in both).
3. Precise fee line representation (new line item vs adjustment) for split surcharge.
