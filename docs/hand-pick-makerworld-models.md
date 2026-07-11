# Hand-pick MakerWorld models into Gift Lab

When you browse MakerWorld yourself and find specific models worth adding to the
catalogue, this is how you get *exactly those* into Gift Lab — no bulk scrape,
no offset paging. You collect the URLs, and the existing scraper pipeline does
the rest.

Everything runs from the `scraper/` folder.

```bash
cd scraper
npm install     # once — Playwright + Chromium (used for the captcha-safe download)
```

## The idea

The whole download → CSV → import chain runs off a `records.json` (a list of
model records). Normally that file comes from the search listing (`list.mjs` /
`bulk.mjs`). For hand-picking, **`pick.mjs`** builds the same file from the URLs
you paste — then every step after is unchanged.

```
pick.mjs  (your URLs)  ─▶  records.json  ─▶  cdp-download  ─▶  export  ─▶  products:import
```

## Step 1 — collect the URLs

Browse https://makerworld.com, and for each model you want, copy its URL from the
address bar. Any of these forms work:

- `https://makerworld.com/en/models/3012887-12-in-1-fidget-toy`
- `https://makerworld.com/en/models/3012887`
- just the id: `3012887`

Paste them into a text file, **one per line**. Blank lines and `#` comments are
ignored.

`scraper/out/picks.txt`:
```
# fidget toys for the kids range
https://makerworld.com/en/models/3012887-12-in-1-fidget-toy
https://makerworld.com/en/models/3015782
# a bare id is fine too
3018896
```

## Step 2 — build records.json

```bash
node pick.mjs --file out/picks.txt --out out/records.json
```

Or skip the file and pass URLs/ids straight as arguments:

```bash
node pick.mjs https://makerworld.com/en/models/3012887 3015782 --out out/records.json
```

`pick.mjs` parses the id out of each URL, fetches each model's details
(title, creator, license, weight, printer instance, thumbnails), dedupes, and
writes `out/records.json`. Unresolvable ids are skipped and logged.

## Step 3 — download the .3mf files

MakerWorld hides the printable bytes behind login **and** a GeeTest captcha that
blocks headless browsers. The reliable path is `cdp-download.mjs`, which rides
your own logged-in Chrome:

1. Fully quit Chrome.
2. Relaunch it in debug mode with a dedicated profile (login persists here):
   ```
   "C:\Program Files\Google\Chrome\Application\chrome.exe" --remote-debugging-port=9222 --user-data-dir="C:\mw-chrome"
   ```
3. In that Chrome, go to makerworld.com and **log in** (email or Google). Leave it open.
4. Download:
   ```bash
   node cdp-download.mjs --in out/records.json --models out/models3d
   ```

Files save to `out/models3d/<slug>-<id>.3mf`. It prefers the **H2S (`O1S`)** slice
so the `.3mf` is print-ready. If a captcha pops, the script pauses and tells you
to solve it in the Chrome window, then press ENTER. Already-downloaded files are
skipped, so you can re-run it safely.

## Step 4 — build the CSV (and upload to S3)

```bash
node export.mjs --no-download --in out/records.json --out out/products.csv --models out/models3d
```

`--no-download` means "don't fetch — just use the `.3mf` already on disk from
step 3." It writes `out/products.csv` (one `MODEL_3D` row per model,
`publish_state=PENDING`) and, if `scraper/.env` has the Spaces creds, uploads
each `.3mf` to production S3 at the canonical ref. Without creds it just writes
local files.

> Spaces creds in `scraper/.env` (gitignored — never commit):
> ```
> AWS_ACCESS_KEY_ID=...       AWS_SECRET_ACCESS_KEY=...
> AWS_DEFAULT_REGION=sgp1     AWS_BUCKET=giftlab
> AWS_ENDPOINT=https://sgp1.digitaloceanspaces.com
> DO_STORAGE_FOLDER=GIFT_LAB
> ```

## Step 5 — import into Gift Lab

- **Production:** upload `out/products.csv` via the admin **Catalogue → Import CSV**
  page (superadmin). The refs resolve against Spaces.
- **Local / CLI:**
  ```bash
  php artisan products:import scraper/out/products.csv --models scraper/out/models3d
  ```

A queue worker must be running (`php artisan queue:work`) — import enqueues an
enrichment job per model (`.3mf → STL` for the viewer, thumbnail mirror, IP
screen, dimensions).

## What you get

- Products land **`PENDING`** — a CSV can never self-publish. Staff review + approve.
- Each `.3mf` gets a derived **STL** as `model_file_ref` (the viewer renders STL);
  the original `.3mf` becomes `production_file_ref` (the floor's H2S print file).
- `creator_credit`, license, weight, and thumbnail come from the model details.
- Dimensions are placeholders (the API doesn't expose them) — staff verify on
  approval, along with the `PENDING` estimates.

## Cheat sheet

```bash
cd scraper
# 1. paste URLs -> out/picks.txt
node pick.mjs --file out/picks.txt --out out/records.json
# 2. (Chrome running with --remote-debugging-port=9222, logged in)
node cdp-download.mjs --in out/records.json --models out/models3d
# 3. build CSV + S3 upload
node export.mjs --no-download --in out/records.json --out out/products.csv --models out/models3d
# 4. import (or use the admin CSV page on prod)
php artisan products:import scraper/out/products.csv --models scraper/out/models3d
```

## Related

- `scraper/README.md` — full scraper reference (listing, offset paging, bulk).
- `docs/orcaslicer-h2s-setup.md` — auto-slicing STLs into H2S print files.
