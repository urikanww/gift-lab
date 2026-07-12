# UV Blank Library + Buy-Per-Order Sourcing — Design

**Date:** 2026-07-12
**Status:** Draft for review
**Class touched:** `SCRAPED_UV`

## Problem

We want a catalogue of UV-printable **blanks** (mugs, tumblers, phone cases,
keychains, coasters, totes…) that customers customise in the existing self-serve
designer and order — B2C small orders and corporate bulk. We do **not** want to
hold inventory.

The initial instinct was to scrape Shopee SG (like the MakerWorld pipeline) or
use the Shopee Affiliate API. Both were investigated and **rejected as the
sourcing model** for concrete reasons:

- **Affiliate API** returns name/price/image/link only — **no variants, no stock,
  no dimensions, no weight, no print area**. It also *requires* displaying
  affiliate links (driving the customer to buy the raw blank on Shopee), which
  bypasses our customisation entirely and leaves staff idle. Using it purely as a
  private data feed risks account termination.
- **HTML scraping Shopee** fights heavy anti-bot, is fragile, still yields no
  specs, and contradicts the app's existing ToS stance.
- **Bulk wholesale** gives specs but forces inventory → warehouse/dead-stock risk
  the owner explicitly rejects.

The real model is **buy-per-order customisation with zero inventory**: we
customise a small, finite set of blank *types* repeatedly. That reframes the need
away from continuous scraping toward a **hand-curated blank library, built once
and refreshed occasionally**, sourced per order.

## Business model (decided)

| Area | Decision |
|---|---|
| Fulfilment | **No inventory. Buy the blank per order**, customise, ship. |
| Audience | B2C self-serve + corporate/assisted. |
| Catalogue | **Curated blank library** in the existing `SCRAPED_UV` gate. Specs entered **once per blank**; we define the variants/print-areas we offer, not the marketplace's. |
| Source links | **Multiple per blank** — local SG primary (speed) + marketplace plain-URL backups. |
| Discovery | **A: capture-on-browse** + **B: keyword candidate-puller**. No affiliate API, no affiliate page. |
| Procurement | Existing **buy-list + Mark received**. Worst-case-cost buffer (B2C) / buy-blanks-at-PO-acceptance (corporate). |
| Customisation | **Already built** — self-serve designer before add-to-cart. |
| Pricing | `blank + print + margin`. **No minimum order** (accepted small-order margin risk). Corporate adds an **artwork/design fee** + locks COGS at PO acceptance. |

### Non-goals

- No Shopee Affiliate API usage; no affiliate storefront/gallery.
- No bulk inventory / warehousing (a tiny buffer of top sellers is optional and
  out of scope here).
- No automated live-stock — the affiliate feed has no stock field and marketplace
  stock is only truthful at the moment of purchase. **Live stock is a human
  re-check at buy time** via the source link.
- No self-checkout bot on Shopee. Procurement stays a human purchase.

## Architecture

One pipeline, several intakes, reusing existing infrastructure:

```
Discovery                 Curation (once per blank)        Fulfilment (per order)
─────────                 ─────────────────────────        ──────────────────────
A capture-on-browse ─┐
                     ├─►  candidate ─► review/pick ─►      designer ─► cart ─► order
B keyword puller ────┘    (SCRAPED_UV gate:                     │
                           specs once, print-area,              ▼
                           multi source links)             buy-list: pick a source
                                                           link ─► buy ─► Mark received
```

- **Curation reuses** `ScrapedCatalogueService` + `CompletenessGate` (ingest,
  publish states, IP screen) — no new gate logic.
- **Fulfilment reuses** the existing buy-list (`ReorderBuyListPage` +
  `AdminReorderController`) and the self-serve designer.
- The **only genuinely new** pieces: the two discovery intakes (A, B), the
  multi-source-link data model, and small pricing rules.

## Components

### 1. Discovery A — capture-on-browse (primary)

Staff browse **any** site normally (Shopee, Lazada, **local SG suppliers**), and
capture a single listing into a library draft. Human-initiated, one page at a
time, on a page they are legitimately viewing → no mass-scraping, no anti-bot
fight, no affiliate account, and **works on local suppliers** a marketplace
scraper/affiliate feed cannot reach.

- Input: a product URL (paste into an admin field) — optionally a bookmarklet
  that posts the current tab's URL.
- Action: fetch **that one page** server-side and extract public fields
  best-effort: `name`, `price`, `image`, and where present `weight`/`dimensions`.
- Output: a **draft** `SCRAPED_UV` product in the gate for staff to complete.
- "Automated" = auto-extract the fields instead of retyping; staff still curate.

Single on-demand fetch per human action — low volume, low risk. Per-site
extractors are small and additive (start with Shopee/Lazada URL shapes + a
generic Open Graph fallback that covers most local supplier pages).

### 2. Discovery B — keyword candidate-puller (secondary)

When hand-picking is too slow, staff run an on-demand keyword search that returns
a **batch of candidates to skim and pick from** (not a live catalogue feed).

- Playwright, headed, reused profile (mirrors `browser-download.mjs` pattern).
- Per keyword: capture the marketplace's internal search JSON (+ DOM fallback),
  normalise to the **same candidate shape** A produces.
- Output: a candidate list; staff select which become library blanks (same
  review step as A).
- Scoped + occasional (curation, not continuous) → anti-bot risk manageable.
  Accepted as **fragile** — breaks when the marketplace changes markup; treated
  as a convenience, not a dependency.

Build **B only after A is in use** and hand-curation proves too slow.

### 3. Pre-filter (rides on A + B)

Auto-flag candidates to speed picking (advisory, never auto-reject):

- **IP/branded** — name matches a brandlist (Sanrio, Pokémon, Disney, artist
  names…) → reuse/extend the existing IP screen.
- **Non-UV material** — fabric/plush/food etc. → tag "likely not UV-printable".

### 4. Multi-source links (data model change)

Today a product has a single `source_url`. A blank needs several ranked buy
links. Add `source_links`:

```
source_links: [
  { label, url, kind: 'local' | 'marketplace', price, currency, last_checked }
]
```

- **Storage:** a JSON column `products.source_links` (cast `array`), or a
  `product_source_links` table if we want per-link history. **Recommend the JSON
  column** for v1 (simpler; matches `dimensions`/`print_zone` precedent).
- **Compatibility:** keep `source_url` as the derived *primary* link (first
  `kind:'local'`, else first entry) so the existing buy-list, `AdminReorderController:107`,
  and "View source" keep working with no change. `source_links` is additive.
- **Buy-list:** surface all links, primary highlighted, each with its last-seen
  price + `last_checked` and a "re-check price/stock before buying" caption.

### 5. Procurement + pricing rules

- **Buy-per-order** through the existing buy-list; staff pick a source link
  (local primary for speed), buy, **Mark received** restocks the order line.
- **COGS rule:**
  - **B2C:** quote off **worst-case blank cost** (max across `source_links`) +
    print + margin, so price drift can't make a job unprofitable.
  - **Corporate/bulk:** **buy blanks at PO acceptance** to lock COGS on volume
    (order-linked purchase, still no warehouse).
- **Print cost:** ink/film + machine + labour, ~fixed per blank size (staff
  config per blank or per size band).
- **No minimum order value** (owner decision). *Accepted risk:* qty-1 self-serve
  orders may under-recover fixed print prep; revisit if small orders lose money.
- **Artwork/design fee:** **corporate/assisted only** (staff create/lay-out/proof
  artwork). B2C self-serve = customer makes the artwork → **$0**.

## Data flow

1. **Curate:** A or B → candidate → staff review in gate → complete specs
   (dimensions, weight, print method `UV`, print area, print cost) + attach
   `source_links` → publish. Item is a reusable blank.
2. **Sell:** customer opens the blank → self-serve designer → add to cart →
   order.
3. **Fulfil:** order raises a buy-list task → staff open a source link → buy one
   → UV-print → **Mark received** → ship.

## Known limitations / accepted risks

- **Listing drift / vanish** between quote and purchase → mitigated by worst-case
  buffer, multiple source links per blank, and human re-check at buy time.
- **No minimum order** → small-order margin risk, explicitly accepted.
- **B is fragile** (marketplace markup changes) → convenience only; A and manual
  pick always work.
- **Overseas per-order is slow** → prefer local SG source links for lead time.

## Testing

- Unit: source-link primary derivation (JSON → `source_url` compatibility);
  worst-case-cost selection across links; pre-filter brand/material flags.
- Feature: capture-on-browse creates a draft `SCRAPED_UV` product from a URL;
  gate holds it `CANNOT_PUBLISH` until specs complete; buy-list renders multiple
  links with primary highlighted.
- Reuse existing `CompletenessGate` / `ScrapedCatalogueService` tests unchanged.

## Out of scope (possible later)

- Discovery C (affiliate API + compliant public gift-ideas gallery) — only if we
  later choose to run affiliate income as a **separate** line. Does not serve the
  UV business; noted, not built.
- Tiny buffer stock for top sellers (a shelf, not a warehouse).
- Automated price re-sync of source links (manual re-check is sufficient at this
  volume).
