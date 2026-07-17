# Public landing page rework — design

Date: 2026-07-17
Status: approved for planning

## Problem

`HomePage.tsx` duplicates navigation that already exists in `SiteHeader`, and
merchandises nothing. Concretely:

- **Search appears three times.** Hero form (`HomePage.tsx:72`), header form
  (`SiteHeader.tsx:86`, hidden below `lg:`), mobile drawer form
  (`SiteHeader.tsx:347`).
- **Category links appear three times.** Hero "Browse:" chips, first 4 only
  (`HomePage.tsx:88`); "Shop by category" tile grid, all 8 (`HomePage.tsx:107`);
  header `CategoriesMenu` dropdown, all 8 (`SiteHeader.tsx:171`); mobile drawer,
  all 8 (`SiteHeader.tsx:354`). Twelve category links on one desktop viewport.
- **Two product sections resolve to the same endpoint.** "New arrivals" is
  `fetchCatalogue({sort:'newest'})`; "Featured gifts" is `fetchCatalogue({})`,
  which is the name-ordered first page. The existing code comment at
  `HomePage.tsx:159` already concedes the label is not backed by a popularity
  signal.
- **Two B2B-native routes are unreachable.** `/kits` (`App.tsx:150`) and
  `/gift-ideas` (`App.tsx:151`) are routed but linked from no nav, no drawer,
  and no page.

The audience is B2B: companies ordering bulk. Supporting evidence in-repo —
`brand-kit`, `quotes`, `procurement`, `production-queue`, `kits` routes, and
`min_order_qty` on the product type.

## Data constraints (verified, not assumed)

These bound the design. Do not design rows the API cannot feed.

| Need | Reality |
|---|---|
| Popularity / top sales | **Does not exist.** `CatalogueSort = 'name' \| 'newest' \| 'price_asc' \| 'price_desc'` (`frontend/src/lib/catalogue.ts:4`). No `featured` or `top-sales` param, public or staff. Commit 267924e's "top-sales auto-load" is the staff recommender defaulting to Shopee's top-sellers — not a signal for our own catalogue. |
| Catalogue listing | `fetchCatalogue(query)` → `Paginated<Product>`, `{ data, meta: { current_page, last_page, total } }` (`catalogue.ts:15`, `types.ts:410`). Params: `page, category, q, sort`. |
| Gift ideas | Public `GET /gift-ideas` (`routes/api.php:56`). Returns **Shopee affiliate offers**, not our products: `name, image_url, offer_link, price, currency, shop_name` (`GiftIdeasPage.tsx:8`). Not addable to a quote. |
| User quotes | `fetchQuotes(page)` → `Paginated<Quote>` (`frontend/src/stores/quoteStore.ts:62`); `GET /quotes` is auth-required (`routes/api.php:96`). No reorder-from-quote helper exists; `ReorderBuyListPage` is a supplier buy-list, unrelated. |
| MOQ | `min_order_qty?: number` exists on `Product` (`frontend/src/types.ts:132`). `ProductCard` does not render it (`ProductCard.tsx:55`). |
| Tier pricing | `fetchTierPrices(productId, variantId, quantities)` → `TierPrice[]` (`catalogue.ts:59`). Per-product, on-demand — too expensive for a card grid. Not used on home. |

**Consequence:** no "Top sales" row. The honest set of rows is category
navigation, newest, and a paginated browse grid. "Featured gifts" is deleted
rather than renamed.

## Decisions

1. **Home is one page with personalised rows**, not two pages and not a
   redirect. Logged-in buyers get one extra row; everything else is shared.
2. **Gift ideas stay on `/gift-ideas`.** They are outbound affiliate links; a
   home row would send buyers off the marketplace mid-browse. Nav link only.
3. **Track order moves to the footer.** Post-purchase intent, mostly reached
   from an emailed link. It frees the nav slot that Kits and Gift ideas need.
4. **MOQ renders on product cards.** Highest-value B2B signal available, and it
   costs one field that is already on the wire.

## Design

### Rows

Top to bottom, logged-out:

1. **Category rail** — the existing 8 `CATEGORIES` (`frontend/src/lib/categories.ts:12`)
   as a single horizontal band of icon + label. Replaces the "Shop by category"
   tile grid. Same links, but sized as navigation furniture rather than a
   headline section, so it stops competing with the header dropdown.
2. **Promo pair** — two static tiles: "Build a kit" → `/kits`, and "Bulk
   pricing" → `/products`. Hardcoded content; no CMS.
3. **New arrivals** — `fetchCatalogue({ sort: 'newest' })`, first 8, in a rail.
4. **Browse grid** — `fetchCatalogue({ page })`, "Load more" button appending
   pages until `meta.current_page === meta.last_page`. Page size is
   server-controlled at 24/page (`catalogue.ts:16`); we only advance the page.

Logged-in: identical, plus **Reorder from a past quote** inserted above New
arrivals, showing up to 3 most recent quotes, each linking to `/quotes/:id`.
Hidden entirely when the buyer has no quotes, so new accounts see exactly the
logged-out shelf.

It does **not** use `quoteStore.fetchQuotes`: that action returns `void`, writes
into store state consumed by `QuoteListPage`, and parks errors in a shared field
(`quoteStore.ts:62-75`) — wrong shape for an optional silent row. A new
`fetchRecentQuotes(limit)` helper mirrors the existing best-effort `fetchRelated`
pattern (`catalogue.ts:30-35`) and resolves to `[]` on any failure.

Deleted: the hero block (`HomePage.tsx:58-99`) in full — headline, search form,
and "Browse:" chips. The "Featured gifts" section (`HomePage.tsx:161-210`).

### Header

`SiteHeader` public nav becomes: Products, Categories▾, Kits, Gift ideas. Four
items, matching today's width. Removed from nav: Track order (to footer). Brand
kit and the staff link cluster keep their existing conditional logic unchanged.

Search: the header form loses `hidden lg:block lg:w-56` (`SiteHeader.tsx:86`)
and becomes visible from `md:` up, flexing to fill available width. It is the
only search on the page. Mobile drawer search is unchanged — the drawer replaces
the header nav at that breakpoint rather than duplicating it.

Mobile drawer gains Kits and Gift ideas links; keeps Track order (no footer
competition on mobile); keeps its 8 category links, which are the drawer's whole
purpose and not a duplicate of anything visible at that breakpoint.

### Components

`HomePage.tsx` is 288 lines today and would roughly double. `NewArrivalsRail`
and `RailButton` are already generic carousel machinery living inside the page
file.

Extract to `frontend/src/components/home/`:

- **`ProductRail.tsx`** — `{ items: Product[]; label: string }`. The existing
  `NewArrivalsRail` + `RailButton` moved verbatim and renamed, with the button
  `aria-label` built from `label` rather than hardcoded to "arrivals". Consumed
  by New arrivals.
- **`CategoryRail.tsx`** — no props. Renders `CATEGORIES` as the icon band.
- **`ReorderRail.tsx`** — no props; owns its own `fetchQuotes(1)` call and
  renders null when the list is empty or the request fails. A failed reorder
  fetch must not surface an error or block the shelf below it. It renders quote
  summaries, not products, so it does not use `ProductRail` — at most 3 items it
  needs no carousel, just a row.

`HomePage.tsx` retains composition plus the catalogue fetch and "Load more"
pagination state — roughly 90 lines.

### Card change

`ProductCard` gains MOQ display under the existing `showMeta` flag: when
`showMeta` is set and `product.min_order_qty` is present and greater than 1,
render "Min. N units" beside the category badge. Absent or `1` renders nothing —
no "Min. 1 units" noise. No new prop; `showMeta` already gates the metadata row
and every home caller passes it.

### Error and loading states

- Catalogue fetch failure: existing `ErrorState` with retry, as today
  (`HomePage.tsx:186`).
- Empty catalogue: existing `EmptyState`, as today (`HomePage.tsx:188`).
- New arrivals: the current "hide the whole section when empty" behaviour is
  kept (`HomePage.tsx:130`) — a bare heading over a blank rail reads as broken.
- Reorder rail: silent — renders null on empty or error. A logged-in buyer with
  no quotes must not see a broken or apologetic row.
- "Load more": button shows a pending state during fetch and is removed at the
  last page.

## Testing

`HomePage.test.tsx` splits along the new component seams:

- `ProductRail.test.tsx` — edge buttons disable at start and end; renders one
  card per item.
- `CategoryRail.test.tsx` — renders 8 links with correct `?category=` hrefs.
- `ReorderRail.test.tsx` — renders null with no quotes; renders null when
  `fetchQuotes` rejects; renders at most 3 quotes when more exist.
- `HomePage.test.tsx` — no search input in the document; no "Featured gifts"
  heading; reorder rail absent when logged out and present when logged in with
  quotes; "Load more" appends a page and disappears on the last page.
- `SiteHeader.test.tsx` — Kits and Gift ideas links present; Track order absent
  from desktop nav and present in the drawer; search input visible at `md:`.
- `ProductCard.test.tsx` — MOQ shown when `min_order_qty > 1` and `showMeta`;
  hidden when `min_order_qty` is 1, absent, or `showMeta` is false.
- `SiteFooter.test.tsx` — Track order link present.

## Out of scope

- Any popularity, top-sales, or recommendation signal. Needs backend work and a
  data source that does not exist; a separate spec if wanted.
- Tier pricing on cards. `fetchTierPrices` is per-product and on-demand;
  batching it is backend work.
- Occasion or audience taxonomy ("gifts for clients", "onboarding kits"). Needs
  curation tooling and an owner. The row list above is additive — this can land
  later as one more rail.
- CMS-managed promo tiles. Hardcoded for now.
