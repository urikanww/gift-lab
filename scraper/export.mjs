#!/usr/bin/env node
/**
 * Build a superadmin-import bundle from scraped MakerWorld records:
 *
 *   1. downloads each model's .3mf into  out/models3d/<slug>-<id>.3mf   (paced)
 *   2. writes  out/products.csv  with columns matching the Product schema
 *      (class=MODEL_3D), model_file_ref pointing at the file above
 *
 * The CSV is consumed by `php artisan products:import` (see
 * app/Console/Commands/ImportScrapedProducts.php), which creates the Product
 * rows and copies the .3mf files into storage/app/private/models3d/.
 *
 * Usage:
 *   node export.mjs                       # uses out/records50.json
 *   node export.mjs --in out/records50.json --delay 1500
 *   node export.mjs --no-download         # CSV only, skip file pulls
 *   node export.mjs --from-models --models out/models3d   # CSV from the .3mf ON DISK
 *                                         # (one row per file, any batch count)
 */

import { readFile, writeFile, mkdir, access, readdir } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import { enrichModel } from './enrich.mjs';
import { fetchModelFile } from './download.mjs';
import { loadAuth } from './auth.mjs';
import { modelRef } from './model-ref.mjs';
import { uploadModelFile } from './s3-upload.mjs';

// --- cost model (staff should review; flagged unverified in the CSV) ---------
const FILAMENT_PER_GRAM = 0.03; // SGD/g, PLA incl. waste
const MACHINE_PER_MINUTE = 0.01; // SGD/min, printer + power
const MIN_BASE_COST = 2.0;

/** MakerWorld license string -> app License enum. */
function mapLicense(lic = '') {
  const s = lic.trim().toUpperCase();
  const cc = {
    'CC0': 'CC0',
    'BY': 'CC_BY',
    'BY-SA': 'CC_BY_SA',
    'BY-NC': 'CC_BY_NC',
    'BY-ND': 'CC_BY_ND',
    'BY-NC-SA': 'CC_BY_NC_SA',
    'BY-NC-ND': 'CC_BY_NC_ND',
  };
  if (cc[s]) return cc[s];
  // Standard Digital File License / MakerWorld Exclusive License / unknown:
  // caller asserted they hold rights -> mark OWNED so the publish gate allows it.
  return 'OWNED';
}

const slug = (s) =>
  (s || '')
    .normalize('NFKD')
    .replace(/[^\w\s-]/g, '')
    .trim()
    .replace(/\s+/g, '-')
    .toLowerCase()
    .slice(0, 50) || 'model';

/** CSV columns, in order. Matches Product fillable + import command. */
const COLUMNS = [
  'name', 'class', 'category', 'description',
  'base_cost', 'currency', 'min_order_qty',
  'dim_l', 'dim_w', 'dim_h', 'weight',
  'print_method', 'stock_mode', 'allow_backorder',
  'license', 'creator_credit', 'is_printable', 'publish_state',
  'image_url', 'source_url', 'source_product_id',
  'model_file_ref', 'production_file_ref', 'filament_material', 'filament_color',
  'est_grams', 'est_print_minutes',
];

function toRow(rec, fileRef) {
  const grams = rec.weightGrams || 0;
  const minutes = rec.estPrintMinutes || 0;
  const baseCost =
    Math.max(MIN_BASE_COST, grams * FILAMENT_PER_GRAM + minutes * MACHINE_PER_MINUTE).toFixed(2);
  const desc =
    `${rec.title} — designed by ${rec.creator || 'unknown'} (MakerWorld). ` +
    `Tags: ${(rec.tags || []).slice(0, 12).join(', ')}.`;

  return {
    name: rec.title,
    class: 'MODEL_3D',
    category: (rec.bambuCategory || '').toLowerCase(),
    description: desc.slice(0, 5000),
    base_cost: baseCost,
    currency: 'SGD',
    min_order_qty: 1,
    // Dimensions aren't exposed by the API — placeholders; staff verifies.
    dim_l: 100, dim_w: 100, dim_h: 100,
    weight: grams || 100,
    print_method: 'FDM',
    stock_mode: 'MAKE_TO_ORDER',
    allow_backorder: 'false',
    license: mapLicense(rec.license),
    creator_credit: rec.creator || '',
    is_printable: 'true',
    // Import unpublished — estimates + preview need staff verification.
    publish_state: 'PENDING',
    image_url: (rec.thumbnails || [])[0] || rec.cover || '',
    source_url: rec.url || `https://makerworld.com/en/models/${rec.id}`,
    source_product_id: rec.id,
    model_file_ref: fileRef, // '' if download skipped/failed
    // The original H2S .3mf is what the floor prints. The backend derives an STL
    // for the viewer and keeps this .3mf as the production file; carrying it here
    // makes the intent explicit even though the backend can also derive it.
    production_file_ref: fileRef,
    filament_material: rec.filamentMaterial || '',
    filament_color: rec.filamentColor || '',
    est_grams: grams,
    est_print_minutes: minutes,
  };
}

function csvEscape(v) {
  const s = v === null || v === undefined ? '' : String(v);
  return /[",\n\r]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
}
function toCsv(rows) {
  const head = COLUMNS.join(',');
  const body = rows.map((r) => COLUMNS.map((c) => csvEscape(r[c])).join(',')).join('\n');
  return `${head}\n${body}\n`;
}

async function exists(p) {
  try {
    await access(p);
    return true;
  } catch {
    return false;
  }
}

let _tick = 0;
function jitter() {
  _tick += 1;
  const x = Math.sin(_tick * 12.9898 + (Date.now() % 1000)) * 43758.5453;
  return x - Math.floor(x);
}
const sleep = (base) => new Promise((r) => setTimeout(r, base + Math.floor(jitter() * base)));

/**
 * Build the record list by scanning a folder of downloaded .3mf files instead of
 * reading a records.json. The id is taken from each `<slug>-<id>.3mf` filename
 * and enriched via the detail API — so the CSV always matches what's ON DISK,
 * however many batches produced it. Deduped by id.
 */
async function recordsFromModelsDir(modelsDir, log) {
  let files;
  try {
    files = await readdir(modelsDir);
  } catch {
    throw new Error(`--from-models: cannot read ${modelsDir}`);
  }
  const ids = [];
  const seen = new Set();
  for (const f of files) {
    const m = /-(\d+)\.3mf$/i.exec(f); // trailing numeric id before .3mf
    if (!m) continue;
    const id = m[1];
    if (seen.has(id)) continue;
    seen.add(id);
    ids.push(id);
  }
  if (!ids.length) throw new Error(`--from-models: no <slug>-<id>.3mf files in ${modelsDir}`);
  log(`Scanning ${modelsDir}: ${ids.length} model file(s) found; enriching ...`);

  const records = [];
  for (let i = 0; i < ids.length; i++) {
    const rec = await enrichModel(ids[i]).catch(() => null);
    // Even if enrichment fails, keep a minimal record so the file still exports.
    records.push(rec || { id: ids[i], title: ids[i] });
    log(`  [${i + 1}/${ids.length}] ${ids[i]}${rec ? ` — ${rec.title}` : ' (enrich failed; minimal row)'}`);
  }
  return records;
}

export async function exportBundle({
  inFile = 'out/records50.json',
  outCsv = 'out/products.csv',
  modelsDir = 'out/models3d',
  download = true,
  fromModels = false,
  delayMs = 1500,
  log = (m) => console.error(m),
} = {}) {
  // --from-models derives the record list from the .3mf files on disk (implies
  // no download — you already have the files). Otherwise read the records file.
  const records = fromModels
    ? await recordsFromModelsDir(modelsDir, log)
    : JSON.parse(await readFile(inFile, 'utf8'));
  if (fromModels) download = false;
  await mkdir(modelsDir, { recursive: true });

  const auth = download ? await loadAuth() : null;
  if (auth) log(`Auth loaded from ${auth.source}.`);

  const rows = [];
  for (let i = 0; i < records.length; i++) {
    const rec = records[i];
    // Ensure cost/weight/instance fields are present (re-enrich if missing).
    const full =
      rec.weightGrams !== undefined ? rec : { ...rec, ...(await enrichModel(rec.id).catch(() => ({}))) };

    const fname = `${slug(full.title)}-${full.id}.3mf`;
    const fpath = `${modelsDir}/${fname}`;
    let fileRef = '';
    let didDownload = false;

    // The CSV ref + S3 key are the SHARED canonical ref (model-ref.mjs), NOT the
    // local slug filename - so the object the app looks up is exactly the one we
    // upload. The local out/ file keeps its human-friendly slug name.
    const ref = modelRef(full.id);

    if (await exists(fpath)) {
      // Resume: already have this file from a previous run — reuse, don't refetch.
      fileRef = ref;
      log(`[${i + 1}/${records.length}] have ${fname} (cached)`);
      try {
        await uploadModelFile(ref, await readFile(fpath));
      } catch (e) {
        log(`  ! S3 upload failed for ${ref}: ${e.message}`);
      }
    } else if (download) {
      try {
        const got = await fetchModelFile(full.id, auth, full);
        if (got) {
          await writeFile(fpath, got.buffer);
          fileRef = ref;
          didDownload = true;
          log(`[${i + 1}/${records.length}] saved ${fname} (${(got.buffer.length / 1e6).toFixed(1)}MB)`);
          try {
            await uploadModelFile(ref, got.buffer);
          } catch (e) {
            log(`  ! S3 upload failed for ${ref}: ${e.message}`);
          }
        } else {
          log(`[${i + 1}/${records.length}] BLOCKED ${full.id} — captcha/quota. model_file_ref blank.`);
        }
      } catch (e) {
        log(`[${i + 1}/${records.length}] download failed ${full.id}: ${e.message}`);
      }
    }

    rows.push(toRow(full, fileRef));
    // Only pace after an actual network download (cached rows are instant).
    if (didDownload && i < records.length - 1) await sleep(delayMs);
  }

  await mkdir(outCsv.replace(/[^/]*$/, '') || '.', { recursive: true });
  await writeFile(outCsv, toCsv(rows));
  log(`Wrote ${rows.length} rows -> ${outCsv}`);
  log(`Model files in -> ${modelsDir}/`);
  return rows;
}

// --- CLI -----------------------------------------------------------------
if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  const args = process.argv.slice(2);
  const str = (f, d) => (args.indexOf(f) !== -1 ? args[args.indexOf(f) + 1] : d);
  const num = (f, d) => (args.indexOf(f) !== -1 ? Number(args[args.indexOf(f) + 1]) : d);
  exportBundle({
    inFile: str('--in', 'out/records50.json'),
    outCsv: str('--out', 'out/products.csv'),
    modelsDir: str('--models', 'out/models3d'),
    download: !args.includes('--no-download'),
    fromModels: args.includes('--from-models'),
    delayMs: num('--delay', 1500),
  }).catch((e) => {
    console.error('Export failed:', e.message);
    process.exit(1);
  });
}
