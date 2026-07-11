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
(`devModelName`) from the detail API, then pulls the raw 3mf (`type=original`,
falling back to `preview`) with your token. Files + a `model.json` land in
`out/<id>/`. Point-redeemable / paid models are flagged and may fail until
redeemed on your account.

**Printer target = Bambu H2S (`O1S`).** The downloader now PREFERS the `O1S`
slice when a model lists it (`pick-dev-model.mjs`), so the `.3mf` is print-ready
on our H2S. Models without an `O1S` variant fall back to their first profile and
print a `! no O1S/H2S slice … re-slice needed` warning — those need re-slicing
against the H2S profile before the floor prints them.

If the token is rejected it prints `auth rejected`. Tokens expire — re-copy the
`token` cookie when that happens. (Alternatively paste a full Cookie header into
`cookie.txt` / `MW_COOKIE`.)

## 3. Build the bundle locally (records + .3mf + CSV)

The output of a scrape is a `products.csv` (Product schema, `class=MODEL_3D`)
plus the `.3mf` files it references.

```bash
# a. list + enrich N records (paced):
node bulk.mjs 50 --delay 1500 --out out/records50.json

# b. download the .3mf into a folder + build the CSV.
#    NOTE: MakerWorld's GeeTest captcha caps headless download at ~2 files.
#    Use browser-download for volume (persistent login, solve captcha in-window):
node browser-download.mjs --in out/records50.json --models out/models3d
#    then (re)build the CSV from whatever .3mf are on disk:
node export.mjs --no-download --out out/products.csv --models out/models3d
```

What you get:
- `out/products.csv` — one MODEL_3D row per model, `publish_state=PENDING`.
- `out/models3d/` — the `.3mf` files.
- `model_file_ref` in the CSV uses the **canonical ref** `models3d/{source}-{id}.{ext}`
  (`model-ref.mjs`) — the SAME path the backend + the S3 upload use, so the file
  the app looks up is exactly the one that gets stored.
- Thumbnails stay as MakerWorld CDN URLs in `image_url`; the backend mirrors them
  to our own storage on import.

## 4. Push to production

The prod server serves model files from a **private S3 (DO Spaces)** disk and
reads them by ref — it never needs the files on its own local disk. So you upload
the `.3mf` files to Spaces from your laptop, then import the CSV on prod. Two ways:

### Option A — scraper uploads direct to S3 (recommended, no juggling)

Put the prod Spaces creds in **`scraper/.env`** (gitignored — never commit):

```
AWS_ACCESS_KEY_ID=...       AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=sgp1     AWS_BUCKET=giftlab
AWS_ENDPOINT=https://sgp1.digitaloceanspaces.com
DO_STORAGE_FOLDER=GIFT_LAB
```

Then `npm install` (pulls the S3 SDK) and run the scrape — `download.mjs` /
`export.mjs` upload each `.3mf` straight to Spaces at the canonical ref as they
go. Nothing to copy by hand. Without creds it silently skips the upload and just
writes local files.

Then on prod: **upload `products.csv` via the admin import page** (Catalogue admin
→ Import CSV, superadmin). The refs resolve against Spaces.

### Option B — files already local → push them up

If the `.3mf` are already on your machine and you don't want to re-scrape:

```bash
# 1. get the files onto the app's model disk AT the CSV refs (and create products):
php artisan products:import scraper/out/products.csv --models scraper/out/models3d

# 2. set the model disk + creds in your LOCAL .env:
#      MODEL3D_DISK=spaces_models   MODEL3D_PRODUCTION_DISK=spaces_models
#      FILESYSTEM_DISK=s3  + AWS_* + DO_STORAGE_FOLDER=GIFT_LAB
# 3. preview, then upload local model files to Spaces:
php artisan catalogue:migrate-models-to-s3 --dry-run   # must list a non-zero count
php artisan catalogue:migrate-models-to-s3
```

Shortcut: if you set `MODEL3D_DISK=spaces_models` **before** step 1, then
`products:import --models` writes the `.3mf` **straight to Spaces** and step 3 is
unnecessary.

### On the production server (one-time)

- Env: `MODEL3D_DISK=spaces_models`, `MODEL3D_PRODUCTION_DISK=spaces_models`,
  `MODEL3D_THUMBNAIL_DISK=s3`, `FILESYSTEM_DISK=s3`, `AWS_*`, `DO_STORAGE_FOLDER=GIFT_LAB`.
- Run **`php artisan queue:work`** (systemd/supervisor). Import enqueues an
  enrichment job per model — `.3mf → STL` (for the viewer), thumbnail mirror, IP
  screen, dimensions. Without a worker, imported models never enrich.
- `QUEUE_CONNECTION=database` (the `jobs` table migration ships with the app).

### What happens after import
- Products land **`PENDING`** — a CSV can never self-publish. Staff review + approve.
- Each MakerWorld `.3mf` gets a derived **STL** as `model_file_ref` (three.js renders
  STL, not 3MF); the original `.3mf` becomes `production_file_ref` (the floor's H2S file).
- Branded models get a non-blocking **IP-risk badge** — surfaced, not blocked.
- `export.mjs` / `browser-download.mjs` are **resume-aware** — drop any
  manually-downloaded `.3mf` into the models dir and re-run to wire them in.

## Files

- `list.mjs`    — tokenless catalog listing (primary).
- `enrich.mjs`  — per-model detail (instance id, printers, files, cost inputs).
- `download.mjs`— token-based f3mf downloader (fast, but captcha-capped ~2).
- `browser-download.mjs` — persistent-login browser downloader (GeeTest-friendly).
- `bulk.mjs`    — list + enrich (+ optional download) with human-like pacing.
- `export.mjs`  — builds products.csv + downloads/wires .3mf files (+ S3 upload).
- `auth.mjs`    — loads token.txt / cookie.txt for downloads.
- `pick-dev-model.mjs` — chooses the printer profile, preferring `O1S` (H2S).
- `model-ref.mjs` — the ONE canonical storage ref (`models3d/{source}-{id}.{ext}`),
  shared by the S3 upload key and the CSV `model_file_ref`. Pinned by
  `path.test.mjs` + the backend `AssetStoreTest` so the two can't drift.
- `s3-upload.mjs` — direct-to-Spaces upload (creds-gated; no-op without them).
- `scrape-makerworld.mjs` — legacy Playwright DOM scraper (fallback).

- Tests: `node --test` (path/O1S contract).
- Backend import: the admin **CSV import page** (superadmin) or the CLI
  `products:import` (`app/Console/Commands/ImportScrapedProducts.php`). Both route
  through the same enrichment (Model3D row, `.3mf→STL`, thumbnail, IP screen).

## Notes

- Respect MakerWorld's ToS. Download only with your own account, for personal
  printing. Scrape modestly — don't hammer the API.
