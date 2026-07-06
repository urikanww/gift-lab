# Catalogue Control Overhaul — Design

**Date:** 2026-07-06
**Status:** Approved for planning
**Goal:** Give superadmin easy control over catalogue data — broad 3D intake, fast
navigation, a professional product-management surface (list / create / detail-edit),
bulk publishing, and licence-risk visibility. Plus fix the same navigation/reload
issues across production and procurement.

---

## Context

The catalogue is transitioning from a keyword-scoped 3D discovery flow to broad
intake. Licence rules were just loosened (all Creative Commons variants + open-source
families are commercial-OK; only "all rights reserved"/unknown/paid stay `BLOCKED`).
The product-management page (`/product-admin`) now lists all classes with
archive/restore, but create + list are mixed on one page, there is no detail/edit
surface, no pagination/sort/filter, and the catalogue gate lives in the main nav.

Sources (Thingiverse, Cults3D) are **search-only** — there is no "give me everything"
firehose — so "all models flow in" is implemented as a keyword-less **popular browse**
sweep, capped per night, with the operator curating (activate/deactivate) afterward.

### Current-state facts (verified this session)

- `StaffLayout` `<aside>` is not sticky → on long pages the nav scrolls away.
- `queueStore.advance()` calls `fetchQueue()` which flips `loading:true` → skeleton +
  scroll jump (same class of bug fixed in `catalogueAdminStore`). Realtime socket
  already updates jobs in place, so the refetch is a safety net.
- Procurement (`procurementStore.reconfirm`) mutates the alert list in place — clean,
  no full reload; layout uses flexible grid columns — no overflow. No change needed.
- `AdminProductController::index` already paginates (`current_page/last_page/total`)
  and lists all classes with `status` (active/archived/all) + `class` filters, ordered
  by name only.
- Product model has `SoftDeletes` (cascades to variants). `license` columns are now
  plain strings (app-level `App\Enums\License` is the source of truth).
- `LineItem` has `product_id` + `qty`; `Quote` has a `state` — enables "most sold".

---

## Architecture decision

**Server-driven product list.** Pagination, sort, and filter are API query params;
the client renders one page at a time. Rationale: browse intake will grow the catalogue
to thousands of MODEL_3D rows; fetch-all-and-filter-in-browser would not scale.

---

## Phase 1 — Navigation & shared fixes

### 1.1 Sticky staff sidebar
`StaffLayout` `<aside>`: `sticky top-0 h-screen` with `overflow-y-auto` on the nav
region so it stays visible while main content scrolls. Fixes navigation on every staff
page (production, procurement, catalogue, products).

### 1.2 Production queue silent refetch
`queueStore.fetchQueue` accepts `{ silent }`; when silent (or when `jobs` already
populated) it does not set `loading:true`. `advance()`'s post-mutation refetch becomes
silent → no skeleton flash / scroll reset. Error-path refetch also silent.

### 1.3 Catalogue gate out of main nav
Remove the `Catalogue Gate` entry from `useStaffNav`. Route `/catalogue-admin` stays;
it is reached from a button on the Products list page (Phase 4).

---

## Phase 2 — 3D intake: popular browse, keywords optional

### 2.1 Client browse mode
`Model3dApiClient` gains a browse capability: fetch the source's **popular** feed with
no query, paginated. Thingiverse `/popular` (or `/things?sort=popular`); Cults3D popular
listing via its GraphQL sort. Returns ids the same shape as `search()`.

### 2.2 `pull-3d` browse flag
`catalogue:pull-3d` gains `--browse=popular` (mutually exclusive with a query argument).
In browse mode it pages the popular feed until `--count` commercial-OK items ingest or a
hard page cap is hit. Licence-blocked / IP-held items do not count toward `--count`.

### 2.3 `discover-3d` default sweep + keyword fallback
- Default (no args): one browse sweep per source, capped by config
  `catalogue/browse_cap` (default **200 per source per night**).
- `--keywords`: optional fallback that runs the legacy keyword loop over
  `catalogue/discovery_keywords` instead of the browse sweep.
- `discovery_keywords` config is retained (used only when `--keywords` is passed).

### 2.4 Cap config
New `PricingConfig` `catalogue/browse_cap` (int, default 200), superadmin-editable via
the pricing editor. Guards API rate limits; operator can scale it.

---

## Phase 3 — Licence risk labels (superadmin-only)

### 3.1 Licence tier
Add `License::tier(): LicenseTier` (or a controller-side map) with three tiers:
- `standard` — CC0, CC-BY, OWNED
- `extended` — CC-BY-SA, GPL, LGPL, BSD, MIT, APACHE_2
- `high_risk` — CC-BY-NC, CC-BY-ND, CC-BY-NC-SA, CC-BY-NC-ND

`AdminProductController::serialize` includes `license_tier`.

### 3.2 Frontend badges
Product list + detail render, **only when `role === 'superadmin'`**:
- `extended` → neutral badge "Extended licence".
- `high_risk` → red badge "High legal risk".
`standard` shows no badge. Public storefront never shows these.

---

## Phase 4 — Product management rework

### 4.1 Routes
- `/product-admin` — list (default).
- `/product-admin/new` — create (moved off the list page).
- `/product-admin/:id` — detail / edit.
All under the existing `staffOnly` guard.

### 4.2 List page (`/product-admin`)
Server-driven table:
- **Pagination** — page controls bound to `current_page/last_page/total`.
- **Sorts** (`sort` param): newest (`created_at desc`, **default**), most sold, name,
  base cost, stock. Toggle asc/desc.
- **Filters**: class, publish state, licence tier, category, archived (active/archived/all),
  text search (`q` on name).
- **Row click** → `/product-admin/:id`.
- Header actions: **"New product"** → `/product-admin/new`; **"Catalogue gate"** →
  `/catalogue-admin`.
- Each row: thumbnail, name, class badge, licence-tier badge (superadmin), publish state,
  sold count, base cost.

### 4.3 Create page (`/product-admin/new`)
The existing `CreateProductForm`, standalone. On success → redirect to the new product's
detail page.

### 4.4 Detail / edit page (`/product-admin/:id`)
Professional editor, full superadmin control:
- Editable: name, description, base cost, category, print method, stock mode,
  dimensions (l/w/h), weight, publish state (publish / set inactive), archive / restore.
- **Image**: upload a new image (stored on the public disk, mirroring CORE seed images) and
  remove the current image. New endpoints `POST /admin/products/{id}/image` (multipart) and
  `DELETE /admin/products/{id}/image`.
- Variants: create / edit stock + price delta for **CORE** only (non-CORE variants come
  from source); read-only note for non-CORE.
- Shows licence, source, licence-tier badge, sold count, timestamps.

### 4.5 Catalogue gate rework (`/catalogue-admin`)
- **Multi-select**: per-row checkbox + "select all eligible" (only `READY_TO_APPROVE`
  rows are eligible; the header checkbox selects those).
- **Bulk publish**: new `POST /admin/products/bulk-publish` accepting `{ ids: number[] }`;
  server publishes each through the full gate (`AdminCatalogueController`/service), returns
  a per-item result `{ id, ok, error? }`. UI shows a summary toast (published N, failed M).
- **Row click** → `/product-admin/:id` (view/edit). Publish/unpublish buttons remain for
  single-row actions.
- Keeps the existing silent-refetch behaviour after mutations.

---

## Backend additions (summary)

- `AdminProductController::index`: add `sort` (most_sold|newest|name|base_cost|stock, with
  direction), `q` (name search), `publish_state`, `category` params. Add `sold_count`
  (Σ `line_items.qty` where the parent quote is in a won state) via subquery/withSum, and
  `license_tier`, to `serialize`.
- `POST /admin/products/bulk-publish` — bulk publish with per-item results.
- `POST /admin/products/{product}/image` + `DELETE /admin/products/{product}/image`.
- Model3d browse client method + `pull-3d --browse` + `discover-3d` default sweep /
  `--keywords` fallback + `catalogue/browse_cap` config.

"Won state" for sold_count: quotes that reached an accepted/confirmed-or-later state
(ACCEPTED, CONFIRMED, IN_PRODUCTION, SHIPPED, DELIVERED, CLOSED — exact enum values
confirmed against `QuoteState` during implementation). Draft/expired/cancelled excluded.

---

## Testing

- **Feature**: list sort (most_sold ordering), filter combinations, pagination bounds,
  text search; bulk-publish with mixed eligibility (some READY, some blocked) → correct
  per-item results; image upload then remove; licence-tier tagging per licence; browse-mode
  ingest (fake popular feed) respects the cap and licence gate.
- **Unit**: `License::tier` mapping.
- **In-browser**: sticky sidebar on a long page; production `advance` keeps scroll / no
  skeleton; product list filter/sort/paginate; detail edit + image remove; gate multi-select
  bulk publish.

---

## Out of scope

- Public storefront changes (licence badges are superadmin-only).
- Procurement changes (already clean).
- Changing the licence policy itself (settled in the prior change).
- Bulk actions beyond publish (e.g. bulk archive) — can follow later.

---

## Rollout / phasing

Phases are independent and shippable in order: **1** (nav + shared fixes) → **2** (intake)
→ **3** (labels) → **4** (management rework). Phase 4 is the largest; its list, create,
detail, and gate pieces can each land incrementally behind the new routes.
