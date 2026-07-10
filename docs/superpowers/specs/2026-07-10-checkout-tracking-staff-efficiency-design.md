# Design — Checkout/Tracking UX + Staff-Efficiency batch

**Date:** 2026-07-10
**Status:** Approved (design)
**Scope:** 7 features. Deferred features #6, #8 live in `pending_features.md`.

## Goal

Two outcomes:

1. **Cut buyer "where's my order?" contacts** — richer, self-serve public tracking
   (one-click link, carrier link, promised date, partial-shipment progress).
2. **Cut production-staff app interaction** — fewer clicks per job on the shared
   production queue (auto-advance on download, batch advance, scan-to-advance).

## Existing constraints honored

- The public tracker (`TrackingController`, `TrackPage.tsx`) is **account-free and
  PII-free by design**: opaque `tracking_code` + first-5-of-email second factor,
  hard rate limit, single generic error (anti-enumeration). Every addition below
  keeps the payload PII-free — status, dates, carrier refs, and counts only. No
  product names, addresses, or pricing on the tracker.
- Production queue is **staff-only, FCFS by readiness** (`QueueService`,
  `ProductionQueueController`). Job state is the single source of truth the tracker
  reads. Every state change is audit-logged and broadcast over Reverb
  (`ProductionQueueUpdated`, `OrderTrackingUpdated`).
- Job lifecycle (`JobState`): READY → IN_PRODUCTION → SHIPPED → CLOSED. Guarded
  transitions via `transitionTo`.

---

## Foundation: `OrderTracker` service

Extract the tracking-payload building currently inline in `TrackingController` into
`App\Services\OrderTracker`:

- `payload(Quote $quote): array` — the full PII-free tracking response (stage,
  stages list, dates, **new**: `needed_by`, `shipments[]`, `items_total`,
  `items_completed`).
- `signedUrl(Quote $quote): string` — a permanent Laravel signed URL to the
  one-click tracker view (feature #2).

`TrackingController` (POST `/track`) and the new signed-link view (GET
`/track/view`) both delegate to `payload()`. One source of truth for the PII
boundary. Unit-test the service directly.

---

## Buyer-facing features

### #2 — Signed one-click tracking link + QR

- **Route:** `GET /track/view` behind Laravel `signed` middleware, throttled like
  `/track` (`throttle:10,1`). Query carries `code` (opaque `tracking_code`) + the
  Laravel `signature`. **No email in the URL** — the signature is the second
  factor, so the payload stays PII-free and nothing sensitive leaks into browser
  history/logs.
- **Expiry:** permanent (`URL::signedRoute`, no expiration). Buyers bookmark and
  track across the order's whole life.
- **Controller:** verifies signature (middleware), looks up the quote by code,
  returns `OrderTracker::payload()`. Unknown/missing code → same generic 404 as
  `/track`.
- **Where the link surfaces:** `OrderTracker::signedUrl()` is included in the
  authenticated quote/checkout response (owner-scoped, e.g. `QuoteResource` for the
  quote owner) so the frontend can render:
  - a "Track order" button on the checkout-success screen and `QuoteDetailPage`,
  - a **QR code** encoding the signed URL (new `qrcode` frontend dep).
- **TrackPage:** remembers the last entered `code` + `email` in `localStorage` and
  prefills on return. `/track/view` deep-links auto-submit via the payload fetch.

### #3 — Carrier tracking passthrough

- **Enum:** `App\Enums\Carrier` — `SingPost`, `NinjaVan`, `JnT`, `Qxpress`, `DHL`,
  `FedEx`, `Other`. Each exposes `label(): string` and
  `trackingUrl(string $ref): ?string` built from a per-carrier URL template with
  `rawurlencode($ref)`. `Other` returns `null` (ref shown as text only).
- **Migration:** add nullable `carrier` (string) to `production_jobs`. Cast to
  `Carrier` on `ProductionJob`.
- **Advance:** `AdvanceJobRequest` accepts optional `carrier` (validated
  `Enum(Carrier::class)`) **only meaningful when** target = SHIPPED.
  `QueueService::advance(job, target, consignmentRef, carrier)` persists it in the
  same save as the state change.
- **Tracker payload:** `shipments[]` = for each of the quote's jobs currently in
  SHIPPED/CLOSED with a `consignment_ref`, `{ carrier_label, tracking_url, ref }`.
  PII-free (carrier + parcel ref only). `TrackPage` renders each as a clickable
  "Track with {carrier}" link, or plain copyable text when `tracking_url` is null.

### #4 — Promised date on the tracker

- `OrderTracker::payload()` includes `needed_by` (ISO date or null) from the quote.
- `TrackPage` shows "Needed by {date}" when present. **No risk/at-risk badge**
  (explicit decision — display only).

### #5 — Per-line partial-shipment progress

- Payload adds `items_total` and `items_completed`. A line item counts as
  **completed** when its linked job's state ∈ {SHIPPED, CLOSED}
  (`LineItem.job_id → ProductionJob.state`).
- `TrackPage` shows "{completed} of {total} items shipped" when
  `items_total > 1` and `0 < items_completed < items_total` (i.e. a genuine partial
  state). Counts only — no product detail. Preserves the "honest least-progressed
  stage" behavior already in `Quote::trackingStage()`; this just adds granularity.

---

## Staff-facing features

### #7 — Auto-advance READY → IN_PRODUCTION on print-file download

- In `ProductionQueueController::printFile`, after auth + file-exists checks and
  **before** streaming: if `job.state === JobState::Ready`, call
  `queue.advance($job, JobState::InProduction)`.
- Idempotent: only fires from READY, so re-downloads at a later state are no-ops.
  Reuses existing audit log + Reverb broadcast in `advance()`. Downloading the
  print file *is* the "started" signal, collapsing download + manual advance into
  one action.

### #9 — Batch advance

- **Route:** `POST /production-jobs/advance-batch`. Body: `job_ids[]` (required,
  array of existing job ids) + `state` (required).
- **Allowed targets:** `IN_PRODUCTION` and `CLOSED` only. SHIPPED is **excluded**
  from batch — it needs a per-parcel `consignment_ref` + `carrier`, so it stays on
  the single-job dialog. Request validation rejects other targets.
- Per job: guard with `canTransitionTo`; advance the valid ones, collect skipped
  ids (wrong current state / not found). Response: `{ advanced: [...], skipped:
  [...] }`. Each advance audit-logs + broadcasts as today.
- **Frontend:** checkboxes on `ProductionQueuePage` cards + a bulk-action bar
  ("Start selected" / "Close selected"). Skipped ids surfaced as a toast.

### #10 — Scan-to-advance (hardware scanner + camera)

- **Route:** `POST /production-jobs/{job}/advance-next` — advances the job to its
  single next state (`JobState::nextStates()[0]`). Scan-safe for
  READY→IN_PRODUCTION and SHIPPED→CLOSED. If the next state is SHIPPED, **reject**
  with a clear error (needs the manual ref/carrier dialog) so a scan can't ship
  without a consignment ref.
- **Printable job label / traveler:** a print view per job rendering the job id +
  a **QR encoding the raw job id** (the advance endpoints are already staff-auth
  gated, so the QR itself is not a secret).
- **Queue page scan input:**
  - *Hardware (keyboard-wedge):* a focused text input; the scanner types the job id
    + Enter → look up job → `advance-next`.
  - *Camera:* optional "Scan" mode using `html5-qrcode` (new frontend dep,
    getUserMedia). Decoded value = job id → `advance-next`.
  - Both paths share the same resolve-id → advance-next handler. SHIPPED-reject
    surfaces as a toast prompting the manual ship dialog.

---

## Testing

**Backend (Feature/Unit):**

- `OrderTracker::payload()` unit test — PII-free shape, `shipments`, counts,
  `needed_by`.
- Signed-link view: valid signature 200; tampered/invalid signature 403; unknown
  code generic 404.
- Carrier: `Carrier::trackingUrl` templating + `Other` null; advance stores carrier;
  payload renders `shipments[]` links.
- Partial counts: mixed job states → correct `items_completed`.
- Auto-advance-on-download: fires only from READY; no-op from later states; audits.
- Batch advance: allowed targets only; per-job guard; skipped reporting; SHIPPED
  target rejected.
- Advance-next: READY→IN_PRODUCTION ok; SHIPPED next-state rejected; audit +
  broadcast.

**Frontend:**

- `TrackPage`: localStorage prefill; `needed_by` display; partial "X of Y" display;
  carrier link vs text.
- `ProductionQueuePage`: multi-select + bulk actions; scan input resolves + advances;
  camera-mode toggle renders.

---

## New / changed surface (summary)

| Kind | Item |
| --- | --- |
| Enum | `App\Enums\Carrier` |
| Migration | `production_jobs.carrier` (nullable string) |
| Service | `App\Services\OrderTracker` |
| Route | `GET /track/view` (signed, throttled) |
| Route | `POST /production-jobs/advance-batch` |
| Route | `POST /production-jobs/{job}/advance-next` |
| Changed | `QueueService::advance` (+ `carrier` arg) |
| Changed | `ProductionQueueController::printFile` (auto-advance) |
| Changed | `AdvanceJobRequest` (+ `carrier`) |
| Changed | `TrackingController` (delegate to `OrderTracker`) |
| Changed | `QuoteResource` (+ signed tracking URL, owner-scoped) |
| Frontend dep | `qrcode`, `html5-qrcode` |
| Frontend | `TrackPage` (localStorage, needed_by, partial, carrier links) |
| Frontend | `ProductionQueuePage` (batch select, scan input, camera mode) |
| Frontend | checkout-success + `QuoteDetailPage` (track button + QR), job label view |

## Out of scope (see `pending_features.md`)

- **#6** buyer self-serve reorder from tracker.
- **#8** carrier webhook → auto Shipped/Delivered. (Feature #3's carrier field is
  the groundwork for it.)
