#!/usr/bin/env node
/**
 * Hand-picked MakerWorld models -> records.json.
 *
 * When you browse MakerWorld yourself and find models worth adding to Gift Lab,
 * this turns the URLs (or bare ids) you collected into the SAME records.json the
 * batch scraper produces — so the rest of the pipeline is unchanged:
 *
 *   node pick.mjs --file out/picks.txt --out out/records.json   # then:
 *   node cdp-download.mjs --in out/records.json --models out/models3d
 *   node export.mjs --no-download --in out/records.json --out out/products.csv --models out/models3d
 *   php artisan products:import out/products.csv
 *
 * Input (either or both):
 *   --file <path>   text file, one URL or id per line (# comments + blanks ok)
 *   <url|id> ...    URLs / ids passed as args
 *
 * Accepts full URLs (makerworld.com/en/models/1234567-some-slug), share links,
 * or bare numeric ids. Unresolvable ids are skipped and logged; ids are deduped.
 */

import { readFile, writeFile, mkdir } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import { enrichModel } from './enrich.mjs';

/** Pull a numeric MakerWorld model id out of a URL or bare id string. */
export function parseModelId(token = '') {
  const s = String(token).trim();
  if (!s || s.startsWith('#')) return null;
  if (/^\d+$/.test(s)) return s; // already a bare id
  // .../models/1234567  or  .../models/1234567-some-title-slug
  const m = s.match(/\/models\/(\d+)/);
  if (m) return m[1];
  // last-ditch: a long digit run anywhere (share links etc.)
  const any = s.match(/(\d{5,})/);
  return any ? any[1] : null;
}

/**
 * Resolve a list of URL/id tokens into enriched records (records.json shape).
 * @param {object} opts
 * @param {string[]} [opts.tokens=[]]  URLs / ids from the CLI args
 * @param {string}   [opts.file]        path to a newline-delimited URL/id list
 * @param {(m:string)=>void} [opts.log=console.error]
 * @returns {Promise<Array<object>>}
 */
export async function pickRecords({ tokens = [], file, log = (m) => console.error(m) } = {}) {
  const raw = [...tokens];
  if (file) {
    const text = await readFile(file, 'utf8');
    raw.push(...text.split(/\r?\n/));
  }

  // Parse + dedupe ids, preserving the order they were listed.
  const ids = [];
  const seen = new Set();
  for (const tok of raw) {
    const id = parseModelId(tok);
    if (!id) {
      if (String(tok).trim() && !String(tok).trim().startsWith('#')) {
        log(`  ! could not parse an id from "${String(tok).trim()}" — skipped`);
      }
      continue;
    }
    if (seen.has(id)) continue;
    seen.add(id);
    ids.push(id);
  }

  if (!ids.length) throw new Error('No model ids found in the input.');
  log(`Resolving ${ids.length} hand-picked model(s) ...`);

  const records = [];
  for (let i = 0; i < ids.length; i++) {
    const id = ids[i];
    const rec = await enrichModel(id).catch(() => null);
    if (!rec) {
      log(`[${i + 1}/${ids.length}] ${id}: not found via detail API — skipped`);
      continue;
    }
    records.push(rec);
    log(`[${i + 1}/${ids.length}] ${id} — ${rec.title}`);
  }
  return records;
}

// --- CLI -----------------------------------------------------------------
if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  const args = process.argv.slice(2);
  const str = (f, d) => (args.indexOf(f) !== -1 ? args[args.indexOf(f) + 1] : d);
  const file = str('--file', null);
  const outFile = str('--out', 'out/records.json');

  // Bare tokens = everything that isn't a flag or a flag's consumed value.
  const consumed = new Set();
  for (const f of ['--file', '--out']) {
    const i = args.indexOf(f);
    if (i !== -1) consumed.add(i + 1);
  }
  const tokens = args.filter((a, i) => !a.startsWith('--') && !consumed.has(i));

  pickRecords({ tokens, file })
    .then(async (records) => {
      if (!records.length) {
        console.error('No records resolved — nothing written.');
        process.exit(2);
      }
      await mkdir(outFile.replace(/[^/]*$/, '') || '.', { recursive: true });
      await writeFile(outFile, JSON.stringify(records, null, 2));
      console.error(`Wrote ${records.length} record(s) -> ${outFile}`);
      console.error(
        `Next: node cdp-download.mjs --in ${outFile} --models out/models3d`,
      );
    })
    .catch((e) => {
      console.error('Pick failed:', e.message);
      process.exit(1);
    });
}
