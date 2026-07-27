# Round-2 Quality Audit — Performance, Data Integrity, Cache, Best-Practices

**Date:** 2026-07-27
**Scope:** A second pass with a different lens than the journey audit — frontend (redundant API calls, duplicate rendering, React mistakes, navigation) and backend (query/N+1/big-data, data integrity, caching, test/stub-data leaks, logic correctness). Confirmed-in-code, cited to `file:line`. Excludes the 24 journey findings already fixed.

**Method:** 9 parallel code-reader passes by concern → synthesized/de-duped (25 raw → 18 surviving).

> **Known intentional (NOT a bug):** "Cancelling a quote never returns MODEL_3D filament" (`QuoteService.php:845`) resurfaced in this pass — this is the owner's deliberate decision (defect output makes exact filament tracking impractical). Excluded from the action list. Left here only for traceability.

---

## Frontend

### Redundant API calls
- **[MEDIUM][redundant-fetch] PDP fires two overlapping `/price-estimate` POSTs on mount** — `frontend/src/pages/ProductDetailPage.tsx:185` (live-price effect :206; `lib/catalogue.ts:74`) — the tier-price effect and live-price effect both POST for `[minQty]` on single-tier products, and the tier result is never rendered (strip gated on `tierQuantities.length>1`, :444). — Every single-tier PDP view doubles load on a rate-limited (~60/min) endpoint with a wholly wasted call. — Gate the tier effect on `tierQuantities.length>1`; reuse a fetched tier price when `qty` matches a tier.

### Duplicate data
- **[LOW][duplicate-render] Order status badge rendered twice, ~40px apart** — `frontend/src/pages/QuoteDetailPage.tsx:901` + `components/OrderStatus.tsx:114` — header pill and the OrderStatus card both lead with the same `humanizeState(quote.state)` badge. — Status shown twice in succession; the header adds nothing the card doesn't. — Drop the header badge; keep the card's (it holds the next/step context).
- **[LOW][duplicate-data] Delivery disclaimer copy duplicated as literals across Cart & Checkout** — `frontend/src/pages/CartPage.tsx:172-176` vs `CheckoutPage.tsx:65-68` — byte-identical notes inlined in Cart, constants in Checkout, no shared source → drift risk. — Hoist both strings into a shared module.

### React mistakes
- **[MEDIUM][react-antipattern] Camera scanner captures a stale `jobs` snapshot** — `frontend/src/pages/ProductionQueuePage.tsx:244` (guard :126, realtime refetch :112) — the scan callback is registered once and closes over the render-time `onScan`, whose membership guard reads `jobs` from when the camera started. — Jobs arriving via realtime after camera-on get a false "not on the queue" reject. — Hold latest `onScan` in a ref, or read `useQueueStore.getState().jobs` inside `onScan`.
- **[MEDIUM][react-antipattern] DesignerCanvas disposes & recreates the fabric canvas on every resize** — `frontend/src/components/DesignerCanvas.tsx:295` (dispose :292, ResizeObserver :155) — effect keyed on `[dims.w,dims.h]` tears down + rebuilds the canvas on any resize/rotate; nothing re-adds the user's placed logo. — Resizing mid-design wipes all objects while the parent's `hasLogo`/price state persists → price bar advertises a logo, export is blank. — Keep one canvas and `setDimensions()`+rescale, or snapshot `toJSON()`/`loadFromJSON` across recreation.
- **[LOW][react-antipattern] Unhandled rejection in JobLineDetail artwork-preview fetch** — `frontend/src/pages/ProductionQueuePage.tsx:706` — `void fetchArtworkPreviewUrl(ref).then(...)` has no `.catch`. — Unhandled rejection + preview silently stays null. — Add `.catch(() => active && setArtworkUrl(null))`.
- **[LOW][react-antipattern] QR-generation promises left unhandled** — `frontend/src/components/JobLabel.tsx:15` + `TrackingQr.tsx:16` — both `QRCode.toCanvas(...)` omit `.catch`; for JobLabel a reject means `window.print()` never fires → blank label modal, no error. — Chain `.catch` on both.

### Navigation
- **[MEDIUM][navigation-ux] Storefront header shows staff shortcuts without permission filtering** — `frontend/src/components/SiteHeader.tsx:65` (mobile :461) — Production/Procurement/Catalogue links gated only on `isStaffRole`; those routes require granular permissions and `ProtectedRoute` bounces the ungranted to `/dashboard`. — A restricted staff_admin sees dead-end links (contradicting the correctly-filtered sidebar). — Wrap each staff link in `hasPermission(user, …)`.
- **[MEDIUM][navigation-ux] Staff order-detail page has no breadcrumb, back link, or active nav** — `frontend/src/pages/QuoteDetailPage.tsx:848` — breadcrumb gated `{!isStaff && …}`; `/orders/:reference` never matches the `/quotes` sidebar item, so nothing highlights and there's no back link. — Staff have zero wayfinding except browser Back. — Add a staff "← Back to Quotes" link; optionally custom `isActive` so `/orders/*` lights Quotes.

---

## Backend

### Query & big-data
- **[HIGH][n+1] Staff quotes index fires one proofs COUNT per price-waiting quote** — `app/Http/Controllers/QuoteController.php:37` (`QuoteResource.php:91`; `ReminderSchedule.php:63,103`) — the staff branch eager-loads only `company`; `reminderSummary()→awaitingCount()` finds `proofs` unloaded and runs a fresh `proofs()->where('state','SENT')->count()` per SENT/ARTWORK_APPROVED row (20/page). — Up to ~20 extra COUNTs per page on a busy console; also drops the proof-chase reminder for PROOFING rows. — Eager-load `proofs` in the staff branch.
- **[LOW][missing-pagination] Open supplier reorders loaded with unbounded `->get()`** — `app/Http/Controllers/AdminReorderController.php:36` — index returns every non-RECEIVED reorder, no limit; the set is auto-drafted per under-threshold variant. — Payload/memory grow linearly with backlog. — Paginate with a capped `per_page`.

### Data integrity
- **[CRITICAL][data-integrity] `amend()` rebuilds subtotal from `unit_price*qty`, silently dropping setup & customization fees** — `app/Services/QuoteService.php:408` (accum :346/:361; `LineItem::lineTotal()` = `bcmul(unit_price,qty)`) — the create-time subtotal (`PricingService.php:171,188`) bakes in per-line customization/size/text/UV fees + a quote-level setup fee that `unit_price` excludes; amend re-sums bare lines and assigns, so `subtotal != sum(unit_price*qty)`. Runs unconditionally even for delivery/notes-only saves. `retotalAfterReconfirm` (:1014) does it correctly via a delta. — Every amend of a fee-bearing quote **under-charges the buyer by the full fees** and re-anchors any existing invoice down. — Retotal via `PricingService::quoteTotals`, or carry fees as a delta.
- **[MEDIUM][data-integrity] `amend()` re-includes DROPPED line items in the recomputed subtotal** — `app/Services/QuoteService.php:346` (drop is a state, not soft-delete, :967) — dropped lines are still returned by `lineItems()` and summed back in. — Superadmin post-procurement amend **over-charges** for dropped goods and re-issues an inflated invoice. — Skip `line_state ∈ {Dropped,Cancelled}` when accumulating.
- **[LOW][data-integrity] Non-blocking QTY_SHORT advisory marks a line READY without decrementing stock** — `app/Services/Procurement/ProcurementManager.php:134` — with `block_on_qty_short` off, QtyShort → `onAdvisory()` sets `procured_qty=qty`→READY, but strategies return QtyShort before any consumption; no reorder drafted. — `stock_on_hand`/filament stay overstated; no shortfall reorder fires. — Consume available stock + backorder/draft reorder, or document that on-hand is intentionally unmaintained here.

### Cache
- **[MEDIUM][cache] `PricingConfig` request-memo never flushed in long-lived workers, pinning stale config** — `app/Models/PricingConfig.php:78` (flush only from tests) — a process-global `static $memo` short-circuits before the 30s `Cache::remember`; a `queue:work` worker holds first-read values for its whole life; under Octane it pins all quote pricing per worker. — Admin edits to `ip_blocklist`/`auto_publish`/pricing never reach a running worker. — Wire `flushMemo()` into Octane `RequestReceived`/queue `JobProcessing`, or drop the static memo for the Cache layer.
- **[LOW][cache] Absent `PricingConfig` keys re-queried every request** — `app/Models/PricingConfig.php:79` — `Cache::remember` treats a null result as a miss and won't persist it, so genuinely-absent keys hit the DB each request. — Minor extra indexed SELECT per request; no correctness issue. — Cache a sentinel for absent keys, or leave as-is.

### Test/stub-data leak
- **[HIGH][test-data-leak] NinjaVan "live" client binds but base_url defaults to the SANDBOX host** — `config/services.php:124` (`AppServiceProvider.php:125`; `HttpNinjaVanClient.php:34,56`) — the provider selects the live client the moment `client_id`+`secret` exist, but base_url defaults to `api-sandbox.ninjavan.co/sg`; sandbox returns 2xx, echoes the tracking ref, and the job transitions to Shipped. — A deploy that sets creds but forgets `NINJAVAN_BASE_URL` **marks orders SHIPPED with real-looking tracking and emails customers, but no parcel moves.** — Default base_url to production, or fail closed when creds are present while base_url points at `*-sandbox.*`.
- **[MEDIUM][test-data-leak] `AdminUserSeeder` provisions superadmin/staff_admin with a committed default password, no env guard** — `database/seeders/AdminUserSeeder.php:34` (auto-run by `DatabaseSeeder.php:20`) — unconditionally seeds `superadmin@giftlab.local`/`ops@giftlab.local` with `Hash::make('ChangeMe!123')`; only mitigation is a doc-comment. — `php artisan db:seed` on prod creates highest-privilege accounts with a **repo-public password**. — Random password (log once / force reset), or env var with no default, or guard to local/testing.
- **[LOW][test-data-leak] `MarketplaceRechecker` unconditionally bound to the Fixture** — `app/Providers/AppServiceProvider.php:71` (`FixtureMarketplaceRechecker.php:31`) — bound with no credential/env gate (unlike payment/courier); `recheck()` returns the product's own stored estimate, not a marketplace read, though the contract calls it authoritative. — Procurement re-check always "confirms" existing figures, never catching real OOS/price drift. — Gate the fixture to local/testing or surface a "not re-checked" state.

### Logic
- **[MEDIUM][logic-bug] CSV product import fatals on any row with blank print_method/stock_mode** — `app/Http/Controllers/AdminProductController.php:516` — `prepareImportRow()` defaults via `PrintMethod::Fdm`/`StockMode::MakeToOrder`, but neither enum is imported (use-list :7-24), so they resolve to non-existent `App\Http\Controllers\*`. — A superadmin import 500s in Pass-1 the moment any row leaves those blank — the very case meant to default. — Add `use App\Enums\PrintMethod;` and `use App\Enums\StockMode;`.

---

## Top fixes now
1. **`amend()` fee-preserving retotal** — `QuoteService.php:408` (critical buyer undercharge / invoice re-anchor).
2. **NinjaVan base_url fail-closed** — `config/services.php:124` (fake fulfilment marked SHIPPED).
3. **Eager-load `proofs` in staff quotes index** — `QuoteController.php:37` (N+1 + missing proofing reminder).
4. **Add PrintMethod/StockMode imports** — `AdminProductController.php:516` (import 500).
5. **Randomize/guard `AdminUserSeeder`** — `AdminUserSeeder.php:34` (public superadmin password).
6. **`amend()` skip dropped lines** — `QuoteService.php:346` (overcharge on post-procurement amend).
7. **Wire `PricingConfig::flushMemo` into runtime** — `PricingConfig.php:78` (stale config in workers/Octane).
8. **Permission-filter SiteHeader staff links** — `SiteHeader.tsx:65` (dead-end redirect links).
9. **Camera-scan ref for latest `onScan`** — `ProductionQueuePage.tsx:244` (false scan rejects).
10. **DesignerCanvas preserve objects across resize** — `DesignerCanvas.tsx:295` (design wiped on resize).

## Overall read
Frontend issues are mostly hygiene (duplicate renders, unhandled QR/preview promises, nav gaps) plus two real state bugs (canvas dispose, stale scan closure). The serious risk is concentrated in the **backend money/inventory path** — `amend()` alone carries three distinct subtotal defects (one critical) — and in **fail-open integration/seed defaults** (NinjaVan sandbox, committed admin password) that present test/stub behavior as production-real.
