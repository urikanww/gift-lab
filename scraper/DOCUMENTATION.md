# MakerWorld → Gift-Lab product pipeline

End-to-end tooling to pull MakerWorld 3D-model listings, download the printable
`.3mf` files, and import them as `MODEL_3D` products into the gift-lab superadmin
catalogue.

- [1. Overview](#1-overview)
- [2. The MakerWorld APIs (what's public, what's gated)](#2-the-makerworld-apis)
- [3. Install](#3-install)
- [4. Auth (your token)](#4-auth)
- [5. Step-by-step pipeline](#5-step-by-step-pipeline)
- [6. Downloading files — the captcha problem & the CDP fix](#6-downloading-files)
- [7. Importing into gift-lab](#7-importing-into-gift-lab)
- [8. Storage layout](#8-storage-layout)
- [9. Script reference](#9-script-reference)
- [10. Data shapes](#10-data-shapes)
- [11. Licensing](#11-licensing)
- [12. Troubleshooting](#12-troubleshooting)
- [13. Security](#13-security)

---

## 1. Overview

```
 ┌─ list.mjs ─────────┐   tokenless   ┌─ enrich.mjs ───────┐   anonymous
 │ search-service API │──────────────▶│ Bambu detail API   │
 │ 50 records         │               │ instanceId,printers│
 └────────────────────┘               │ files,cost inputs  │
                                       └─────────┬──────────┘
                                                 │
         ┌───────────────────────────────────────┴──────────────┐
         │ cdp-download.mjs  (rides your real Chrome)             │  login-gated
         │ f3mf endpoint → presigned S3 → <slug>-<id>.3mf         │  + GeeTest
         └───────────────────────────┬───────────────────────────┘
                                      │
                            ┌─────────┴─────────┐
                            │ export.mjs        │  builds products.csv
                            │ CSV + file refs   │  (Product schema)
                            └─────────┬─────────┘
                                      │
                     ┌────────────────┴─────────────────┐
                     │ php artisan products:import       │  creates Products,
                     │ + catalogue:backfill-3d-dimensions│  copies .3mf to disk
                     └───────────────────────────────────┘
```

Two things are produced:
1. **`out/products.csv`** — 50 rows in the Product schema, `class=MODEL_3D`.
2. **`out/models3d/<slug>-<id>.3mf`** — the printable files.

---

## 2. The MakerWorld APIs

Discovered by inspecting network traffic. None are officially documented.

| Purpose | Endpoint | Auth |
|---|---|---|
| **List models** | `GET makerworld.com/api/v1/search-service/select/design2?categories=&orderBy=hotScore&entrance=list&designType=0&limit=20&offset=0` | none |
| **Model detail** | `GET api.bambulab.com/v1/design-service/design/{id}` | none |
| **Download .3mf** | `GET makerworld.com/api/v1/design-service/instance/{instanceId}/f3mf?type=original&devModelName={code}` | **Bearer token** |

Key facts learned the hard way:

- **List is public**, detail is public. Only the **file bytes** are gated.
- The list returns a **design id**; the download needs an **instance id**. Map
  via the detail API's `defaultInstanceId`.
- `model_files[].modelUrl` in the list JSON is **always empty** — there is no
  static download URL; bytes come only from the f3mf endpoint.
- f3mf auth: `Authorization: Bearer <token>` **alone** works. Sending a
  `Cookie: token=` header *as well* makes the server fall back to cookie-session
  auth and reject with `403 Please log in`. Bearer-only.
- `type=original` = raw uploaded 3mf; `type=preview` = sliced variant fallback.
- `devModelName` selects the target printer profile: `C12`=P1S, `N7`=P2S,
  `O1D`=H2D, `C11`=P1P, etc. First one from the detail API is a safe default.
- The presigned S3 URL that f3mf returns is short-lived (~5 min TTL) and
  unauthenticated — fetch it immediately.

---

## 3. Install

```bash
cd scraper
npm install          # Playwright + Chromium (~150MB, first run only)
```

Requires Node 18+ (uses global `fetch`).

---

## 4. Auth

Downloads need your MakerWorld JWT.

1. Log in at makerworld.com.
2. DevTools (F12) → **Application → Cookies → `https://makerworld.com`**.
3. Copy the **`token`** cookie value (a long string).
4. Save it to `scraper/token.txt` (one line) — **gitignored, never commit**.

The token expires periodically; re-copy when downloads start returning
`auth rejected`.

> Note: for the **CDP** download path (section 6) you don't strictly need
> `token.txt` — the download rides your logged-in Chrome session. `token.txt` is
> used by the plain `download.mjs`/`export.mjs` fast path.

---

## 5. Step-by-step pipeline

```bash
cd scraper

# 1. List + enrich 50 records (human-paced). -> out/records50.json
node bulk.mjs 50 --delay 1500 --out out/records50.json

# 2. Download the .3mf files (see section 6 for WHY it's CDP, not headless).
#    Launch your real Chrome in debug mode first, log into makerworld, then:
node cdp-download.mjs --in out/records50.json --models out/models3d

# 3. Build products.csv from whatever .3mf are on disk (resume-aware).
node export.mjs --no-download --out out/products.csv --models out/models3d

# 4. Import into gift-lab (from the project root).
cd ..
php artisan products:import scraper/out/products.csv --models scraper/out/models3d
php artisan catalogue:backfill-3d-dimensions
```

---

## 6. Downloading files

### The problem

MakerWorld defends downloads with **GeeTest captcha**. After ~2 automated
downloads the f3mf endpoint returns:

```json
{"error":"We need to confirm that you are not a robot.","captchaId":"..."}
```

The captcha **sticks to the session** — waiting doesn't clear it. And MakerWorld
**detects Playwright's own browser** (`navigator.webdriver`), so its login page
just redirect-loops. Headless/token bulk download is therefore capped at ~2.

### The fix: attach to your REAL Chrome over CDP

`cdp-download.mjs` does **not** drive an automated browser. You run your normal
Chrome with remote debugging; it looks like a human (no `webdriver` flag), so
login works and captchas are solvable. The script attaches over CDP and pulls
files through that trusted session (shared cookies).

**Setup (one time):**

1. Fully quit Chrome.
2. Launch it in debug mode with a dedicated profile (PowerShell):

   ```powershell
   & "C:\Program Files\Google\Chrome\Application\chrome.exe" --remote-debugging-port=9222 --user-data-dir="C:\mw-chrome"
   ```

3. In that Chrome, go to makerworld.com and **log in** (email or Google both
   work — it's your real Chrome). Leave it open.
4. Run the downloader:

   ```bash
   node cdp-download.mjs --in out/records50.json --models out/models3d
   ```

When GeeTest appears the script pauses; click Download on the model in Chrome,
solve the captcha, press **Enter**, and it continues. Files already on disk are
skipped (resume). The `C:\mw-chrome` profile keeps you logged in for next time.

| | Playwright browser | Real Chrome (CDP) |
|---|---|---|
| `navigator.webdriver` | `true` → detected | `false` → normal |
| MakerWorld login | redirect-loops | works |
| GeeTest captcha | blocks | you solve it |

### Notes

- Some files are 100MB+ (e.g. Yoshi multipart = 116MB); the file-body request
  has its timeout disabled.
- `browser-download.mjs` is the Playwright-persistent-context variant. It's kept
  as a fallback but **CDP is the reliable path** because Playwright's browser is
  detected.

---

## 7. Importing into gift-lab

**There is no CSV import page in the app** — products are normally created via
the admin JSON API or a seeder. So this pipeline ships a purpose-built command:

```bash
php artisan products:import <csv> --models <dir> [--dry-run]
```

Defined in `app/Console/Commands/ImportScrapedProducts.php`. It:

- reads the CSV, one `MODEL_3D` Product per row;
- is **idempotent** — keyed on `source_product_id`, so re-running updates rather
  than duplicating (safe to run repeatedly as you gather more files);
- copies each referenced `.3mf` from `--models` into the private `local` disk at
  `models3d/`, setting `model_file_ref`;
- imports everything as `publish_state=PENDING` with `estimates_verified=false`
  and `model_preview_verified=false`, so the **publish gate holds them** until
  staff verify — nothing goes live automatically;
- rows whose file wasn't downloaded import **without** `model_file_ref` (you can
  re-run later once the file exists).

After import, `catalogue:backfill-3d-dimensions` fills real dimensions from the
stored geometry (the CSV ships placeholder `100×100×100`).

**Publish checklist** (per gift-lab's gate) before a MODEL_3D product can go
`PUBLISHED`: valid `license` + `creator_credit` (unless CC0/OWNED), verified
filament estimates, verified model preview, `is_printable=true`.

---

## 8. Storage layout

| Asset | Disk | Folder / column |
|---|---|---|
| `.3mf` model file | `local` (**private**, `storage/app/private`) | `storage/app/private/models3d/` — `products.model_file_ref = models3d/<file>` |
| Thumbnail | — | none placed; `products.image_url` = MakerWorld CDN URL directly |

- Model files are **private on purpose** — the CC/proprietary licenses forbid
  redistribution, so they're served only through staff/stream endpoints, never a
  public URL.
- Thumbnails need no upload; `image_url` points at MakerWorld's public CDN.
- The import command copies files for you — you don't hand-place anything. If you
  ever do it manually, drop `.3mf` into `storage/app/private/models3d/`.

---

## 9. Script reference

| Script | Purpose | Auth | Key flags |
|---|---|---|---|
| `list.mjs` | Tokenless catalogue listing | none | `<limit> --order --free --out` |
| `enrich.mjs` | Per-model detail (instance, printers, files, weight/time/filament) | none | (module) |
| `bulk.mjs` | list + enrich (+optional download) with pacing | none/token | `<limit> --delay --free --download --out` |
| `download.mjs` | Token-based f3mf download (fast, captcha-capped ~2) | token.txt | `<id...>` |
| `cdp-download.mjs` | **Real-Chrome CDP download (captcha-friendly)** | Chrome login | `--in --models --cdp` |
| `browser-download.mjs` | Playwright-persistent download (fallback) | site login | `--in --models` |
| `export.mjs` | Build products.csv + download/wire files | token.txt | `--in --out --models --no-download --delay` |
| `auth.mjs` | Loads token.txt / cookie.txt | — | (module) |
| `scrape-makerworld.mjs` | Legacy Playwright DOM scraper (fallback) | none | `<limit> --enrich --headed --out` |

App-side: `app/Console/Commands/ImportScrapedProducts.php` → `products:import`.

---

## 10. Data shapes

### Enriched record (`records50.json`)

```json
{
  "id": "3015782",
  "title": "Mystic Dragon – Breathtaking Dragon Figure",
  "url": "https://makerworld.com/en/models/3015782",
  "creator": "DElex3D",
  "thumbnails": ["https://makerworld.bblmw.com/.../cover.png"],
  "files": [{ "name": "🐉 Mystic Dragon.3mf", "type": "3mf", "size": 23730949 }],
  "license": "Standard Digital File License",
  "isPointRedeemable": false,
  "isExclusive": true,
  "free": true,
  "defaultInstanceId": 3387636,
  "devModelNames": ["C12", "N7", "O1D"],
  "weightGrams": 233,
  "estPrintMinutes": 840,
  "filamentMaterial": "PLA",
  "filamentColor": "#FFFFFF",
  "bambuCategory": "Characters"
}
```

### CSV columns (`products.csv`)

`name, class, category, description, base_cost, currency, min_order_qty,
dim_l, dim_w, dim_h, weight, print_method, stock_mode, allow_backorder,
license, creator_credit, is_printable, publish_state, image_url, source_url,
source_product_id, model_file_ref, filament_material, filament_color,
est_grams, est_print_minutes`

- `class` = `MODEL_3D`, `print_method` = `FDM`, `stock_mode` = `MAKE_TO_ORDER`.
- `base_cost` = `max(2, grams×0.03 + minutes×0.01)` SGD — a **placeholder**
  cost model; staff should review.
- `dim_*` = `100` placeholders; corrected by `catalogue:backfill-3d-dimensions`.
- `license` mapped to the app enum (section 11).

---

## 11. Licensing

**Important.** MakerWorld models carry per-creator licenses. Of a typical
hot-list pull, almost none are cleanly licensed for commercial resale:

| MakerWorld license | Maps to app enum | Commercial resale |
|---|---|---|
| Standard Digital File License | `OWNED` | proprietary — normally **no** |
| MakerWorld Exclusive License | `OWNED` | **no** |
| CC BY-NC | `CC_BY_NC` | NonCommercial — **no** |
| CC BY-ND | `CC_BY_ND` | NoDerivatives |
| CC BY / CC0 / MIT / … | `CC_BY` / `CC0` / `MIT` | yes |

`export.mjs` maps anything non-CC to `OWNED` (asserting you hold rights).
Selling models you don't have rights to violates the creator license **and**
MakerWorld's ToS. The gift-lab `License` enum + publish gate are built to enforce
attribution/commercial rules — use them. **Confirm you have the rights before
publishing.**

---

## 12. Troubleshooting

| Symptom | Cause / fix |
|---|---|
| `403 Please log in to download models` | Token missing/expired, or you sent Bearer **and** Cookie. Re-copy `token.txt`; use Bearer only. |
| `We need to confirm that you are not a robot` + `captchaId` | GeeTest. Use `cdp-download.mjs` and solve it in your real Chrome. |
| MakerWorld login page redirect-loops | Playwright browser detected. Use CDP (real Chrome), not `browser-download.mjs`. |
| `Could not attach to Chrome at localhost:9222` | Chrome not launched with `--remote-debugging-port=9222`, or a normal Chrome is already holding the profile. Fully quit Chrome first. |
| `apiRequestContext.get: Timeout 30000ms` | Large file (100MB+). Fixed — body download timeout is disabled; re-run (resume skips done files). |
| CSV `model_file_ref` blank for a row | That file wasn't downloaded yet. Download it, then re-run `export.mjs --no-download` and re-import. |
| Only 2–3 files download then stop | The captcha wall — see GeeTest row above. |

---

## 13. Security

- `token.txt`, `cookie.txt`, `session.json`, `.env`, `.mw-profile/`, and `out/`
  are **gitignored**. The token is a live credential — anyone with it can act as
  your MakerWorld account until it expires. Don't commit or paste it anywhere
  shared; log out to invalidate a leaked one.
- Downloaded `.3mf` files are stored on the **private** disk and never served
  publicly, matching the redistribution restrictions in the licenses.
```
