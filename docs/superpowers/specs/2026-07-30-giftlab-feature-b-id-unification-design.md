# Feature B — Unify order reference & tracking code into one id

**Date:** 2026-07-30
**Source:** `docs/superpowers/specs/2026-07-29-giftlab-post-walkthrough-roadmap.md` § "Feature B" + owner decisions (this session).
**Scope:** Feature B only. Does not touch Batch 0, Feature A, or Features C/D.

---

## Owner decisions (confirmed this session)

- **B-format — which id survives:** the **`GL-` tracking format** survives as the single id, **lengthened** to avoid duplication. New shape: `GL-` + **10** random chars = `GL-XXXXXXXXXX` (13 chars), from the existing unambiguous 32-symbol alphabet → ~2^50 space (1000× the old 6-char `GL-` code).
- **B-links — old links breaking:** confirmed **pre-production, safe to break**. No external party holds old QR / `/track` links. No compatibility shim, no dual-lookup.

---

## Problem

Two ids per order today:
- `reference` (`MANVQ3MT1M`, 10 chars, no prefix) — the account-scoped buyer-facing order name; the **route key** for `/orders/{reference}` and `/api/quotes/{reference}`; the primary id in `QuoteResource`, in 4 of 5 mail blades, and across ~15 feature tests.
- `tracking_code` (`GL-X78J0F`, `GL-` + 6 chars) — the PII-free public token the login-free `/track` page, the QR deep-link, and the live tracking broadcast key off.

Two things to print, two things to communicate, two columns to keep collision-safe. The public tracker already requires a **buyer-email prefix check** before returning anything, so a single exposed id is safe — collapse to one.

---

## Decision

Collapse to **one** id used everywhere: order pages (staff + buyer), all emails, the QR, and the public tracker.

**Which physical column is kept — the crux:** keep the **`reference`** column and retro-fit its generator to emit the surviving `GL-` format; **drop the `tracking_code`** column. This delivers the owner's choice (the `GL-` format is the single id) with the **least churn and lowest risk**, because `reference` is already:
- the route key for `/orders/{reference}` and `/api/quotes/{reference}` (controller-resolved — see below),
- the primary id in `QuoteResource`,
- printed in `design-requested`, `proof-changes-requested`, `order-milestone`, `parcel-returned` blades,
- asserted in ~15 feature tests as the order-lookup handle.

Keeping `tracking_code` instead would force re-pointing all of the above. Keeping `reference` only requires re-pointing the **public-tracker surface** (`OrderTracker`, `OrderTrackingUpdated`, `TrackingController`, the QR/track frontend), which is small and self-contained.

**Net buyer-visible result is identical to "keep GL-":** one id, `GL-` format, longer. The column name is an internal detail.

### Route-key note
`Quote` defines no `getRouteKeyName()` / `resolveRouteBinding()`. `/api/quotes/{ref}` resolves in `QuoteController` (accepts opaque `reference` **or** numeric id). Dropping `tracking_code` therefore does **not** touch route-model binding. `reference` stays the order route id, unchanged.

---

## Data & migration

- **`reference` column:** already `string(24) nullable unique` (migration `2026_07_20_062127`). 24 ≥ 13, so **no width change**. Existing rows keep their current 10-char `reference` values — those stay valid ids; only *newly generated* references adopt the `GL-` format. (Pre-prod; a mixed set of old 10-char and new `GL-…` references is acceptable and both resolve. If the owner wants existing rows re-formatted, that is a separate backfill — out of scope here.)
- **`Quote::generateReference()`:** change from 10 bare chars to `'GL-'` + 10 chars from `TRACKING_ALPHABET`. Length 13, well under the column's 24 and the tracker's `max:16` input.
- **New migration `drop_tracking_code_from_quotes`:**
  - `up()`: `dropUnique(['tracking_code'])` then `dropColumn('tracking_code')` (symmetric with how `add_reference` drops its own index in `down()`; explicit unique-drop is portable across MySQL + SQLite).
  - `down()`: re-add `string('tracking_code', 16)->nullable()->unique()->after('id')`. No backfill on rollback (pre-prod).
- No new backfill on `up`. Ids already exist.

---

## Backend changes

- **`app/Models/Quote.php`:**
  - `generateReference()` → `'GL-'` + 10 chars.
  - Drop `tracking_code` from `$fillable`.
  - Drop the `tracking_code` branch of the `static::creating` hook.
  - Remove `generateTrackingCode()` (now unused). `TRACKING_ALPHABET` stays (used by `generateReference`).
- **`app/Services/OrderTracker.php`:**
  - `payload()`: `'reference' => $quote->reference` (was `$quote->tracking_code`). Payload key name `reference` is unchanged — the frontend contract holds.
  - `signedFrontendLink()`: sign `['code' => $quote->reference]` (was `tracking_code`).
- **`app/Events/OrderTrackingUpdated.php`:**
  - `broadcastOn()`: guard + channel keyed on `reference` → `new Channel("track.{$this->quote->reference}")`.
  - `broadcastWith()`: `'reference' => $this->quote->reference`.
- **`app/Http/Controllers/TrackingController.php`:**
  - `__invoke()`: validate request param `reference` (was `tracking_code`), still `max:16`; look up `->where('reference', $code)`. Keep the email-prefix second factor + generic-404 anti-enumeration **unchanged**.
  - `view()`: look up signed `code` query against `reference`.
- **`app/Http/Resources/QuoteResource.php`:** drop the `'tracking_code'` key. Keep `'reference'` and `'tracking_link'`.
- **`app/Services/ShipmentService.php` (line ~79):** courier payload `reference: (string) ($quote->tracking_code ?? $quote->id)` → `reference: (string) $quote->reference` (`reference` is always assigned on create, so the `?? id` fallback is dropped).

### Courier side stays untouched (owner requirement)
`NinjaVanWebhookController` resolves parcels by **`consignment_ref`** (the per-job NinjaVan tracking number from `NinjaVanTrackingNumber::forJob`), **never** by `tracking_code` or `reference`. Unifying the buyer-facing id therefore cannot affect courier matching. **Add a test** asserting the webhook still resolves a parcel by `consignment_ref` and that no code path reads the retired `tracking_code`.

---

## Frontend changes

- **`frontend/src/types.ts`:** drop `tracking_code?: string | null` from the `Quote` interface. `TrackResult.reference` unchanged (public payload still sends `reference`).
- **`frontend/src/pages/TrackPage.tsx`:**
  - Field label "Tracking code" → "Order reference"; placeholder `GL-XXXXXX` → `GL-XXXXXXXXXX`.
  - POST body `{ tracking_code, email }` → `{ reference, email }`.
  - localStorage keys `gl.track.code` / `gl.track.email` — rename the code key to `gl.track.reference` (or keep; behaviour-neutral — rename for clarity).
  - `result.reference` channel subscription (`track.${reference}`) unchanged.
- **`frontend/src/components/TrackingQr.tsx`:** no change — it renders the signed `tracking_link` path, which is now signed with `reference` transparently.
- **`frontend/src/pages/QuoteDetailPage.tsx`:** wherever `tracking_code` was shown, show the single `reference`. QR `link` prop unchanged.

---

## Mail changes

- **`resources/views/mail/quote-ready.blade.php`:** collapse the two rows — "Order reference" (`$quote->reference`) and "Tracking code" (`$quote->tracking_code`) — into **one** row showing `$quote->reference`. Remove the tracking-code row + its now-stale comment.
- Other blades (`design-requested`, `proof-changes-requested`, `order-milestone`, `parcel-returned`) already print only `reference` — no change.

---

## Testing (TDD)

Backend (Pest):
- **`QuoteReferenceTest`:** `generateReference()` now yields `GL-` prefix + total length **13**; two quotes differ. (Update the old length-10 / no-prefix assertions.)
- **`TrackingTest`:** update the `GL-`/length assertion (was `startsWith('GL-')` + length 9 → still `GL-`, length 13); rename the POST param `tracking_code` → `reference`; lookups resolve by `reference`; email gate + generic-404 anti-enumeration still hold; wrong email prefix → 404.
- **`OrderTrackerTest`:** `payload['reference']` equals `$quote->reference`.
- **`SignedTrackLinkTest`:** signed deep-link resolves by `reference`; payload `reference` == `$quote->reference`.
- **`QuoteReadyMailTest`:** email shows `reference` once; **no** `tracking_code` row (drop the tracking_code assertions).
- **New — courier untouched:** a webhook test asserting a parcel resolves by `consignment_ref` and the flow is unaffected by the id unification. (Confirm no lingering `tracking_code` read in the webhook path.)
- **Regression:** `grep -r tracking_code app/ database/ resources/ routes/` returns nothing live after the change (docs/old-migrations excepted).

Frontend (Vitest/RTL):
- `TrackPage` submits `{ reference, email }` and renders a result.
- `QuoteDetailPage` shows the single id; no `tracking_code` reference remains in the component/tests.
- `types.ts` compiles with `tracking_code` removed (tsc clean).

Live (per instruction): `preview_start api/frontend`, `http://localhost:5173` (NOT 127.0.0.1 — Sanctum cookie host-bound). Confirm: tracker gates on email + resolves by the unified id; QR + emails render one id; no lingering `tracking_code`.

---

## Migration & compatibility

- Additive-then-subtractive: retro-fit the generator (new rows only), drop the retired column. No data migration on existing rows.
- Old QR/`/track` links (signed with `tracking_code`, keyed on the `GL-`+6 code) break — **accepted, pre-prod** (owner B-links).
- Courier (`consignment_ref`) path unchanged and test-guarded.
- Do not delete or disturb seeded / walkthrough data.

---

## Out of scope

- Batch 0, Feature A (approval order), Features C/D (courier/production rework).
- Re-formatting existing rows' `reference` values to `GL-` (separate backfill if ever wanted).
- Any change to the email-prefix second factor or the anti-enumeration contract.

---

## Open questions

None outstanding — B-format and B-links resolved above.
