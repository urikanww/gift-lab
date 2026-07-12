# Blank Recommender + Public Gift-Ideas Page — Design

**Date:** 2026-07-12
**Status:** Draft for review
**Depends on:** SCRAPED_UV gate, `HttpShopeeAffiliateClient`, `ScrapedCatalogueService::ingest` (all shipped)

## Problem

Production staff currently discover UV-printable blanks by hand (capture-on-browse
= paste a URL) — no recommendation. We want an **in-app staff recommender**:
type a keyword → get ranked candidate blanks from the Shopee Affiliate API →
"Add as blank" into the gate. Using the affiliate API for internal discovery
requires an **active, compliant affiliate presence**, so we also build a **public
gift-ideas page** that surfaces curated affiliate products (offerLink) and
cross-sells our customization.

This is Discovery B, affiliate-powered (the browser-scraper alternative was
rejected: it can only run headed on a laptop/non-datacenter IP, is fragile, and
can't be an in-app feature).

## Decisions (locked)

| Question | Decision |
|---|---|
| Recommender surface | Dedicated staff page `/blank-recommendations` |
| Gift-ideas data | **Curated from the recommender** (staff flag "feature publicly") |
| Cross-sell | **Yes** — "Personalize with us →" CTA per card |
| Scope | Recommender + gift-ideas page in **one plan** |

## ⚠️ Concerns (must be addressed by the build)

### Gift-ideas page
1. **Verify the program requirement FIRST (pre-build gate).** The page exists to
   keep the affiliate account compliant. Before building the public surface,
   confirm what Shopee's affiliate program actually requires (a live gallery vs
   periodic activity/conversions). If a lighter footprint suffices, scale the page
   down. *Owner action item, not code.*
2. **Cannibalization.** The page sends buyers to Shopee for the plain product.
   The "Personalize with us" CTA mitigates; accept the residual risk.
3. **Affiliate disclosure (legal).** A visible "contains affiliate links — we may
   earn a commission" disclosure is required on the page.
4. **Third-party IP.** Only display feed-provided assets, unmodified. **Exclude
   IP-flagged/branded items** from the public page (reuse the existing IP screen).
5. **Link hygiene.** Public page uses `offerLink` (affiliate, `rel="sponsored
   nofollow noopener"`). Procurement (buy-per-order) uses the plain `productLink`.
   **Never cross them** (self-referral rule). Never use `offerLink` for our own buys.
6. **Stale/dead data.** Featured products delist/change price → a scheduled refresh
   updates price + prunes dead links (`sourceDead`).
7. **Rate limits.** Never hit the affiliate API per page-load — the public page
   reads **cached/stored** featured rows only.
8. **SEO/brand.** Keep it small + curated; `rel="sponsored nofollow"` on all
   affiliate links; clear framing so it doesn't dilute the customization brand.

### Recommender / affiliate API
9. **No specs from feed** → added candidates land `CANNOT_PUBLISH`; staff complete
   dims/weight (unchanged gate behaviour).
10. **Account = data feed.** Suspension (non-compliance/self-referral) kills the
    recommender too — compliance protects everything.

## Architecture

```
Staff recommender (admin, reads affiliate API)
  keyword ─► searchCandidates() ─► rank + pre-filter (IP/material flags)
    ├─ "Add as blank"    ─► ScrapedProductData ─► ScrapedCatalogueService::ingest ─► gate
    └─ "Feature publicly"─► gift_idea_features row (offerLink, image, price, ip_flag)
                                     │
Public gift-ideas page (cached read of gift_idea_features, IP-safe) ◄──┘
  card: "Buy on Shopee" (offerLink) + "Personalize with us →" (our catalogue)
  + affiliate disclosure banner

Scheduled: giftideas:refresh ─► re-fetch each featured item ─► update price / prune dead
```

## Components

### Backend

**1. Affiliate client — richer candidate query.**
- Extend `HttpShopeeAffiliateClient` with `searchCandidates(keyword, limit): array<ShopeeCandidate>`.
- New DTO `App\Services\Scraper\ShopeeCandidate` (readonly): `itemId, shopId,
  sourceProductId ("{shopId}_{itemId}"), name, price, currency, imageUrl,
  productLink (plain), offerLink (affiliate), sales, ratingStar, shopName`.
- Extend the GraphQL query to select `sales, ratingStar, offerLink, shopName`.
- Leave existing `search()`/`fetch()` (ingest/resync path) untouched.

**2. Pre-filter** `App\Services\Catalogue\CandidateScreen`:
- `ipFlag(name): ?string` — brandlist match (reuse the existing IP screen brandlist
  if one exists; else a small list: disney, sanrio, pokemon, marvel, …).
- `materialFlag(name): ?string` — non-UV material keywords (fabric, plush, cotton,
  silicone-only, …) → "likely not UV-printable".
- Advisory in the recommender (shown, never auto-hidden). **Blocks the public page**
  (IP-flagged excluded).

**3. Recommender endpoints (staffOnly, under `auth:sanctum`):**
- `GET /admin/blank-recommendations?keyword=&limit=` → `searchCandidates` → rank by
  `sales` desc → attach ip/material flags → JSON.
- `POST /admin/blank-recommendations/add` `{source_product_id}` (+ candidate fields)
  → build `ScrapedProductData` → `ScrapedCatalogueService::ingest` → seed
  `source_links` with the **plain productLink** → return product (mirrors
  `AdminBlankCaptureController`).
- `POST /admin/blank-recommendations/feature` `{candidate}` → upsert a
  `gift_idea_features` row. `DELETE /admin/blank-recommendations/feature/{id}`.

**4. `gift_idea_features` table:** `id, source_product_id (unique), name, image_url,
offer_link, product_link, price, currency, shop_name, ip_flagged (bool),
sort (int), created_by, timestamps, soft-deletes`.

**5. Public endpoint:** `GET /gift-ideas` (public group, `throttle:60,1`) → cached
list of `gift_idea_features` where `ip_flagged = false` and not dead, ordered by
`sort`. Returns `{name, image_url, offer_link, price, currency, shop_name}` +
nothing that leaks internal ids.

**6. Refresh command** `giftideas:refresh`: for each featured row, `fetch()` via the
affiliate client → update `price`; if `sourceDead` → soft-delete (prune). Scheduled
daily in `routes/console.php`. Busts the public cache.

### Frontend

**7. Staff recommender page** `/blank-recommendations` (staffOnly route):
- Search box + limit → candidate grid: image, name, price, `sales`, `ratingStar`,
  `shopName`, IP/material flag badges.
- Per card: **Add as blank** (POST /add → toast → optional jump to gate) and a
  **Feature publicly** toggle (POST/DELETE /feature).
- Add a nav link from the catalogue gate.

**8. Public gift-ideas page** `/gift-ideas` (public, in `Layout`):
- Grid of featured products. Each card: image, name, price, **"Buy on Shopee"**
  (`offerLink`, `rel="sponsored nofollow noopener"`, `target="_blank"`) + **"Personalize
  with us →"** CTA (to `/products`).
- **Affiliate disclosure banner** at top.
- Reads `GET /gift-ideas` only (no direct API calls).

## Data flow

1. **Discover:** staff open `/blank-recommendations`, search a keyword, see ranked
   candidates with IP/material flags.
2. **Add:** "Add as blank" → gate draft (`CANNOT_PUBLISH`) → staff complete specs →
   publish (existing flow).
3. **Feature:** "Feature publicly" → `gift_idea_features` row (IP-flagged rows are
   allowed to be added but the public endpoint filters them out; UI warns).
4. **Public:** `/gift-ideas` renders cached featured rows with affiliate links +
   cross-sell.
5. **Maintain:** `giftideas:refresh` daily updates prices + prunes dead links.

## Non-goals

- No auto-keyword public feed (curated only).
- No affiliate link on any procurement/buy-per-order path (plain productLink there).
- No browser-scraper (rejected).
- The **program-requirement verification (#1)** is an owner action item, not code —
  but the gift-ideas page ships behind it: if the program needs less, scale down.

## Testing

- **Backend (Pest):** `searchCandidates` maps the richer node (mock Http);
  `CandidateScreen` ip/material flags; `/add` ingests to the gate with plain
  productLink in `source_links`; `/feature` upserts + `/gift-ideas` excludes
  IP-flagged + returns `offerLink`; `giftideas:refresh` updates price + prunes dead.
  Staff-gate on all admin endpoints; public endpoint needs no auth.
- **Frontend (Vitest):** recommender renders candidates + flag badges; public page
  renders disclosure + `rel="sponsored nofollow"` links + cross-sell CTA.
- **Env:** blank affiliate creds already isolated in `phpunit.xml`; tests mock Http,
  so no live calls.

## Rollout / sequencing (within the one plan)

1. Backend: DTO + client `searchCandidates` + `CandidateScreen`.
2. Recommender endpoints + `/add` + tests.
3. `gift_idea_features` table + feature endpoints + `/gift-ideas` public + refresh command.
4. Frontend recommender page.
5. Frontend public gift-ideas page + disclosure.
6. **Gate the public page's go-live on the owner verifying the program requirement (#1).**
