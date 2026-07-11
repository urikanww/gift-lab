# Plan: MakerWorld → S3 catalogue, Bambu H2S production, unified 3D asset pipeline

**Status:** ready to execute. Self-contained — no prior chat context needed.
**Target printer:** Bambu Lab **H2S** = MakerWorld device code **`O1S`**.
**Goal:** Serve all product 3D models + thumbnails from **our own S3 (DO Spaces)**, not the
original source (which rots). Unify how Thingiverse and the MakerWorld-CSV path save
assets. Make MakerWorld `.3mf` products production-ready on H2S. Never break existing
UI / Product-model consumers or the UV (SCRAPED_UV) track.

---

## 0. Background (current state)

- **Product model** (`app/Models/Product.php`): `model_file_ref` = relative path on the
  private `local` disk (e.g. `models3d/thingiverse-123.stl`); `image_url` = a full URL.
- **Thingiverse/Cults3D ingest** (`app/Console/Commands/PullModel3dCatalogue.php` →
  `app/Services/Model3d/Model3dCatalogueService.php::ingest`): downloads STL to `local`
  disk via `Model3dFileStore`, creates a `Model3d` row + linked `Product`, mirrors the
  thumbnail to the `public` disk (`mirrorImage`), runs `IpScreenService`, measures
  estimates with `SlicerService` (PrusaSlicer CLI — slices to G-code, reads grams/mins,
  **deletes** the G-code), and gates publish.
- **CSV import — TWO paths exist today (must be reconciled, see Phase 6):**
  - HTTP: `app/Http/Controllers/AdminProductController.php::import` (newer; the one
    `ProductCsvImport.tsx` + `AdminProductImportTest` target): superadmin uploads a scraper
    CSV; validates every row; upserts `Product` rows (no `Model3d` row, no file download,
    no IP screen); `image_url` = raw MakerWorld CDN URL; `model_file_ref` = a `models3d/…`
    string; placeholder dimensions.
  - CLI: `app/Console/Commands/ImportScrapedProducts.php` (`products:import`): copies the
    `.3mf` straight into the raw `local` disk (line 75) and upserts `Product` rows
    directly — **no `Model3d` row, no IP screen, no `AssetStore`, no S3**. This command
    BYPASSES everything this plan builds; Phase 6 must route it through `ingest` or delete
    it, else it silently re-opens the old door.
- **Model-file serving is hard-wired to `Storage::disk('local')` in 28 sites across 7
  source files** (grep `disk('local')` — the plan previously undercounted this):
  - `app/Http/Controllers/AdminCatalogueController.php` — **15 sites** (`adminModel`,
    `partModel`, `exportParts`, upload-replace, delete/part-delete).
  - `app/Http/Controllers/CatalogueController.php` — **3 sites** (the PUBLIC model
    endpoint: `exists`/`path`/`response`). Previously omitted; flipping the disk without
    fixing this breaks public serving.
  - `app/Services/Model3d/Model3dFileStore.php` — **3 sites** (`store`, `storePart`,
    `exists`).
  - `app/Services/Model3d/SlicerService.php` — **3 sites** (`exists` + input `->path` +
    output `->path`).
  - `app/Services/Model3d/Model3dCatalogueService.php` — **2 sites** (`exists` +
    `dimensions->fromFile(...->path())`).
  - `app/Http/Controllers/AdminProductController.php` — **1 site** (import validation
    `exists` check).
  - `app/Console/Commands/ImportScrapedProducts.php` — **1 site** (`put` on copy).
- **Viewers** (`frontend/src/components/ModelViewer.tsx`, `StlModelViewer.tsx`,
  `StlStudioViewer.tsx`, `Model3dDecalPreview.tsx`, `Model3dZoneEditor.tsx`) use three.js
  **`STLLoader` only** → they cannot render `.3mf`. They ALREADY fetch via
  `api.get(url, {responseType:'arraybuffer'})` (e.g. `StlModelViewer.tsx:87`,
  `ProductionQueuePage.tsx`) but with **no `onDownloadProgress`** and parse on the main
  thread → no determinate bar, big meshes freeze the tab. Phase 7 extends this existing
  fetch (5 call-sites), it is NOT greenfield.
- **S3 disks exist** (`config/filesystems.php`): `s3` (public, root `GIFT_LAB`) and
  `spaces_private` (private, signed URLs). `assets:migrate-to-spaces` already pushes
  product images to `s3`.
- **MakerWorld `.3mf` files are Bambu Studio print projects** (multi-object mesh under
  `3D/Objects/*.model` + build transforms + Bambu metadata). For a supported printer
  they are already sliced/print-ready.

## Locked decisions

1. **Direct-to-S3:** the scraper uploads each `.3mf` straight to S3 as it downloads
   (no local staging, no separate push command). The CSV carries the S3 ref.
2. **Two-file model (additive, non-breaking):**
   - `model_file_ref` = **STL** — the app file (viewer, dimensions, estimate-slice).
   - new `production_file_ref` (**nullable, falls back to `model_file_ref`**) = the file
     the floor prints.
   - Thingiverse: `production_file_ref` null → floor uses STL (unchanged). MakerWorld:
     `production_file_ref` = the original `.3mf`.
3. **Bambu H2S (`O1S`):** MakerWorld `.3mf` downloaded with `devModelName=O1S` prints
   as-is on H2S (49/50 of the scraped set support O1S). Thingiverse STL is auto-sliced to
   an H2S-ready `.gcode.3mf` via **OrcaSlicer** CLI. The 1 model without O1S → re-slice
   its mesh with the H2S profile.
4. **IP policy:** flagged **but otherwise valid** item → `READY_TO_APPROVE` +
   **`ip_flagged`** tag (non-blocking), NOT `CANNOT_PUBLISH`. New distinct **IP-risk
   badge** with a hover tooltip; the existing license-tier "High legal risk" badge also
   gets a tooltip. IP screen must run on the **CSV path** too (today it doesn't).
5. **Real loading bar:** determinate progress via axios `onDownloadProgress`
   (needs `Content-Length`), plus **Web Worker parse** so a 100 MB mesh doesn't freeze
   the tab. Viewer stays STL-based (we derive STL), so no `3MFLoader` needed.
6. **Shared `AssetStore` service** for thumbnail + model save, used by BOTH Thingiverse
   and the CSV/MakerWorld path. Thumbnails → `s3` (public, `products/{source}/…`).
   Models → a **new `spaces_models` disk** (private, `models3d/{source}/…`).
   **Do NOT reuse `spaces_private`** — it is rooted at `GIFT_LAB/anon-uploads`
   (`config/filesystems.php:97`), so models would land mixed into the anon designer-artwork
   folder. Add a sibling disk rooted at **`GIFT_LAB`** (bare — model refs already
   carry the `models3d/` prefix, so rooting at `GIFT_LAB/models3d` would DOUBLE it
   and the object never resolves) with `visibility => 'private'`, and point
   `MODEL3D_DISK`/`MODEL3D_PRODUCTION_DISK` at it. Model files then land under
   `GIFT_LAB/models3d/`. Refs stay flat (`models3d/{source}-{id}.{ext}`) so the CSV
   importer's `model_file_ref` regex (no sub-slashes) still accepts them, and the
   scraper's S3 upload key == the CSV ref == what the backend resolves.
7. **Disk abstraction:** one `config('model3d.disk')` routes every `disk('local')`
   model-file site, so switching to S3 is one config change and serving is unchanged.
8. **UV-aware:** the thumbnail mirror + IP screen apply to `SCRAPED_UV` too; the disk
   abstraction doesn't touch UV (no model file).

## Non-breaking guarantees (must hold at every phase)

- `Product` shape is **add-only** (`production_file_ref`, `ip_flagged`, `ip_flag_reason`).
- `image_url` stays a URL; `model_file_ref` stays a relative path of the same shape.
- Same admin routes + auth for model streaming (`/admin/products/{id}/model`).
- Thingiverse products unchanged in behavior (production falls back to STL).
- UV products unaffected by the disk/model changes.

---

## Phase 0 — Schema + config foundations

**Files**
- New migration `database/migrations/xxxx_add_production_and_ip_flags_to_products.php`:
  - `production_file_ref` string nullable (relative path, like `model_file_ref`).
  - `ip_flagged` boolean default false.
  - `ip_flag_reason` string nullable.
- `app/Models/Product.php`: add the three to `$fillable`; cast `ip_flagged` boolean.
- New `config/model3d.php`: `['disk' => env('MODEL3D_DISK', 'local'), 'production_disk'
  => env('MODEL3D_PRODUCTION_DISK', 'local'), 'thumbnail_disk' => env('MODEL3D_THUMBNAIL_DISK','public')]`.
- `config/filesystems.php`: add the new **`spaces_models`** disk (s3 driver, same bucket,
  `visibility => 'private'`, `root => rtrim(env('DO_STORAGE_FOLDER','GIFT_LAB'),'/').'/models3d'`).
  This is the private model-file disk — NOT `spaces_private` (that is anon-artwork only).
- `.env.example`: add `MODEL3D_DISK`, `MODEL3D_PRODUCTION_DISK`, `MODEL3D_THUMBNAIL_DISK`,
  `ORCA_SLICER_BINARY`, `ORCA_H2S_PROFILE` (path to the H2S OrcaSlicer profile bundle),
  and note prod values (`spaces_models` / `spaces_models` / `s3`).

**Verify:** `php artisan migrate`; `php artisan test` still green; `Product::create` with
the new fields works in tinker.

## Phase 1 — Storage abstraction + `AssetStore` (+ backfill)

**Files**
- `app/Services/Model3d/AssetStore.php` (new):
  - `storeThumbnail(string $source, string $sourceId, string $remoteUrl): ?string`
    — download (short timeout), put to `config('model3d.thumbnail_disk')` (=`s3` in prod)
    at `products/{source}/{sourceId}.jpg`, return `Storage::disk(...)->url($path)`;
    silent-skip on failure (return null → caller keeps the source URL). Reuse the logic
    from `PullModel3dCatalogue::mirrorImage`.
  - `storeModelFile(string $source, string $sourceId, string $bytes, string $ext): string`
    — put to `config('model3d.disk')` at `models3d/{source}/{sourceId}.{ext}`, return ref.
  - `storeProductionFile(...)` — same for `config('model3d.production_disk')`.
- Route ALL hard-coded `Storage::disk('local')` model-file sites through
  `Storage::disk(config('model3d.disk'))`. **28 sites / 7 files** (grep `disk('local')`
  and re-count before starting — earlier drafts undercounted):
  - `app/Http/Controllers/AdminCatalogueController.php` — **15 sites** (lines
    226/227/243/244 upload-replace, 313/317 `adminModel`, 334/338 `partModel`,
    407/420 `exportParts`, 479/544/545/577/578 delete + part-delete).
  - `app/Http/Controllers/CatalogueController.php` — **3 sites** (202/213/221, the PUBLIC
    model endpoint). MUST be included or public serving breaks on the S3 flip.
  - `app/Services/Model3d/Model3dFileStore.php` — **3 sites** (74 `exists`, 184/244 `put`).
  - `app/Services/Model3d/SlicerService.php` — **3 sites** (41 `exists`, 45 input `->path`,
    46 output `->path`; Orca needs a local temp copy when disk is S3 — see Phase 3).
  - `app/Services/Model3d/Model3dCatalogueService.php` — **2 sites** (178 `exists`,
    182 `dimensions->fromFile(...->path())` — needs temp copy on S3, same as slicer).
  - `app/Http/Controllers/AdminProductController.php` — **1 site** (549 import `exists`).
  - `app/Console/Commands/ImportScrapedProducts.php` — **1 site** (75 `put`) — but this
    command is being reconciled in Phase 6; fix or remove there.
- Replace `PullModel3dCatalogue::mirrorImage` body with a call to
  `AssetStore::storeThumbnail` (keep the method as a thin wrapper or delete + inline).
- Extend `app/Console/Commands/MigrateAssetsToSpaces.php` (or add
  `catalogue:migrate-models-to-s3`) to copy existing `local` `models3d/*` →
  `spaces_private`, keeping refs identical.

**Caveat:** `->response()` streams fine from S3, but every `->path()` caller assumes a
local filesystem and needs a **local temp copy** on S3 (`Storage::disk($d)->get($ref)` →
write temp → use → cleanup). The `->path()` sites are: `SlicerService` (45/46),
`AdminCatalogueController::exportParts` (420), and
`Model3dCatalogueService` dimensions `fromFile` (182). `adminModel`/`partModel`/public
`CatalogueController` use `->response()` → fine. Handle the temp-copy wrapper in Phase 3
(shared helper, reused by dimensions + slicer + zip-export).

**Verify:** with `MODEL3D_DISK=local` everything behaves as before (all tests green).
Flip to `spaces_private` on a staging env → `adminModel` still streams a model; thumbnail
mirror writes to `s3` and `image_url` points at the Spaces URL. Add a feature test that
asserts the disk is read from config, not hard-coded.

## Phase 2 — `.3mf → STL` converter (prototype FIRST — riskiest)

**Files**
- `app/Services/Model3d/ThreeMfToStl.php` (new): `convert(string $threeMfBytes): string`
  (returns binary STL).
  - Unzip in-memory (`ZipArchive` on a temp file).
  - **Stream-parse** `3D/3dmodel.model` with `XMLReader` (NOT SimpleXML — files are
    100 MB+): read `<build>` items → object id + transform matrix.
  - For each referenced object (`3D/3dmodel.model` inline or `3D/Objects/object_N.model`):
    read `<mesh>` `<vertices>`/`<triangles>`, apply the transform, accumulate
    world-space triangles.
  - Compute per-face normals, write **binary STL**.
  - Guard: cap triangle count / memory; on parse failure throw a typed exception so the
    caller can fall back (see below).
- Fallback (optional): if pure-PHP fails, shell out to `lib3mf`/`assimp`/Orca CLI to
  export STL. Config-gated.
- Test `tests/Feature/ThreeMfToStlTest.php`: convert the real
  `scraper/out/models3d/*imperial-shuttle*.3mf` → assert STL header `PK`? no — assert
  valid binary STL (80-byte header + triangle count matches), non-zero triangles,
  bounding box sane.

**Verify:** run the converter on the Imperial Shuttle (100+ objects) and the fidget toy;
load the output STL in `StlModelViewer` (browser) — it renders. This proves the whole
downstream (viewer/dimensions) works for MakerWorld before wiring the rest.

## Phase 3 — Slicer → OrcaSlicer, persist production file, H2S

**Files**
- `app/Services/Model3d/SlicerService.php`:
  - Switch the binary to **OrcaSlicer** CLI (`config('services.slicer.binary')` →
    OrcaSlicer path) targeting the **H2S profile** (`ORCA_H2S_PROFILE`).
  - **Persist** the sliced output as the production file instead of `@unlink`: write to
    `production_file_ref` via `AssetStore::storeProductionFile` (a `.gcode.3mf`), AND
    parse grams/minutes from the same pass (one slice → production file + estimates).
  - When the model disk is S3: pull the STL to a local temp, slice, upload the result,
    read estimates, clean temp.
  - Feed material/color from `Product::$filament_material` / `filament_color`.
- MakerWorld special-case (in the ingest path, Phase 6): if `production_file_ref` is
  already an H2S-targeted `.3mf` (from the scraper), **skip re-slicing** — just measure
  estimates (or trust the `.3mf`'s embedded slice_info). Only re-slice models that lack
  an O1S variant.
- `app/Console/Commands/SlicePendingModels.php`: unchanged trigger, now yields a
  persisted production file too.

**Verify:** configure OrcaSlicer on a machine, slice a Thingiverse STL → a
`.gcode.3mf` lands in `production_file_ref` and estimates are set. Confirm the file opens
in Bambu Studio / is valid for H2S.

## Phase 4 — IP policy (both paths, non-blocking + tag)

**Files**
- `app/Services/Model3d/Model3dCatalogueService.php` (+ `PullModel3dCatalogue.php` where
  it currently sets `CANNOT_PUBLISH` on `ip_flag`): change IP-flag handling → set
  `ip_flagged = true`, `ip_flag_reason = …`, and DO NOT force `CANNOT_PUBLISH`. If the
  item is otherwise valid (has a producible file + required data + license), let it reach
  `READY_TO_APPROVE`.
- Publish gate: `ip_flagged` no longer a blocking reason; it's a surfaced tag.
- `AdminProductController::import`: call `IpScreenService::screen(name, description)` per
  row; set `ip_flagged`/`ip_flag_reason`. Keep forcing `PENDING` from CSV (publish stays
  a human act), but store the IP flag so the badge shows.
- Serializer (`AdminProductController::serialize` / wherever products are serialized to
  the admin API): include `ip_flagged` + `ip_flag_reason`.
- Optionally seed `pricing_configs` `catalogue/ip_blocklist` with the obvious franchises
  in the scraped set (star wars, pokemon, disney, yoshi, gremlins, daffy duck, kratos…).

**Verify:** feature test — an IP-flagged valid row imports as `ip_flagged=true` and is
NOT `CANNOT_PUBLISH`; the API response carries the flag.

## Phase 5 — Scraper: direct-to-S3 + H2S (`O1S`)

**Files (in `scraper/`)**
- `enrich.mjs`: when choosing the download `devModelName`, **prefer `O1S`** (H2S) if the
  model's `devModelNames` contains it; else fall back to the first (and flag "no H2S
  slice" for later re-slice).
- `cdp-download.mjs` / `download.mjs`: after fetching the `.3mf` bytes, **upload directly
  to S3** (`models3d/makerworld/{id}.3mf` on the private Spaces disk) instead of / in
  addition to writing local. Needs S3 creds in the scraper env (`.env`, gitignored).
- `export.mjs`: write `model_file_ref` / a `production_file_ref` column pointing at the
  S3 path (or keep `models3d/makerworld/{id}.3mf` relative, resolved by the backend disk).
- Re-download the 3 already-fetched files with `devModelName=O1S` (they're currently P1S).

**Verify:** one model downloaded with `O1S`, uploaded to Spaces, visible in the bucket;
CSV row references it.

## Phase 6 — Converge ingest (CSV + Thingiverse through one path)

**Files**
- **Reconcile the two CSV paths FIRST** (see Background). There are two importers writing
  `Product` rows directly: `AdminProductController::import` (HTTP) and
  `ImportScrapedProducts` (`products:import` CLI). Decide per path:
  - HTTP `import`: route through `Model3dCatalogueService::ingest` (below).
  - CLI `ImportScrapedProducts`: EITHER delete it (if the HTTP path fully replaces it) OR
    refactor it to call the same `ingest` — it must NOT keep copying `.3mf` to raw `local`
    (line 75) and upserting `Product` without `Model3d`/IP/`AssetStore`, or it re-opens
    the bypass this whole plan closes. Whichever you keep, only ONE code path may create
    MODEL_3D products after this phase.
- Build a `Model3dData` DTO from a CSV row and route the surviving CSV importer through the
  SAME `Model3dCatalogueService::ingest` the Thingiverse pull uses (so it gets: `Model3d`
  row + `model3d_id` link, IP screen, thumbnail mirror via `AssetStore`, STL derivation via
  `ThreeMfToStl`, production file, dimensions, gate) — instead of writing `Product` rows
  directly.
  - **Idempotency:** dedup on `(source, source_id)`, NOT `source_product_id` alone
    (`ImportScrapedProducts` currently keys on `source_product_id` only → collides across
    sources). Skip re-upload if the S3 object already exists.
  - MakerWorld branch inside ingest: source `.3mf` from S3 → `ThreeMfToStl` → STL to
    `model_file_ref`; original `.3mf` → `production_file_ref`; thumbnail → `AssetStore`.
  - Keep the CSV importer's validation layer (Phase-4 rules) in front.
- Ensure `SCRAPED_UV` rows skip the model-file/STL/slice branch but still get thumbnail
  mirror + IP screen + validation.

**Verify:** import the real `scraper/out/products.csv` on staging → products have
`Model3d` rows, S3 thumbnails, derived STL, `production_file_ref`, `ip_flagged` where
applicable, all `PENDING`/`READY_TO_APPROVE` (never auto-published). Existing
`AdminProductImportTest` still green (adjust expectations for the new linkage).

## Phase 7 — Frontend: real loading bar + IP badge + production download

**Files**
- New `frontend/src/lib/loadModelWithProgress.ts`: EXTEND the existing
  `api.get(url, {responseType:'arraybuffer'})` calls (already in the viewers, e.g.
  `StlModelViewer.tsx:87`) by adding `onDownloadProgress` → expose `loaded/total`. Move
  `STLLoader.parse` into a **Web Worker** (`frontend/src/workers/stlParse.worker.ts`) →
  post geometry back.
- Migrate **all 5 call-sites** to this helper: `StlModelViewer.tsx`, `StlStudioViewer.tsx`,
  `Model3dDecalPreview.tsx`, `Model3dZoneEditor.tsx`, `ModelViewer.tsx` — render a
  **determinate progress bar** (download %) then an indeterminate "processing" state during
  worker parse. (`ProductionQueuePage.tsx` also fetches arraybuffer — check if it needs the
  bar too.)
- `frontend/src/pages/adminProductBadges.tsx`: add an **`IpRiskBadge`** (red, distinct
  from `LicenseTierBadge` at line 45) driven by `product.ip_flagged`, with a **hover
  tooltip** explaining the risk; add a tooltip to the existing `LicenseTierBadge` too.
  Reuse the existing `Tooltip` component (`frontend/src/ui/Tooltip.tsx`, exported from
  `../ui`) — no new primitive needed.
- `frontend/src/types.ts`: add `ip_flagged`, `ip_flag_reason`, `production_file_ref` to
  `AdminProduct`.
- Production queue UI / `ProductionQueueController`: expose a **download of
  `production_file_ref`** (fallback `model_file_ref`) so the floor gets the H2S `.3mf`.
- **Content-Length (hard step, not optional):** the determinate bar needs it. The `local`
  disk (`serve => true`) sets it automatically via `->response()`; an **S3 `->response()`
  (Flysystem) does NOT reliably set it** → bar stays indeterminate. On the S3 path,
  explicitly `Storage::disk($d)->size($ref)` (or a HEAD) and set the `Content-Length`
  header on `adminModel`/`partModel`/public `catalogue model` responses. Verify on staging
  S3, not just local.
- CSP: add the Spaces domain to `img-src` (and `connect-src` if the model streams from
  Spaces directly) so thumbnails/models load.

**Verify (browser):** open a MakerWorld product's model → determinate progress bar to
100%, then it renders (no tab freeze on a big file). IP badge shows with tooltip on hover.
Production queue offers the `.3mf` download.

## Phase 8 — Full verification

- `php artisan test` (all green; update `AdminProductImportTest` for linkage/IP).
- New tests: `ThreeMfToStlTest`, `AssetStore` (disk from config, source subfolders,
  silent-skip), IP-flag-non-blocking, disk-abstraction (serving reads config disk).
- Browser: model viewer progress bar, badges + tooltips, import modal still works.
- Backfill dry-run then real: existing local models → S3; existing image_urls → Spaces.
- Confirm UV products still list, publish, and print (decal path) untouched.

---

## Config / ops to set before running in prod

- DO Spaces creds: `AWS_*`, `DO_STORAGE_FOLDER=GIFT_LAB`.
- Add the new `spaces_models` disk to `config/filesystems.php` (private, rooted at
  `GIFT_LAB` — NOT `spaces_private`, which is anon-artwork only).
- `MODEL3D_DISK=spaces_models`, `MODEL3D_PRODUCTION_DISK=spaces_models`,
  `MODEL3D_THUMBNAIL_DISK=s3`.
- `ORCA_SLICER_BINARY=<path>`, `ORCA_H2S_PROFILE=<H2S profile bundle>`.
- Scraper `.env`: S3 creds for direct upload. **Confirm `scraper/.env` is gitignored
  before any commit** — the whole `scraper/` dir is currently untracked; do not let creds
  land in git.
- Queue worker running (thumbnail mirror + slicing + 3mf→STL are queued jobs — do NOT run
  them inside the import HTTP request).
- CSP updated for the Spaces domain.

## Risks / watch-items

- **`.3mf→STL` for multi-object Bambu projects** is the riskiest piece — prototype Phase 2
  first on the Imperial Shuttle before committing the rest.
- **Dual CSV importer bypass** — `AdminProductController::import` AND
  `ImportScrapedProducts` both create products directly; if the CLI one survives Phase 6
  unrefactored it silently skips S3/IP/`Model3d`. Reconcile to a single path.
- **Disk-site sweep is bigger than it looks** — 28 `disk('local')` sites / 7 files, incl.
  the PUBLIC `CatalogueController`. Re-grep and fix ALL before flipping the disk, or public
  serving + dimensions + slicing break on S3.
- **Wrong private disk** — models must go to a new `spaces_models` disk, NOT `spaces_private`
  (rooted at `anon-uploads`).
- **S3 `->path()`** doesn't exist — slicer + zip-export + dimensions need a local temp copy.
- **S3 `Content-Length`** — Flysystem `->response()` may omit it; set it explicitly or the
  progress bar can't be determinate.
- **Memory**: stream-parse big meshes; queue + cap.
- **Legal**: IP-flagged items are now publishable (with a tag) — deliberate risk
  acceptance. MakerWorld models are heavily branded; keep the IP badge + human approval.
- **Printer match**: only download/print the `O1S` (H2S) slice; re-slice the 1 model
  without it.
- **Idempotency**: dedup on `(source, source_id)`; skip re-upload if the S3 object exists.
