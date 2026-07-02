# Owner Actions — Gift Lab Launch Checklist

Compiled 2026-07-02. Everything below is one-time setup or a business decision.
Ongoing per-product manual work after this list: **zero** (items flow in nightly,
gates hold anything unverified for a single staff click).

---

## 1. Credentials to obtain (`.env`)

| # | Action | Where to get it | .env keys | What it unlocks |
|---|--------|-----------------|-----------|-----------------|
| 1 | Register **Shopee Affiliate Open Platform (SG)** | affiliate.shopee.sg → Open API application | `SHOPEE_AFFILIATE_APP_ID`, `SHOPEE_AFFILIATE_SECRET` | Live UV-blank feed: `catalogue:pull-uv "ceramic mug"`. ToS-clean product data (name/price/image/link) |
| 2 | Create **Thingiverse app token** | thingiverse.com/developers → create app | `THINGIVERSE_TOKEN` | Live 3D pull, nightly discovery sweep, STL file downloads to our storage |
| 3 | Create **Cults3D API key** | cults3d.com → account settings → API | `CULTS3D_USERNAME`, `CULTS3D_TOKEN` | Second 3D source. Note: no file-download API — their items wait in the admin gate until a model file is attached manually |
| 4 | (Already planned) **Stripe** secret when B2C pay-now goes live | dashboard.stripe.com | `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET` | Real payments; fixture gateway refuses to run outside local/testing |
| 5 | Register **Lazada Open Platform** affiliate app | open.lazada.com | `LAZADA_AFFILIATE_APP_KEY`, `LAZADA_AFFILIATE_SECRET` (+ confirm `LAZADA_AFFILIATE_SEARCH_PATH`/`ITEM_PATH` against your program's API console) | Second UV-blank feed: `catalogue:pull-uv "tumbler" --source=lazada` |
| 6 | Install **PrusaSlicer** on the server, set binary path | prusa3d.com (CLI: `prusa-slicer-console`) | `SLICER_BINARY` | Auto-measured grams/print-minutes → estimates auto-verified → true zero-touch publish (removes the last manual click) |
| 7 | **Anthropic API key** | console.anthropic.com | `ANTHROPIC_API_KEY` | LLM layer of the IP/trademark screen (keyword blocklist runs regardless) |

No code changes needed for any of these — each integration switches from
fixture to live automatically when its credentials appear.

## 2. Business numbers — ✅ SET (2026-07-02, owner-confirmed)

| Config key | Value | Decision |
|---|---|---|
| `margin.default_pct` | **50** | Corporate-gift midpoint |
| `print_cost.filament_per_gram` | **0.05** | Premium PLA + waste allowance |
| `print_cost.machine_rate_per_min` | **0.08** | S$4.80/hr |
| `print_cost.minutes_per_gram` | 2.0 | Proxy until slicer runs |
| `delivery.table` | S$5/12/30/60 | Kept — handling buffer over courier rates |
| `fee.customization_per_unit` | 0.00 | Raise when UV-decorate volume warrants |
| `margin.floor_pct` | 12 | Unchanged |

Note: the seeder is now **insert-only** — deploy-time re-seeds never clobber
values tuned in the dashboard. Tune anytime via superadmin.

## 3. Catalogue decisions — ✅ SET (2026-07-02, owner-confirmed)

1. **Discovery keywords** — expanded to 12 corporate-gift terms (phone stand,
   desk organizer, cable holder, name plate, keychain, pen holder, card
   holder, coaster, headphone stand, plant pot, luggage tag, bag hook).
   Swept nightly 04:00; edit anytime via config.
2. **Auto-publish** — **ON**. Gates enforce licence + IP blocklist + local
   file + verified estimates before anything goes public. Until
   `SLICER_BINARY` lands, each 3D item needs one staff verify click.
3. **Filament stock** — starter set entered via `FilamentSeeder` (create-only,
   never resets live stock): PLA × Black/White/Grey, 1000 g each, reorder at
   200 g. Designer colour options aligned to these three — add spool rows
   before offering more colours.
4. **IP blocklist** — 18 seeded franchise terms kept; review for your market.

## 4. Deploy steps (each release)

```bash
php artisan migrate
php artisan db:seed --class=PricingConfigSeeder   # idempotent, adds new config keys
```

Cron/scheduler already covers: scraped resync 03:00, 3D licence re-check
03:30, 3D discovery 04:00, slicer sweep 04:30 (all `onOneServer` +
`withoutOverlapping`).

## 4b. Ops decisions (spec §8) — decided 2026-07-02

| Decision | Status |
|---|---|
| Proof production owner + SLA | **Owner + one staff member** (staff name TBD — fill in below). Set an explicit SLA (suggest 1 business day) once the staff member is named |
| Admin-gate + procurement ops owner | Same pair as above |
| Real product photos for catalogue | **Still open** — current CORE images are stock photos; scraped/3D images come from sources. Shoot or source real product photography before launch marketing |

> Staff member: ______________________ (name/email) — update this line.

## 5. Legal / compliance (read once)

- Only CC0 / CC-BY / owned models publish; CC-BY credit is stored and shown.
  **Ensure creator credit also appears on packing slips/invoices** (currently
  product page only — ops decision).
- Shopee data comes via the affiliate API you registered for — do not add
  HTML scraping alongside it.
- Procurement of marketplace blanks stays a human purchase (spec §7) — never
  automate the checkout.

## 6. Backlog you may want next (no action needed now)

Shipped since first draft: slicer integration, LLM IP screen, three.js
viewer, admin gate tools (verify-estimates + file upload), Lazada feed,
slug URLs. Remaining:

- LLM description enrichment (clean copy, SEO titles, categories) at ingest.
- Parametric text-emboss pipeline (personalisation text applied to the model
  file automatically; today production applies it manually).
- Printer feedback loop (OctoPrint/Bambu → live job status via Reverb).
