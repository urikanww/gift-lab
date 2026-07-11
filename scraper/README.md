# MakerWorld scraper

Pull the MakerWorld 3D-model catalog (title, thumbnails, file list) and — with a
free login — download the printable `.3mf` files for Bambu Studio / Bambu
printers.

## How it works

| Step | Endpoint | Auth |
|------|----------|------|
| **List** | `makerworld.com/api/v1/search-service/select/design2` | none (tokenless) |
| **Detail** (instance id + target printers) | `api.bambulab.com/v1/design-service/design/{id}` | none |
| **Download .3mf** | `makerworld.com/api/v1/design-service/instance/{instanceId}/f3mf?type=original&devModelName=...` | **login required** |

The list + detail APIs are public. The **file bytes are login-gated** — the
f3mf endpoint returns `403 {"error":"Please log in to download models."}` when
anonymous. `model_files[].modelUrl` in the JSON is always empty; there is no
tokenless download URL anywhere. So downloading needs a (free) MakerWorld/Bambu
account. No token to copy — you log in once and the browser session is reused.

## Install

```bash
cd scraper
npm install          # Playwright + Chromium (~150MB, used only for login/download)
```

## 1. List models — tokenless, fast

```bash
node list.mjs 60                       # 60 hottest -> stdout JSON
node list.mjs 100 --order newest --out out.json
node list.mjs 40 --free --out out.json # only directly-downloadable (no points)
```

As a function:

```js
import { listModels } from './list.mjs';
const models = await listModels({ limit: 40, orderBy: 'hotScore' });
```

Each record:

```json
{
  "id": "3015782",
  "title": "Mystic Dragon – Breathtaking Dragon Figure",
  "url": "https://makerworld.com/en/models/3015782",
  "creator": "DElex3D",
  "thumbnails": ["https://...png", "..."],
  "files": [{ "name": "🐉 Mystic Dragon.3mf", "type": "3mf", "size": 23730949 }],
  "tags": ["dragon", "..."],
  "license": "Standard Digital File License",
  "downloadCount": 713,
  "isPrintable": true,
  "isPointRedeemable": false,
  "isExclusive": true,
  "free": true
}
```

`isExclusive` is just a MakerWorld "exclusive" **badge**, not a paywall — those
are still free. Only `isPointRedeemable` (costs points) / paid actually gate the
file. `free` reflects that.

## 2. Download the .3mf — with your `token`

The f3mf endpoint validates a JWT. Grab yours once:

1. Log in at makerworld.com.
2. DevTools (F12) -> **Application** -> **Cookies** -> `https://makerworld.com`.
3. Find the **`token`** row, copy its **Value** (a long `eyJ...` JWT).
4. Save it to `scraper/token.txt` (one line), or `set MW_TOKEN=<jwt>`.

`token.txt` is gitignored — it's your secret, don't commit or share it.

```bash
node download.mjs 3015782            # -> out/3015782/<title>_<instance>_original.3mf
node download.mjs 3015782 3018896    # multiple ids
```

For each model the downloader resolves `defaultInstanceId` + a target printer
(`devModelName`, e.g. C12=P1S) from the detail API, then pulls the raw 3mf
(`type=original`, falling back to `preview`) with your token. Files + a
`model.json` land in `out/<id>/`. Point-redeemable / paid models are flagged and
may fail until redeemed on your account.

If the token is rejected it prints `auth rejected`. Tokens expire — re-copy the
`token` cookie when that happens. (Alternatively paste a full Cookie header into
`cookie.txt` / `MW_COOKIE`.)

## 3. Product-import pipeline (superadmin)

This app has **no CSV import page** — products are created via API/seeder. So the
bundle is a `products.csv` (Product schema, `class=MODEL_3D`) plus a folder of
`.3mf`, imported by a purpose-built artisan command.

```bash
# a. list + enrich 50 records (paced):
node bulk.mjs 50 --delay 1500 --out out/records50.json

# b. download the .3mf into a folder + build the CSV.
#    NOTE: MakerWorld's GeeTest captcha caps headless download at ~2 files.
#    Use browser-download for volume (persistent login, solve captcha in-window):
node browser-download.mjs --in out/records50.json --models out/models3d
#    then (re)build the CSV from whatever .3mf are on disk:
node export.mjs --no-download --out out/products.csv --models out/models3d

# c. import -> creates Products (PENDING) + copies .3mf into the private disk:
php artisan products:import scraper/out/products.csv --models scraper/out/models3d
php artisan catalogue:backfill-3d-dimensions   # fill real dims from geometry
```

- `.3mf` files are copied to **`storage/app/private/models3d/`** (private disk);
  `products.model_file_ref = models3d/<file>`. Served only via staff endpoints.
- Thumbnails use MakerWorld's CDN URL directly in `image_url` — no upload.
- Products import as `PENDING` with unverified estimates; the publish gate holds
  them until staff verify, then they can go `PUBLISHED`.
- `export.mjs` and `browser-download.mjs` are **resume-aware** — drop any
  manually-downloaded `.3mf` into the models dir and re-run to wire them in.

## Files

- `list.mjs`    — tokenless catalog listing (primary).
- `enrich.mjs`  — per-model detail (instance id, printers, files, cost inputs).
- `download.mjs`— token-based f3mf downloader (fast, but captcha-capped ~2).
- `browser-download.mjs` — persistent-login browser downloader (GeeTest-friendly).
- `bulk.mjs`    — list + enrich (+ optional download) with human-like pacing.
- `export.mjs`  — builds products.csv + downloads/wires .3mf files.
- `auth.mjs`    — loads token.txt / cookie.txt for downloads.
- `scrape-makerworld.mjs` — legacy Playwright DOM scraper (fallback).

Import command: `app/Console/Commands/ImportScrapedProducts.php` (`products:import`).

## Notes

- Respect MakerWorld's ToS. Download only with your own account, for personal
  printing. Scrape modestly — don't hammer the API.
