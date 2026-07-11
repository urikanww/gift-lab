#!/usr/bin/env node
/**
 * CDP-attach MakerWorld downloader — rides your REAL Chrome.
 *
 * MakerWorld's login and GeeTest captcha both detect Playwright's own browser
 * (navigator.webdriver etc.) and block it — the login page just loops. The fix
 * is to NOT use Playwright's browser at all: you launch your normal Chrome with
 * remote debugging, log in + solve captchas as a human, and this script attaches
 * over CDP and pulls files through that same trusted session (shared cookies).
 *
 * ── SETUP (one time) ────────────────────────────────────────────────────────
 * 1. Fully quit Chrome.
 * 2. Launch it in debug mode with a dedicated profile (login persists here):
 *
 *    Windows:
 *      "C:\Program Files\Google\Chrome\Application\chrome.exe" ^
 *        --remote-debugging-port=9222 --user-data-dir="C:\mw-chrome"
 *
 *    (macOS: /Applications/Google\ Chrome.app/Contents/MacOS/Google\ Chrome
 *            --remote-debugging-port=9222 --user-data-dir="$HOME/mw-chrome")
 *
 * 3. In that Chrome, go to makerworld.com and LOG IN (email or Google — this is
 *    your real Chrome, so both work). Leave it open.
 * 4. Run:  node cdp-download.mjs
 * ────────────────────────────────────────────────────────────────────────────
 *
 * Resume-aware: files already in the models dir are skipped. Saved as
 * <modelsDir>/<slug>-<id>.3mf so `node export.mjs --no-download` wires them in.
 */

import { chromium } from 'playwright';
import { readFile, writeFile, mkdir, access } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import { enrichModel } from './enrich.mjs';
import { pickDevModelName } from './pick-dev-model.mjs';

const CDP_URL = 'http://localhost:9222';

const F3MF = (instanceId, { type = 'original', devModelName = '' } = {}) => {
  const q = new URLSearchParams({ type });
  if (devModelName) q.set('devModelName', devModelName);
  return `https://makerworld.com/api/v1/design-service/instance/${instanceId}/f3mf?${q}`;
};

const slug = (s) =>
  (s || '')
    .normalize('NFKD')
    .replace(/[^\w\s-]/g, '')
    .trim()
    .replace(/\s+/g, '-')
    .toLowerCase()
    .slice(0, 50) || 'model';

export async function cdpDownload({
  ids,
  inFile = 'out/records50.json',
  modelsDir = 'out/models3d',
  cdpUrl = CDP_URL,
  log = (m) => console.error(m),
} = {}) {
  if (!ids || ids.length === 0) {
    const recs = JSON.parse(await readFile(inFile, 'utf8'));
    ids = recs.map((r) => String(r.id));
  }
  await mkdir(modelsDir, { recursive: true });

  let browser;
  try {
    browser = await chromium.connectOverCDP(cdpUrl);
  } catch (e) {
    throw new Error(
      `Could not attach to Chrome at ${cdpUrl}. Launch Chrome with ` +
        `--remote-debugging-port=9222 first (see the header of this file). (${e.message})`,
    );
  }

  const context = browser.contexts()[0];
  if (!context) throw new Error('No browser context found over CDP.');
  const page = context.pages()[0] || (await context.newPage());

  if (!(await isLoggedIn(context))) {
    await page.goto('https://makerworld.com/en', { waitUntil: 'domcontentloaded' }).catch(() => {});
    log('Not logged in on this Chrome. Log into makerworld.com in that window, then press ENTER.');
    await waitForEnter();
  }
  log('Attached to your Chrome session. Starting downloads.\n');

  const saved = [];
  const failed = [];

  for (let i = 0; i < ids.length; i++) {
    const id = ids[i];
    const meta = await enrichModel(id).catch(() => null);
    if (!meta || !meta.defaultInstanceId) {
      log(`[${i + 1}/${ids.length}] ${id}: no instance — skip`);
      failed.push(id);
      continue;
    }
    const fname = `${slug(meta.title)}-${id}.3mf`;
    const fpath = `${modelsDir}/${fname}`;
    if (await exists(fpath)) {
      log(`[${i + 1}/${ids.length}] ${fname} cached — skip`);
      saved.push(fname);
      continue;
    }

    const { devModelName } = pickDevModelName(meta); // prefer H2S (O1S)
    let ok = false;
    for (let attempt = 0; attempt < 4 && !ok; attempt++) {
      const buf = await pull(context, meta.defaultInstanceId, devModelName);
      if (buf === 'CAPTCHA') {
        await page
          .goto(`https://makerworld.com/en/models/${id}`, { waitUntil: 'domcontentloaded' })
          .catch(() => {});
        log(
          `\n!!! Captcha for ${id}. In your Chrome: click Download on the model, ` +
            `solve the captcha, then press ENTER here to continue.\n`,
        );
        await waitForEnter();
        continue;
      }
      if (buf) {
        await writeFile(fpath, buf);
        saved.push(fname);
        log(`[${i + 1}/${ids.length}] saved ${fname} (${(buf.length / 1e6).toFixed(1)}MB)`);
        ok = true;
      } else {
        break;
      }
    }
    if (!ok) {
      failed.push(id);
      log(`[${i + 1}/${ids.length}] ${id}: could not download`);
    }
  }

  await browser.close(); // detaches from CDP; your Chrome stays open
  log(`\nDone. ${saved.length} file(s) in ${modelsDir}/, ${failed.length} failed.`);
  log(`Next: node export.mjs --no-download --out out/products.csv --models ${modelsDir}`);
  return { saved, failed };
}

/** @returns {Promise<Buffer|null|'CAPTCHA'>} */
async function pull(context, instanceId, devModelName) {
  for (const type of ['original', 'preview']) {
    const res = await context.request.get(F3MF(instanceId, { type, devModelName }), {
      headers: { Referer: 'https://makerworld.com/', Accept: 'application/json, */*' },
      timeout: 60_000,
    });
    const ct = res.headers()['content-type'] || '';
    if (ct.includes('application/json')) {
      const j = await res.json().catch(() => ({}));
      if (j.captchaId || /not a robot/i.test(j.error || '')) return 'CAPTCHA';
      if (j.error) continue;
      const url = j.url || j.downloadUrl || j.data?.url;
      if (!url) continue;
      // Model files can be 100MB+ — disable the timeout for the body download.
      const dl = await context.request.get(url, { timeout: 0 });
      if (dl.ok()) return Buffer.from(await dl.body());
    } else if (res.ok()) {
      return Buffer.from(await res.body());
    }
  }
  return null;
}

async function isLoggedIn(context) {
  const cookies = await context.cookies('https://makerworld.com');
  return cookies.some((c) => /token|session|uid/i.test(c.name) && c.value && c.value.length > 8);
}
function waitForEnter() {
  return new Promise((resolve) => {
    process.stdin.resume();
    process.stdin.once('data', () => {
      process.stdin.pause();
      resolve();
    });
  });
}
async function exists(p) {
  try {
    await access(p);
    return true;
  } catch {
    return false;
  }
}

// --- CLI -----------------------------------------------------------------
if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  const args = process.argv.slice(2);
  const str = (f, d) => (args.indexOf(f) !== -1 ? args[args.indexOf(f) + 1] : d);
  const ids = args.filter((a) => /^\d+$/.test(a));
  cdpDownload({
    ids: ids.length ? ids : null,
    inFile: str('--in', 'out/records50.json'),
    modelsDir: str('--models', 'out/models3d'),
    cdpUrl: str('--cdp', CDP_URL),
  }).catch((e) => {
    console.error(e.message);
    process.exit(1);
  });
}
