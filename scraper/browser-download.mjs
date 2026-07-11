#!/usr/bin/env node
/**
 * Browser-assisted MakerWorld downloader (GeeTest-friendly).
 *
 * Headless API download hits MakerWorld's GeeTest captcha after ~2 files. This
 * script instead drives a REAL, visible Chromium with a PERSISTENT profile:
 *
 *   - You log in once; the profile (cookies + GeeTest trust) is saved to
 *     .mw-profile/ and reused on every later run — far fewer re-challenges.
 *   - Files are pulled through the logged-in browser session (context.request),
 *     so they inherit whatever trust the visible browser has earned.
 *   - When a captcha DOES appear, the script pauses and asks you to solve it in
 *     the open window, then continues. No captcha-solving automation.
 *   - Resume: any .3mf already in the models dir is skipped.
 *
 * Files are saved as  <modelsDir>/<slug>-<id>.3mf  — the exact names export.mjs
 * expects, so `node export.mjs --no-download` afterwards wires them into the CSV.
 *
 * Usage:
 *   node browser-download.mjs                 # download every id in records50.json
 *   node browser-download.mjs 3015782 3018896 # only these ids
 *   node browser-download.mjs --in out/records50.json --models out/models3d
 */

import { chromium } from 'playwright';
import { readFile, writeFile, mkdir, access } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import { enrichModel } from './enrich.mjs';
import { pickDevModelName } from './pick-dev-model.mjs';

const PROFILE_DIR = '.mw-profile';

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

export async function browserDownload({
  ids,
  inFile = 'out/records50.json',
  modelsDir = 'out/models3d',
  log = (m) => console.error(m),
} = {}) {
  // Resolve the id list (arg > records file).
  if (!ids || ids.length === 0) {
    const recs = JSON.parse(await readFile(inFile, 'utf8'));
    ids = recs.map((r) => String(r.id));
  }
  await mkdir(modelsDir, { recursive: true });

  const context = await chromium.launchPersistentContext(PROFILE_DIR, {
    headless: false,
    viewport: { width: 1280, height: 900 },
    acceptDownloads: true,
  });
  // Reuse the tab that opens with the context — don't spawn extra ones.
  const page = context.pages()[0] || (await context.newPage());

  // Log in to the MAKERWORLD WEBSITE inside THIS window (not Chrome/Google).
  await page.goto('https://makerworld.com/en/login', { waitUntil: 'domcontentloaded' });
  log(
    '\n================ LOGIN ================\n' +
      'In the browser window that just opened (it is a separate, empty browser):\n' +
      '  1. Log in to the MAKERWORLD WEBSITE — use EMAIL + password.\n' +
      '     (Google sign-in is often blocked in automated browsers — avoid it.)\n' +
      '  2. Wait until you see your avatar / are back on makerworld.com.\n' +
      '  3. Then press ENTER here.\n' +
      'This is NOT signing into Chrome — ignore any Chrome/Google profile prompts.\n' +
      '======================================\n',
  );

  // Wait for login, verifying the auth cookie actually landed. Retry politely.
  for (let tries = 0; tries < 5; tries++) {
    await waitForEnter();
    if (await isLoggedIn(context)) {
      log('Login detected. Starting downloads.\n');
      break;
    }
    log(
      'No MakerWorld session cookie yet. Make sure you logged into the WEBSITE ' +
        '(makerworld.com) in this window, not Chrome. Press ENTER to re-check.',
    );
    if (tries === 4) {
      log('Still no session — proceeding anyway; downloads may fail.');
    }
  }

  const saved = [];
  const failed = [];

  for (let i = 0; i < ids.length; i++) {
    const id = ids[i];
    const meta = await enrichModel(id).catch(() => null);
    if (!meta || !meta.defaultInstanceId) {
      log(`[${i + 1}/${ids.length}] ${id}: no instance — skipped`);
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

    for (let attempt = 0; attempt < 3 && !ok; attempt++) {
      const buf = await pullViaSession(context, meta.defaultInstanceId, devModelName, page);
      if (buf === 'CAPTCHA') {
        // Bring the model page up so the user can trigger + solve the challenge.
        await page.goto(`https://makerworld.com/en/models/${id}`, { waitUntil: 'domcontentloaded' }).catch(() => {});
        log(
          `\n!!! Captcha for ${id}. In the browser: click Download on the model, ` +
            `solve the captcha, then press ENTER here to continue.\n`,
        );
        await waitForEnter();
        continue; // retry the session pull now that trust is refreshed
      }
      if (buf) {
        await writeFile(fpath, buf);
        saved.push(fname);
        log(`[${i + 1}/${ids.length}] saved ${fname} (${(buf.length / 1e6).toFixed(1)}MB)`);
        ok = true;
      } else {
        break; // hard failure (gated/paid/no file)
      }
    }
    if (!ok) {
      failed.push(id);
      log(`[${i + 1}/${ids.length}] ${id}: could not download`);
    }
  }

  await context.close();
  log(`\nDone. ${saved.length} file(s) in ${modelsDir}/, ${failed.length} failed.`);
  log(`Next: node export.mjs --no-download --out out/products.csv --models ${modelsDir}`);
  return { saved, failed };
}

/**
 * Pull f3mf bytes through the logged-in browser session.
 * @returns {Promise<Buffer|null|'CAPTCHA'>}
 */
async function pullViaSession(context, instanceId, devModelName, page) {
  for (const type of ['original', 'preview']) {
    const res = await context.request.get(F3MF(instanceId, { type, devModelName }), {
      headers: { Referer: 'https://makerworld.com/', Accept: 'application/json, */*' },
      timeout: 60_000,
    });
    const ct = res.headers()['content-type'] || '';
    if (ct.includes('application/json')) {
      const j = await res.json().catch(() => ({}));
      if (j.captchaId || /not a robot/i.test(j.error || '')) return 'CAPTCHA';
      if (j.error) continue; // login/gated — try next type
      const url = j.url || j.downloadUrl || j.data?.url;
      if (!url) continue;
      const dl = await context.request.get(url, { timeout: 0 }); // 100MB+ files
      if (dl.ok()) return Buffer.from(await dl.body());
    } else if (res.ok()) {
      return Buffer.from(await res.body());
    }
  }
  return null;
}

/** True once a MakerWorld auth/session cookie is present in the context. */
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
  browserDownload({
    ids: ids.length ? ids : null,
    inFile: str('--in', 'out/records50.json'),
    modelsDir: str('--models', 'out/models3d'),
  }).catch((e) => {
    console.error('Browser download failed:', e.message);
    process.exit(1);
  });
}
