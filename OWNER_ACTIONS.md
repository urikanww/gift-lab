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

## 2. Business numbers to set (superadmin dashboard / pricing config)

Seeded defaults are placeholders — set your real costs:

| Config key | Seeded | Meaning |
|---|---|---|
| `print_cost.filament_per_gram` | 0.06 | Filament cost per gram (SGD) |
| `print_cost.minutes_per_gram` | 2.0 | Print-time proxy until slicer integration |
| `print_cost.machine_rate_per_min` | 0.08 | Machine time rate (SGD/min) |
| `fee.customization_per_unit` | 0.00 | Per-unit personalisation fee (e.g. embossed text) |
| `margin.default_pct` / `margin.floor_pct` | 35 / 12 | Confirm they match your economics |
| `delivery.table` | 4 tiers | Confirm courier rates |

## 3. Catalogue decisions

1. **Discovery keywords** — `catalogue.discovery_keywords` config. Seeded:
   phone stand, desk organizer, cable holder, name plate, keychain.
   Edit to match your gift catalogue strategy; swept nightly at 04:00.
2. **Auto-publish toggle** — `catalogue.auto_publish` (superadmin endpoint).
   Safe to enable: licence gate + IP screen + local-file gate +
   verified-estimates gate all enforce before anything goes public. With
   `SLICER_BINARY` configured, estimates verify themselves — zero-touch
   publish. Without it, each 3D item needs one staff click (the inline
   "Verify estimates" form on the Catalogue gate page).
   Also review the **IP blocklist** (`catalogue.ip_blocklist`, 18 seeded
   franchise terms) for brands relevant to your market.
3. **Filament stock** — enter real spools (material/colour/grams) so 3D
   procurement decrements against reality.

## 4. Deploy steps (each release)

```bash
php artisan migrate
php artisan db:seed --class=PricingConfigSeeder   # idempotent, adds new config keys
```

Cron/scheduler already covers: scraped resync 03:00, 3D licence re-check
03:30, 3D discovery 04:00, slicer sweep 04:30 (all `onOneServer` +
`withoutOverlapping`).

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
