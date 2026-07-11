#!/usr/bin/env node
/**
 * MakerWorld 3D-model scraper.
 *
 * The listing page (https://makerworld.com/en/3d-models) is a Cloudflare-gated
 * Next.js app: plain server-side HTTP requests get a 403 challenge and the
 * public JSON API returns empty bodies without browser session state. So we
 * drive a real Chromium via Playwright, let it pass the challenge and hydrate,
 * then harvest models two ways:
 *
 *   1. API capture  - listen for the search XHR the page fires and read its
 *                     clean JSON payload (preferred; richest fields).
 *   2. DOM fallback - if no API response is seen, parse the rendered cards.
 *
 * Infinite scroll is driven until `limit` models are collected or the page
 * stops growing.
 */

import { chromium } from 'playwright';
import { writeFile } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import { enrichModel } from './enrich.mjs';

const LIST_URL = 'https://makerworld.com/en/3d-models';

/** URL fragments that identify the model-search JSON response. */
const API_HINTS = ['/api/v1/search', '/design-service/', '/api/v1/design'];

/**
 * Scrape models from the MakerWorld listing page.
 *
 * @param {object} [opts]
 * @param {number}  [opts.limit=60]     Max models to collect.
 * @param {boolean} [opts.headless=true] Run Chromium headless.
 * @param {number}  [opts.timeoutMs=60000] Per-navigation timeout.
 * @param {(msg:string)=>void} [opts.log=console.error] Progress sink (stderr).
 * @returns {Promise<Array<object>>} Normalized model records.
 */
export async function scrapeModels(opts = {}) {
  const {
    limit = 60,
    headless = true,
    timeoutMs = 60_000,
    log = (m) => console.error(m),
  } = opts;

  const browser = await chromium.launch({ headless });
  const context = await browser.newContext({
    userAgent:
      'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 ' +
      '(KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
    locale: 'en-US',
    viewport: { width: 1440, height: 900 },
  });
  const page = await context.newPage();

  /** @type {Map<string, object>} id -> normalized model (dedupes across sources) */
  const byId = new Map();

  // 1. API capture: sniff every JSON response, pull anything model-shaped.
  page.on('response', async (res) => {
    const url = res.url();
    if (!API_HINTS.some((h) => url.includes(h))) return;
    if (!(res.headers()['content-type'] || '').includes('application/json')) return;
    try {
      const json = await res.json();
      for (const raw of extractList(json)) {
        const m = normalize(raw);
        if (m.id) byId.set(m.id, { ...m, ...byId.get(m.id) });
      }
    } catch {
      /* non-JSON / consumed body - ignore */
    }
  });

  log(`Opening ${LIST_URL} ...`);
  await page.goto(LIST_URL, { waitUntil: 'domcontentloaded', timeout: timeoutMs });

  // Wait for either a model card or the Cloudflare challenge to clear.
  await page
    .waitForSelector('a[href*="/models/"]', { timeout: timeoutMs })
    .catch(() => log('No model-card selector yet; relying on API capture.'));

  // 2. Infinite scroll until we have enough or the page stalls.
  let stagnantRounds = 0;
  let lastCount = 0;
  for (let round = 0; round < 40; round++) {
    const domModels = await scrapeDom(page);
    for (const m of domModels) {
      if (m.id) byId.set(m.id, { ...byId.get(m.id), ...m });
    }

    const count = byId.size;
    log(`round ${round}: ${count} models collected`);
    if (count >= limit) break;

    stagnantRounds = count === lastCount ? stagnantRounds + 1 : 0;
    if (stagnantRounds >= 3) {
      log('Page stopped growing; stopping.');
      break;
    }
    lastCount = count;

    await page.mouse.wheel(0, 4000);
    await page.waitForTimeout(1200); // let lazy content + XHR settle
  }

  await browser.close();

  return [...byId.values()].slice(0, limit);
}

/** Pull an array of model-like objects out of an arbitrary API JSON shape. */
function extractList(json) {
  if (Array.isArray(json)) return json;
  for (const key of ['hits', 'list', 'models', 'designs', 'records', 'data']) {
    const v = json?.[key];
    if (Array.isArray(v)) return v;
    if (Array.isArray(v?.hits)) return v.hits;
    if (Array.isArray(v?.list)) return v.list;
  }
  return [];
}

/** Map a raw API record to our normalized shape (best-effort, tolerant of unknown keys). */
function normalize(r = {}) {
  const id = String(
    r.id ?? r.designId ?? r.modelId ?? r.design_id ?? r.model_id ?? '',
  ).trim();
  const author = r.designer || r.author || r.user || {};
  return {
    id,
    title: r.title || r.name || r.designTitle || '',
    url: id ? `https://makerworld.com/en/models/${id}` : r.url || '',
    cover: r.cover || r.coverUrl || r.coverImage || r.image || '',
    author: author.name || author.nickname || author.handle || '',
    likes: num(r.likeCount ?? r.likes ?? r.like),
    downloads: num(r.downloadCount ?? r.downloads ?? r.download),
    boosts: num(r.boostCount ?? r.boosts),
  };
}

/** Parse the rendered DOM cards as a fallback / supplement to API capture. */
function scrapeDom(page) {
  return page.evaluate(() => {
    const seen = new Set();
    const out = [];
    for (const a of document.querySelectorAll('a[href*="/models/"]')) {
      const href = a.getAttribute('href') || '';
      const match = href.match(/\/models\/(\d+)/);
      if (!match) continue;
      const id = match[1];
      if (seen.has(id)) continue;
      seen.add(id);

      const card = a.closest('article, li, div') || a;
      const img = card.querySelector('img');
      const title =
        a.getAttribute('title') ||
        img?.getAttribute('alt') ||
        card.querySelector('h2, h3, [class*="title"]')?.textContent?.trim() ||
        '';

      out.push({
        id,
        title,
        url: new URL(href, location.origin).href,
        cover: img?.getAttribute('src') || '',
        author: card.querySelector('[class*="designer"], [class*="author"], [class*="user"]')
          ?.textContent?.trim() || '',
      });
    }
    return out;
  });
}

function num(v) {
  const n = Number(v);
  return Number.isFinite(n) ? n : 0;
}

/**
 * Enrich scraped models in parallel (bounded) via the anonymous detail API:
 * adds file list (.3mf/.stl names+types), all thumbnails, license, print/paid flags.
 */
async function enrichAll(models, { concurrency = 6, log = (m) => console.error(m) } = {}) {
  const out = [];
  for (let i = 0; i < models.length; i += concurrency) {
    const batch = models.slice(i, i + concurrency);
    const enriched = await Promise.all(
      batch.map(async (m) => {
        const rich = await enrichModel(m.id).catch(() => null);
        return rich ? { ...m, ...rich } : m;
      }),
    );
    out.push(...enriched);
    log(`enriched ${out.length}/${models.length}`);
  }
  return out;
}

// ---- CLI ----------------------------------------------------------------
// Usage: node scrape-makerworld.mjs [limit] [--headed] [--enrich] [--out file.json]
if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  const args = process.argv.slice(2);
  const limit = Number(args.find((a) => /^\d+$/.test(a))) || 60;
  const headless = !args.includes('--headed');
  const wantEnrich = args.includes('--enrich');
  const outIdx = args.indexOf('--out');
  const outFile = outIdx !== -1 ? args[outIdx + 1] : null;

  scrapeModels({ limit, headless })
    .then((models) => (wantEnrich ? enrichAll(models) : models))
    .then(async (models) => {
      const json = JSON.stringify(models, null, 2);
      if (outFile) {
        await writeFile(outFile, json);
        console.error(`Wrote ${models.length} models -> ${outFile}`);
      } else {
        console.log(json);
      }
    })
    .catch((err) => {
      console.error('Scrape failed:', err);
      process.exit(1);
    });
}
