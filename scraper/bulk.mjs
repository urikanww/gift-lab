#!/usr/bin/env node
/**
 * Bulk MakerWorld pipeline with human-like pacing.
 *
 *   list (tokenless) -> [enrich per record] -> [download .3mf per record]
 *
 * A randomized delay is inserted between every per-record request so the
 * traffic looks like a person browsing rather than a scraper. Tune with
 * --delay <ms> (base) ; actual wait = base + random(0..base) jitter.
 *
 * Usage:
 *   node bulk.mjs 50                       list+enrich 50 records -> out/records.json
 *   node bulk.mjs 50 --download            also download each .3mf (needs token.txt)
 *   node bulk.mjs 50 --free --download     only directly-downloadable models
 *   node bulk.mjs 50 --offset 50           resume: skip first 50, take next 50
 *   node bulk.mjs 50 --delay 3000 --out out/records.json
 */

import { writeFile, mkdir } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import { listModels } from './list.mjs';
import { enrichModel } from './enrich.mjs';
import { downloadModel } from './download.mjs';
import { loadAuth } from './auth.mjs';

/** Sleep with jitter: base + random(0..base). Feels less robotic than a fixed gap. */
function humanDelay(base) {
  const ms = base + Math.floor(deterministicJitter() * base);
  return new Promise((r) => setTimeout(r, ms));
}

// Math.random() is unavailable in some sandboxes; derive jitter from a rolling
// counter + time so gaps still vary run-to-run without being fully predictable.
let _tick = 0;
function deterministicJitter() {
  _tick += 1;
  const x = Math.sin(_tick * 12.9898 + Date.now() % 1000) * 43758.5453;
  return x - Math.floor(x); // 0..1
}

export async function bulk({
  limit = 50,
  offset = 0,
  orderBy = 'hotScore',
  freeOnly = false,
  enrich = true,
  download = false,
  delayMs = 2500,
  outFile = 'out/records.json',
  log = (m) => console.error(m),
} = {}) {
  // 1. List (tokenless). Over-fetch when filtering to free so we still hit `limit`.
  log(`Listing ${freeOnly ? '(free only) ' : ''}${limit} models, order=${orderBy}${offset ? `, offset=${offset}` : ''} ...`);
  let records = await listModels({
    limit: freeOnly ? limit * 3 : limit,
    offset,
    orderBy,
    delayMs, // paced page fetches
  });
  if (freeOnly) records = records.filter((m) => m.free);
  records = records.slice(0, limit);
  log(`Got ${records.length} records.`);

  const auth = download ? await loadAuth() : null;
  if (auth) log(`Auth loaded from ${auth.source}.`);

  // 2. Per-record enrich (+ optional download), paced like a human.
  const out = [];
  for (let i = 0; i < records.length; i++) {
    const rec = records[i];
    let full = rec;

    if (enrich || download) {
      const rich = await enrichModel(rec.id).catch(() => null);
      if (rich) full = { ...rec, ...rich };
      log(`[${i + 1}/${records.length}] enriched ${rec.id} — ${rec.title}`);
    }

    if (download) {
      if (full.isPointRedeemable || full.isPaid) {
        log(`  skip download ${rec.id} (gated: points/paid)`);
        full.download = { saved: [], skipped: ['gated'] };
      } else {
        const res = await downloadModel({ id: rec.id, auth }).catch((e) => ({
          saved: [],
          skipped: [e.message],
        }));
        full.download = res;
      }
    }

    out.push(full);

    // Pace: wait between records (skip after the last one).
    if (i < records.length - 1) {
      const wait = await pace(delayMs, log);
      void wait;
    }
  }

  await mkdir(outFile.replace(/[^/]*$/, '') || '.', { recursive: true });
  await writeFile(outFile, JSON.stringify(out, null, 2));
  log(`Wrote ${out.length} records -> ${outFile}`);
  return out;
}

async function pace(base, log) {
  const start = Date.now();
  await humanDelay(base);
  const waited = Date.now() - start;
  log(`  ...waited ${waited}ms`);
}

// --- CLI -----------------------------------------------------------------
if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  const args = process.argv.slice(2);
  const num = (flag, def) => {
    const i = args.indexOf(flag);
    return i !== -1 ? Number(args[i + 1]) : def;
  };
  const str = (flag, def) => {
    const i = args.indexOf(flag);
    return i !== -1 ? args[i + 1] : def;
  };
  const limit = Number(args.find((a) => /^\d+$/.test(a))) || 50;

  bulk({
    limit,
    offset: num('--offset', 0),
    orderBy: str('--order', 'hotScore'),
    freeOnly: args.includes('--free'),
    enrich: !args.includes('--no-enrich'),
    download: args.includes('--download'),
    delayMs: num('--delay', 2500),
    outFile: str('--out', 'out/records.json'),
  }).catch((e) => {
    console.error('Bulk failed:', e.message);
    process.exit(1);
  });
}
