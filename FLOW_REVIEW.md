# Flow Review — Auto Flow-In + 3D Customization Goal

**Date:** 2026-07-02
**Goal (vendor's words):** Products auto-flow in from marketplaces; vendor buys and customizes; "worldmaker" for 3D from model sources; print on own 3D printer; end users design corporate + personalized gifts.

---

## 1. Current state vs goal

| Goal piece | Today | Gap |
|---|---|---|
| Auto flow-in 3D models | `catalogue:pull-3d` manual artisan command; live Thingiverse + Cults3D clients | Not scheduled — human must run per keyword |
| Marketplace UV blanks (Shopee/Lazada) | `FixtureScraperClient` stub only | No live ingest at all |
| Print with own printer | `model_file_ref` stored; `est_grams` field exists | STL not mirrored locally; grams are a manual guess; no bed-fit / manifold check |
| End-user 3D design ("worldmaker") | `DesignerCanvas.tsx` = Fabric.js 2D only (logo + text) | Zero 3D: no viewer, no text emboss, no color pick |

Architecture verdict: skeleton is right. Two-gate spine (proof approval + readiness), per-class procurement strategies, real licence gate. Do **not** redesign the flow — fill the gaps below.

## 2. Critical risks

1. **STL files not mirrored at ingest.** `PullModel3dCatalogue::mirrorImage()` (app/Console/Commands/PullModel3dCatalogue.php:246) mirrors the *image* only. If the source deletes the model after a customer quotes, the job cannot be printed — contract breach. CC0/CC-BY permits copying; download the STL to Spaces at ingest. **Highest priority.**
2. **No 3D licence re-check.** `routes/console.php` schedules daily resync for SCRAPED_UV only. MODEL_3D items are never re-checked; a creator can change licence or delete the model upstream. Extend resync to the 3D class (licence drift → `CANNOT_PUBLISH / needs_re-review`, same pattern as price drift).
3. **3D pricing on guesswork.** `Model3dCatalogueService::ingest()` sets `base_cost = 0` and `is_printable = true` unconditionally (app/Services/Model3d/Model3dCatalogueService.php:55-58); `est_grams` comes from source data, not measurement.

## 3. Recommended amendments (priority order)

1. **Schedule discovery (small, big win).** Admin-editable keyword list in `PricingConfig` ("phone stand", "desk organizer", "name plate", …). Nightly cron loops keywords → `catalogue:pull-3d`. `catalogue.auto_publish` toggle already exists. Pipeline becomes: cron discovers → licence gate → admin approve queue.
2. **Slicer-in-loop pricing.** Headless PrusaSlicer/CuraEngine at ingest: STL → grams + print minutes → PricingService. Also validates manifold + printer-bed fit → sets `is_printable` honestly.
3. **3D customization, phased:**
   - **A (ship first):** three.js STL viewer on product detail — rotate/zoom + filament color pick.
   - **B (revenue):** server-side parametric personalization — OpenSCAD/CadQuery embosses name/logo (keychains, name plates, lithophanes). Output STL becomes the proof artwork → rides the existing immutable proof gate.
   - **C (defer):** full free-form "worldmaker" configurator. Big lift, low incremental revenue vs B.
   - Licence note: CC-BY permits commercial derivatives; attribution must survive to product page **and** packing slip. NC/ND already blocked — correct.
4. **More sources.** `CompositeModel3dApiClient` ready for: Printables (Prusa, CC licences, good API), MyMiniFactory (API + commercial tier), Thangs. MakerWorld licence terms messy — skip. Also consider vendor-bought paid Cults3D commercial licences for hero models (bulk licence ≠ the per-order purchase the spec forbids).
5. **UV blanks: feed, don't scrape.** Spec §7 bans bot checkout; live Shopee/Lazada scraping is fragile + ban risk. Instead: (a) supplier CSV import behind the existing `ScraperClient` interface, (b) admin "add by URL" form with manual fields — completeness gate handles the rest. Human procurement stays per spec.
6. **Later: printer feedback loop.** OctoPrint/Klipper/Bambu API → Job status auto-updates via Reverb. Nice, not urgent.

---

## 4. Additional findings (second pass — services, pricing, clients, designer)

> Note: `AUDIT_REPORT.md` already covers code-quality/security line-items. This section is flow-level only; no duplication.

### F1 — CRITICAL: `model_file_ref` is a web page URL, not a model file
- Thingiverse client stores `public_url` (app/Services/Model3d/HttpThingiverseClient.php:74); Cults3D stores `creation.url` (app/Services/Model3d/HttpCults3dClient.php:83).
- Spec §6.6 says the Job "carries print-ready file" — impossible today: the floor gets a link to a webpage.
- Fix: Thingiverse `/things/{id}/files` endpoint returns direct download URLs — fetch the STL at ingest and store to Spaces (CC0/CC-BY permits the copy). Cults3D free-file downloads are not cleanly exposed via GraphQL — likely a manual download step in the admin approval gate; make that explicit in the gate UI (blocker tag `missing_model_file`).
- Supersedes/expands risk #1 in §2: it is not only "not mirrored" — the file was never fetched at all.

### F2 — HIGH: Thingiverse licence mapping leaks SA and ND as CC_BY
- `mapLicense()` (HttpThingiverseClient.php:88): NC is checked before the generic `attribution` match, so NC is safe — but "Attribution - Share Alike" and "Attribution - No Derivatives" both contain "attribution" and map to `CC_BY`.
- **ND directly blocks the personalization goal** (embossing a name = derivative). SA imposes share-alike obligations on the design.
- The command's own docblock (PullModel3dCatalogue.php:22) claims "NC/ND/SA/unknown-licence hits are skipped" — the code does not do that.
- Fix: match `no derivative` / `share alike` (and short forms) before the generic attribution branch. The Cults3D mapper is strict (exact `cc_by` only) — mirror that rigor.
- **Must land before auto-scheduled discovery** — otherwise the cron auto-ingests licence liabilities nightly.

### F3 — HIGH: MODEL_3D pricing has no real economics
- `base_cost = 0` → margin percentage multiplies zero; unit price collapses to the flat `print_cost.per_unit[FDM]` config value (app/Services/PricingService.php:23-32).
- A 200 g vase prices the same as a 5 g keychain. Filament cost is absent from landed cost, so the margin-floor guard is meaningless for 3D lines.
- Fix: `unit = est_grams × filament_cost_per_gram + print_minutes × machine_rate_per_min + margin` — all as `pricing_configs` rows. Depends on slicer integration (§3.2) for honest grams/minutes.

### F4 — MEDIUM: source-client defaults masquerade as data
- Both clients hardcode `filamentMaterial: 'PLA'`, `filamentColor: 'Black'`, `estGrams: 50.0`. With `catalogue.auto_publish` ON, an item can publish with untouched defaults → wrong filament decrement in `Model3dProcurement` and wrong delivery weight.
- Fix: mark estimate fields as unverified at ingest; force `READY_TO_APPROVE` (never auto-publish) while unverified. Auto-publish should only apply to items whose estimates have been confirmed (by staff or by the slicer).

### F5 — MEDIUM: delivery table falls through to free shipping
- `PricingService::deliveryFor()` (PricingService.php:88-99): weight above every configured tier max → returns `0.0`. Heaviest shipment ships free unless the seeder always keeps a null-max catch-all tier. Config-fragile.
- Fix: fall back to the last tier's price (or fail loud) instead of 0.

### F6 — NOTE: customization fee is flat per line
- `quoteTotals()` adds `customization_flat` once per line regardless of qty. Fine as a setup fee for UV. For 3D parametric personalization (per-unit emboss = per-unit print-time cost), pricing needs a per-unit component when Phase B lands.

### F7 — NOTE: 2D designer is shown for MODEL_3D products
- `ProductDesignerPage` uses the same Fabric.js canvas for every class: for a 3D product the customer overlays a flat logo on a *photo* of the model. That artwork cannot be produced as shown, yet it becomes the proof the customer approves — the immutable-proof guarantee binds to an unprintable artefact.
- Fix (until Phase B parametric pipeline): hide the logo-overlay canvas for `MODEL_3D`; offer filament colour + text fields instead, and let the proof carry those choices.

### Solid pieces worth keeping (verified, no change)
- Soft-delete resurrection guard on scraped re-ingest; per-item failure isolation in resync; price-drift → auto-unpublish; `lockForUpdate` on filament decrement; reorder-draft dedupe; explicit HTTP timeouts + bounded retry on both live clients; blocked-licence rows hard-deleted so the admin gate never fills with unusable items.

## 5. Revised priority order (integrating both passes)

1. **F2 licence-mapping fix** — small, blocks legal risk, prerequisite for any auto-pull.
2. **F1 real STL fetch + mirror to Spaces** — prerequisite for "print on my printer" at all.
3. **Scheduled discovery cron + keyword config** (§3.1) — only after 1 & 2.
4. **Slicer-in-loop** (§3.2) → feeds **F3 pricing model** and clears **F4 defaults** (slicer output = verified estimate).
5. **F5 delivery fall-through guard** — one-line hardening, do alongside 4.
6. **F7 designer split by class** + three.js viewer (§3.3 Phase A).
7. **Parametric personalization pipeline** (§3.3 Phase B) + per-unit customization fee (F6).
8. **UV blanks supplier-feed import** (§3.5); more 3D sources (§3.4); printer feedback loop (§3.6).

---

## 6. Zero-touch catalogue automation (answer to "avoid manual populate/review/adjust")

Principle: **machine gates + exception-only review.** Auto-publish when every gate passes; humans see only failures (tagged) or a random spot-check sample. "Don't scrape" applies only to Shopee/Lazada bot activity — everything below is API/feed-based and ToS-clean.

### 6.1 3D track gate stack (all machine-checkable)

| # | Gate | How | Kills which manual step |
|---|---|---|---|
| 1 | Licence | Existing gate (fix F2 first) | Legal review |
| 2 | File | Download STL → parse → manifold + bed-fit via headless PrusaSlicer | "Can we print this?" check |
| 3 | Pricing | Slicer grams + minutes → formula (F3) | Manual price adjust |
| 4 | Quality | Thingiverse `like_count` / `download_count` / `make_count` thresholds | Junk filtering |
| 5 | IP/trademark | Keyword blocklist + Claude API screen (name/description/image): branded IP? gift-suitable? — CC licence ≠ trademark clearance | The real reason human review exists |
| 6 | Enrichment | Same LLM call: clean description, SEO title, category, filament suggestion | Populate/tidy work |

- All pass → `PUBLISHED` untouched. Any fail → `CANNOT_PUBLISH` with new reason tags (`ip_flag`, `file_invalid`, `low_quality`) — existing reason-tag mechanism extends cleanly.
- `catalogue.auto_publish` toggle already exists; gates make ON safe.
- Optional dial: publish everything gate-passing, route random 5% to an audit queue (spot-check replaces gatekeeping; ~95% review-effort cut).

### 6.2 UV blanks without scraping

1. **Shopee/Lazada affiliate/open-platform APIs** — official product feeds (price, stock, images) built for catalogue display. The legitimate "auto flow-in from marketplace" path.
2. **Supplier feed ingest** — watcher on inbox/folder → LLM parses arbitrary CSV/Excel layouts → `ScraperClient` interface → existing completeness gate. Zero manual entry.
3. Printability (dimensions vs UV bed max) already machine-checked in the completeness gate once dimensions arrive.

### 6.3 Stays human (irreducible)

- Cults3D free-file download click (API exposes no download endpoint).
- The actual marketplace purchase at procurement time (spec §7 ban on bot checkout — keep).
- Supplier onboarding; IP dispute edge cases.

---

## 7. Fixes shipped (2026-07-02 session) — all findings closed

Verified: 84 backend tests (204 assertions) + 35 frontend tests green; designer + pricing verified live in browser.

| Finding | Fix | Where |
|---|---|---|
| F2 licence leak (SA/ND → CC_BY) | ND/SA matched before the generic attribution branch; +4 tests | `HttpThingiverseClient::mapLicense` |
| F1 no real model file | Thingiverse `/things/{id}/files` → STL/3MF/OBJ downloaded to the private `local` disk at ingest (`models3d/…`); `model_file_ref` = our copy. Gate blocks `missing_model_file` otherwise (Cults3D items held for manual file attach, no longer deleted) | `Model3dFileStore` (new), `Model3dCatalogueService`, `PullModel3dCatalogue` |
| F8 (new): admin publish always failed for 3D | Class-aware publish/unpublish — MODEL_3D uses its own gate, not the scraped CompletenessGate | `AdminCatalogueController`, `Model3dCatalogueService::publish/unpublish` |
| F4 placeholder estimates auto-publishing | `products.estimates_verified` column (migration); unverified holds at READY_TO_APPROVE with `estimates_unverified`; staff endpoint `POST /admin/products/{id}/verify-estimates` sets material/colour/grams + flag; verified facts survive re-ingest | migration `…000018`, service, controller, route |
| F3 3D price = flat fee | `landedCost()` for MODEL_3D = grams × `print_cost.filament_per_gram` + grams × `print_cost.minutes_per_gram` × `print_cost.machine_rate_per_min`; margin applies to full production cost; flat FDM per-unit fee no longer stacks; margin floor now real for 3D (AmendQuoteRequest uses `landedCost()`) | `PricingService`, `AmendQuoteRequest`, seeder |
| F5 free delivery fall-through | Heaviest tier charged when weight exceeds every tier | `PricingService::deliveryFor` |
| F6 per-unit customization fee | `fee.customization_per_unit` config (default 0) added ×qty on customized lines | `PricingService::quoteTotals`, seeder |
| Licence re-check (risk #2) | `catalogue:resync-3d` daily 03:30 — re-fetches licence per item, drifted/NC items pulled from public; dead source flags `needs_re-review` (production unaffected — we hold the file) | new command + schedule |
| Auto flow-in (§3.1) | `catalogue:discover-3d` nightly 04:00 runs a keyword-less popular-browse pull per source through `pull-3d`, capped by `catalogue.browse_cap` | new command + schedule + seeder |
| Marketplace API (§6.2) | `HttpShopeeAffiliateClient` (ScraperClient impl; SHA256-signed GraphQL, ToS-clean affiliate feed) + `catalogue:pull-uv {keyword}`; auto-bound when `SHOPEE_AFFILIATE_APP_ID/SECRET` present, fixture otherwise. Feed items land in the completeness gate for dims/printability | new client + command + `config/services.php` + `AppServiceProvider` |
| F7 unprintable 3D proofs | Designer branches by class: MODEL_3D gets `Model3dPersonalizer` (filament colour + optional text → structured customization), Fabric canvas stays for UV/CORE | `ProductDesignerPage`, `Model3dPersonalizer` (new) |
| Public URLs exposed ids | `products.slug` (unique, generated once from name, stable across renames; migration backfills). Public catalogue resolves slug first, numeric id kept as legacy fallback so old links/carts never break. Frontend links via `productPath()`/`designPath()` — `/products/bamboo-coaster`, `/design/bamboo-coaster`. Admin/quote routes stay numeric (auth-gated, not public) | migration `…000019`, `Product` model, `CatalogueController::show`, `ProductResource`, `lib/catalogue.ts` + Home/Catalogue/Detail pages |

### Owner actions required (one-time; ongoing per-product work = zero)

1. **Shopee affiliate account** — register at Shopee Affiliate Open Platform (SG), get AppId + Secret → set `SHOPEE_AFFILIATE_APP_ID`, `SHOPEE_AFFILIATE_SECRET` in `.env`. Live feed switches on automatically.
2. **Thingiverse token** — `THINGIVERSE_TOKEN` (app token from thingiverse.com/developers). Enables live pull + nightly discovery + STL downloads.
3. **Cults3D credentials** — `CULTS3D_USERNAME` + `CULTS3D_TOKEN` (cults3d.com API key). Note: their items block on `missing_model_file` until a file is attached manually.
4. **Review pricing numbers** (superadmin dashboard / seeder defaults): `filament_per_gram` 0.06, `minutes_per_gram` 2.0, `machine_rate_per_min` 0.08, `customization_per_unit` 0.00 — placeholders; set to your real costs.
5. **Tune discovery breadth** — `catalogue.browse_cap` config (max models ingested per source per nightly popular-browse sweep).
6. **Decide auto-publish** — flip `catalogue.auto_publish` ON when comfortable; gates (licence, file, verified estimates) now make it safe. Estimates still need per-item verification (staff click or future slicer).
7. **Run on deploy**: `php artisan migrate && php artisan db:seed --class=PricingConfigSeeder`.

### Backlog batch shipped (same day, second pass) — 103 backend + 35 frontend tests green

| Feature | What shipped | Where |
|---|---|---|
| Slicer integration | `SlicerService` (PrusaSlicer CLI, gated on `SLICER_BINARY`): slices the stored file, reads real grams + print minutes from the G-code, auto-sets `estimates_verified` — kills the manual verify click. Slicing failure flags `is_printable=false` (manifold/bed-fit signal). Runs at pull + nightly `catalogue:slice-pending` 04:30. `est_print_minutes` column; pricing uses measured minutes over the grams proxy | `SlicerService`, `SlicePendingModels`, migration `…000020`, `PricingService` |
| LLM IP/trademark screen | `IpScreenService`: layer 1 = admin-editable keyword blocklist (`catalogue.ip_blocklist`, 18 seeded terms, free, always on); layer 2 = Claude screen when `ANTHROPIC_API_KEY` set (fails open with warning). Runs before ingest in `pull-3d` — flagged items never enter the catalogue | `IpScreenService`, `PullModel3dCatalogue`, seeder |
| three.js viewer | `GET /api/catalogue/{key}/model` streams our file copy (published MODEL_3D only); lazy-loaded `ModelViewer` (STLLoader + OrbitControls, auto-rotate) on the product detail page; `has_model` flag on the resource | `CatalogueController::model`, `ModelViewer.tsx`, `ProductDetailPage` |
| Admin gate tools | `POST /admin/products/{id}/model-file` (staff, .stl/.3mf/.obj ≤100 MB → local disk, re-gates); `Model3dRowTools` on CatalogueAdminPage: inline verify-estimates form + attach-file button, shown only on rows that need them | `AdminCatalogueController`, `Model3dCatalogueService::regate`, `CatalogueAdminPage`, admin store |
| Lazada feed | `HttpLazadaAffiliateClient` (open-platform HMAC-SHA256 signing; search/detail paths configurable per affiliate program); `CompositeScraperClient` routes `lazada:{id}` → Lazada, everything else → Shopee, so daily resync spans both feeds; `catalogue:pull-uv --source=lazada` | `HttpLazadaAffiliateClient`, `CompositeScraperClient`, `AppServiceProvider`, `PullShopeeCatalogue` |

### Designer overhaul (hybrid UV-on-3D flow)

- **Product photo backdrop in the designer** — DOM `<img>` layered behind a
  transparent fabric canvas. Deliberately NOT drawn into the canvas: no CORS
  requirement on the image host (the Spaces bucket has no CORS policy) and no
  canvas taint, so `toDataURL` capture always works. The export is a
  transparent PNG of only the design layers — the actual print artwork, never
  a product mockup.
- **Drag & drop** logo onto the canvas (PNG/JPEG), plus the existing picker.
- **Hybrid 3D flow**: MODEL_3D items now use filament-colour picker + the
  same canvas. Business model: FDM-print the item, then UV-print the design
  on its flat face — placement mockup is a real production step, so the
  proof is producible. Customization payload = `{filament_color, logo_size,
  name_text, artwork_ref}`.
- Ops notes for hybrid: needs a flat/near-flat decoration zone; light
  filament colours carry UV ink contrast best (hint shown in UI); fixture the
  printed part for the UV pass. Consider a per-model `uv_decoratable` flag
  later to hide the canvas for models with no flat face.

### Still open

- Description enrichment via LLM (clean copy, SEO titles, categories) — screen exists, enrichment doesn't.
- Optional Spaces CORS policy (only needed if a future feature must draw product photos INTO a canvas; current designer doesn't).
- `uv_decoratable` flag per 3D model (hide canvas for models with no flat face).
- Printer feedback loop (OctoPrint/Bambu → job status via Reverb).
- Confirm Lazada affiliate API paths against the actual program console (configurable via env).
