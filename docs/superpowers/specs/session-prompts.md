# Session prompts — post-walkthrough roadmap

Copy-paste starters for follow-up Claude Code sessions in `D:\work\NexGen\gift-lab`.
All reference the design spec: `docs/superpowers/specs/2026-07-29-giftlab-post-walkthrough-roadmap.md`.

**Coordination:** Feature A (quote state machine) and Feature C+D (jobs/shipments + quote
states) both touch the order lifecycle — don't run them truly parallel (merge-conflict
risk). Feature B (identifiers) is safe to parallelize alongside one of A / C+D. Do Batch 0
first and merge it before starting the features.

---

## 0. Sequential starter (Batch 0 first)

```text
Read docs/superpowers/specs/2026-07-29-giftlab-post-walkthrough-roadmap.md — it's the
plan from a full-app walkthrough (findings + 4 owner-requested features), and
docs/flow-audit-2026-07-28/WALKTHROUGH-FEEDBACK.md is the evidence behind it.

We're executing that roadmap in order. Each numbered item = its own spec → plan → build
cycle. Do NOT do everything at once.

Start with **Batch 0 — quick wins** (F1, F2, F3, F6, F7, F9, F11, F14, + F4 if cheap).
For Batch 0:
  - Brainstorm/confirm the design, then implement with TDD (tests first), then verify.
  - Before F3: ask me whether a fully blank/uncustomised line should carry the SGD 25
    setup fee (⚠️ OWNER decision in the spec).
  - Verify in the live app (preview_start api/frontend, http://localhost:5173, never
    127.0.0.1 — the Sanctum cookie is host-bound). Env is already test-configured
    (QUEUE=sync, SMTP live to admin@nexgen.com.sg, NinjaVan sandbox, self-chosen
    NINJAVAN_WEBHOOK_SECRET). Seeded demo data + my walkthrough test orders exist —
    don't delete them.

Do NOT start Features A (price/proof toggle), B (unify order-ref/tracking-code), or
C+D (courier/production rework incl. the F10 returned-parcel-resolution UI 🔴) until
Batch 0 is merged AND I've answered the ⚠️ OWNER decisions listed at the bottom of the
spec. Ask me those decisions per-feature, right before designing that feature.

Work on a branch off master; commit per logical unit; don't push or open PRs unless I ask.
```

---

## A. Feature A — price/proof order toggle

```text
Read docs/superpowers/specs/2026-07-29-giftlab-post-walkthrough-roadmap.md, section
"Feature A". Implement ONLY Feature A.

Goal: per-order, staff-controllable price-first vs proof-first approval, default
price_first (current behaviour). Plain-stock (no proof-needing line) stays a no-op.

Before designing, ask me these ⚠️ OWNER decisions (in the spec):
  A-1: in proof_first, show the price read-only before proof approval, or hide it?
  A-2: lock approval_order at "Send to buyer", or allow changing after send?
  A-3: global default price_first with per-order override only, or a per-company default?

Then: brainstorm the design → write/confirm the Feature-A spec → writing-plans → build
with TDD. Load app/Services/QuoteService.php (accept/sendProofs/proof-decision),
app/Enums/QuoteState.php, and ChaseUnansweredOrders before touching the state machine.
Add quotes.approval_order (default price_first). Enforce legal transitions per ordering
(reject price-accept before proof in proof_first, and vice-versa). Verify chase phases
still key off the right wait.

Verify live: preview_start api/frontend, http://localhost:5173 (NOT 127.0.0.1 — Sanctum
cookie is host-bound). Env already test-configured (QUEUE=sync). Don't delete seeded/
walkthrough data. Branch off master; commit per unit; no push/PR unless I ask.
```

---

## B. Feature B — unify order-ref + tracking-code

```text
Read docs/superpowers/specs/2026-07-29-giftlab-post-walkthrough-roadmap.md, section
"Feature B". Implement ONLY Feature B.

Goal: collapse the two order identifiers (quotes.reference like MANVQ3MT1M, and
tracking_code like GL-X78J0F) into ONE id used everywhere. The public /track page keeps
its buyer-email verification gate, so a single exposed id is safe.

Before designing, ask me the ⚠️ OWNER decision: which format survives — keep `reference`
(MANVQ3MT1M, recommended) or the `GL-` tracking format? Also confirm no external party
holds old /track links (they'd break; pre-prod so likely fine).

Then: brainstorm → spec → writing-plans → build with TDD. Touch points: quotes model +
migration (drop/deprecate the retired column), every mailable + blade that prints both
ids (grep the mail views), frontend/src/components/TrackingQr.tsx, the /track lookup
(TrackPage), and Quote resource/trackingStage. NOTE: NinjaVanWebhookController keys off
consignment_ref, NOT tracking_code — courier side must stay untouched; add a test
asserting that.

Verify live: preview_start api/frontend, http://localhost:5173 (NOT 127.0.0.1). Confirm
tracker still gates on email + resolves by the unified id; QR/emails render one id; no
lingering tracking_code refs. Branch off master; commit per unit; no push/PR unless I ask.
```

---

## C+D. Feature C + D — courier / production / shipment rework

```text
Read docs/superpowers/specs/2026-07-29-giftlab-post-walkthrough-roadmap.md, sections
"Feature C + D". Implement Features C and D together (same surface).

Goal: (C1) shipment grouping — DEFAULT one shipment/consignment per order, staff can
split per item [note: today it auto-creates a job+consignment per line, so this INVERTS
current behaviour]. (D1) separate the make-queue from a ship-desk / in-transit /
needs-attention surface. (D2) 🔴 F10 — wire the returned-parcel resolution UI (reship /
close / cancel-&-credit) to the EXISTING backend POST /production-jobs/{job}/resolve-return
(backend + tests already done; only the frontend call is missing). (D3) F12 make the
book-shipment dialog scroll with a sticky footer + F13 prefill & disable SG city/state/
country. (D4) F8 mark the commit PO field required.

Before designing, ask me these ⚠️ OWNER decisions:
  C-1: when staff SPLIT into N shipments, charge N delivery fees or one? (today: one fee
       even for multiple parcels — possible under-charge).
  C-2/D: Shipment model shape (1:1 vs 1:many jobs) and how many production surfaces.

Then: brainstorm → spec → writing-plans → build with TDD. Design the Shipment entity
carefully (it's the crux). Reuse the existing terminal JobState::Returned. If the F10
blocker must ship first, pull D2 forward as a stopgap (wire resolve-return into the
current queue card) before the full restructure.

Load app/Services/QueueService.php, ShipmentService.php, NinjaVanWebhookController.php,
ProductionQueueController.php, and frontend/src/components/production/*,
frontend/src/pages/ProductionQueuePage.tsx first.

Verify live: preview_start api/frontend/reverb, http://localhost:5173 (NOT 127.0.0.1).
To test returned parcels, POST a signed NinjaVan webhook (see §1e of
docs/flow-audit-2026-07-28/WALKTHROUGH-PLAN.md; secret is NINJAVAN_WEBHOOK_SECRET in
.env, header X-Ninja-Hmac, HMAC-SHA256 of the raw body). Don't delete seeded/walkthrough
data. Branch off master; commit per unit; no push/PR unless I ask.
```
