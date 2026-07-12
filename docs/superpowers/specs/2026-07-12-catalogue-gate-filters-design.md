# Catalogue Gate — Advanced Filters + Shared Filter UI — Design

**Date:** 2026-07-12
**Status:** Draft for review
**Surface:** `CatalogueAdminPage` (route `/catalogue-admin`), `AdminCatalogueController::index`

## Problem

The catalogue gate (`CatalogueAdminPage`) has only inline `search / class / state /
sort` filters held in **local component state** — filters are lost on refresh or
back-nav, can't be shared by URL, and the production team can't slice the gate by
the things that actually matter to them (what's blocking an item, where it came
from, its print track). Meanwhile `ProductAdminPage` already has the target
pattern (URL-param filters, a Filters modal, removable chips, a count pill,
clear-all) — but that UI lives inside `ProductAdminPage` and would be
copy-pasted.

## Goals

1. Bring the **Filters modal + removable chips + clear-all** pattern to the gate.
2. Migrate the gate's filter state to **URL params** (shareable, refresh-safe),
   matching `ProductAdminPage`.
3. **Extract** the chips/pill/modal-shell into a **shared component** used by both
   admin pages (no copy-paste, no drift).
4. Add production-focused filters: **blocker reason, source, print method,
   category, IP-flagged, missing buy link**.

## Non-goals

- No change to the gate's data scope: it stays **SCRAPED_UV + MODEL_3D only**
  (CORE lives in `ProductAdminPage`). We share the filter *shell*, not the query.
- No new sort keys beyond the existing `newest / name / base_cost`.

## Dependency

**Source** and **missing buy link** filters read `products.source_links`, added by
the [UV Blank Library Phase 1 plan](../plans/2026-07-12-uv-blank-library-phase1.md)
(Task 1). Sequence: land Phase 1 Task 1 (the column) before those two filters;
the other filters (blocker, print method, category, IP) have no such dependency.

## Filters

| Filter | Param | Backend derivation |
|---|---|---|
| Blocker reason | `blocker` | `whereJsonContains('cannot_publish_reasons', $blocker)` |
| Source (normalized) | `source` | derive from `source_url` host → one of `marketplace \| local \| makerworld \| thingiverse \| cults3d \| manual` |
| Print method | `print_method` | `where('print_method', $v)` (UV/FDM/RESIN) |
| Category | `category` | `where('category', $v)` |
| IP-flagged | `ip_flagged` | `where('ip_flagged', true)` when `=1` |
| Missing buy link | `missing_link` | when `=1`: `SCRAPED_UV` AND (`source_links` null or `[]`) |

### Source normalization

A single derivation shared by filter + display. Host → label:

- contains `shopee.` / `lazada.` / `amazon.` / `aliexpress.` / `qoo10.` → `marketplace`
- contains `makerworld` → `makerworld`; `thingiverse` → `thingiverse`; `cults3d` → `cults3d`
- empty `source_url` → `manual`
- otherwise → `local`

Implemented once in PHP (a `SourceKind::fromUrl()` helper or a method on the
existing `SourceLinks` support class) and reused by the query filter. The frontend
sends the label; the backend maps the label back to a host predicate (e.g.
`marketplace` → `where(host in shopee/lazada/…)`), because `source_url` stores a
full URL, not a label.

> **Implementation note:** filtering by derived source needs a host predicate in
> SQL. Options: (a) a raw `where` on `source_url LIKE` per host in the label's
> set; (b) add a persisted `source_kind` column populated on save (cleaner, and
> indexable). **Recommend (b)** — a `source_kind` column kept in sync in the
> Product saving hook (same place `source_url` is derived), so the filter is a
> plain indexed `where('source_kind', $label)` and the label is also available to
> the row payload for display. Migration + backfill included in the plan.

## Counts correctness

The summary badges come from the `$byState` breakdown query, which today honors
`class` + `search` but **not** `state`. **Every new filter must be applied to both
`$byState` and the paginator** (except `state`, which is intentionally excluded
from `$byState` so the badges show the whole filtered set). Otherwise the badge
totals contradict the visible rows.

## Shared component

Extract from `ProductAdminPage` into `frontend/src/components/admin/Filters.tsx`:

- `CountPill` — the numeric badge on the Filters button.
- `FilterChips` — removable active-filter chips + a "Clear all" button. Props:
  `chips: {key,label}[]`, `onRemove(key)`, `onClear()`.
- `FiltersModal` — the modal shell (title, footer with Clear/Done). Body is passed
  as children so each page supplies its own field set.

`ProductAdminPage` is refactored to consume these (behaviour unchanged);
`CatalogueAdminPage` consumes the same. Pure presentational — testable with Vitest.

## Data / API changes

- `AdminCatalogueController::index` — accept + apply `blocker, source,
  print_method, category, ip_flagged, missing_link`; add them to `$byState`;
  return `source_kind` in each row payload (for a Source chip/column).
- `catalogueAdminStore.fetch` — extend the params object with the new keys.
- `AdminCatalogueItem` type — add `source_kind`.

## Testing

- **Backend (Pest):** each filter narrows the set; `$byState` counts respect the
  new filters; `missing_link` matches only SCRAPED_UV with empty `source_links`;
  `source` maps label → correct rows. Source-kind derivation unit-tested.
- **Frontend (Vitest):** `FilterChips` renders + removes + clears; source-kind
  label mapping; chip list derives from URL params.
- **Regression:** `ProductAdminPage` filter behaviour unchanged after extraction.

## Rollout

Order: (1) shared component extraction + `ProductAdminPage` refactor (no behaviour
change), (2) gate URL-param migration + modal/chips, (3) backend filters + counts,
(4) source/missing-link filters after Phase 1 Task 1 lands.
