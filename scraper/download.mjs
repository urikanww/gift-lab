#!/usr/bin/env node
/**
 * Authenticated 3D-file downloader for MakerWorld (token / cookie based).
 *
 * MakerWorld gates the actual .3mf bytes behind a JWT:
 *   GET /api/v1/design-service/instance/{instanceId}/f3mf?type=original&devModelName=...
 *   -> 403 {"error":"Please log in to download models."}  when unauthenticated.
 *
 * Supply your `token` cookie once (token.txt or MW_TOKEN, see auth.mjs) and this
 * replays the same authorized request the website makes, then saves the file.
 *
 * Pipeline per model:
 *   id --enrich.mjs--> defaultInstanceId + target printer (devModelName)
 *      --f3mf(auth)--> raw 3mf bytes (or a presigned URL we then fetch)
 *      --> out/<id>/<title>_<instance>_<type>.3mf
 */

import { mkdir, writeFile } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import { enrichModel } from './enrich.mjs';
import { loadAuth } from './auth.mjs';
import { pickDevModelName } from './pick-dev-model.mjs';
import { uploadModelFile } from './s3-upload.mjs';
import { modelRef } from './model-ref.mjs';

const UA =
  'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 ' +
  '(KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

const F3MF = (instanceId, { type = 'original', devModelName = '' } = {}) => {
  const q = new URLSearchParams({ type });
  if (devModelName) q.set('devModelName', devModelName);
  return `https://makerworld.com/api/v1/design-service/instance/${instanceId}/f3mf?${q}`;
};

/**
 * Fetch the printable 3mf bytes for a model (raw, then preview fallback).
 * Reusable by the CLI and the CSV exporter.
 * @param {string|number} id
 * @param {object} auth   loadAuth() result
 * @param {object} [meta] pre-fetched enrichModel(id) result (avoids a re-fetch)
 * @returns {Promise<{buffer:Buffer, type:string, meta:object}|null>}
 */
export async function fetchModelFile(id, auth, meta) {
  meta = meta || (await enrichModel(id));
  if (!meta) throw new Error(`Model ${id} not found via detail API.`);
  const instanceId = meta.defaultInstanceId;
  // Prefer the H2S (O1S) slice so the .3mf is print-ready on our printer.
  const { devModelName } = pickDevModelName(meta);
  if (!instanceId) return null;
  for (const type of ['original', 'preview']) {
    const buffer = await fetchFile(F3MF(instanceId, { type, devModelName }), auth);
    if (buffer) return { buffer, type, meta };
  }
  return null;
}

/**
 * Download files for one model using the loaded auth.
 * @param {object} opts
 * @param {string|number} opts.id
 * @param {object} opts.auth              Result of loadAuth() (reused across models).
 * @param {string} [opts.outDir='out']
 * @returns {Promise<{saved:string[], skipped:string[]}>}
 */
export async function downloadModel({ id, auth, outDir = 'out' }) {
  const meta = await enrichModel(id);
  if (!meta) throw new Error(`Model ${id} not found via detail API.`);

  if (meta.isPointRedeemable || meta.isPaid) {
    console.error(
      `! Model ${id} "${meta.title}" is gated (pointRedeemable=${meta.isPointRedeemable} ` +
        `paid=${meta.isPaid}). Redeem it on your account first; download may fail.`,
    );
  }

  const dir = `${outDir}/${id}`;
  await mkdir(dir, { recursive: true });
  await writeFile(`${dir}/model.json`, JSON.stringify(meta, null, 2));

  const saved = [];
  const skipped = [];
  const instanceId = meta.defaultInstanceId;
  // Prefer the H2S (O1S) slice; flag models that lack it for a later re-slice.
  const { devModelName, isH2s } = pickDevModelName(meta);
  if (!isH2s) {
    console.error(`  ! model ${id} has no O1S/H2S slice (using "${devModelName || 'default'}") - re-slice needed`);
  }

  if (!instanceId) {
    skipped.push(`model ${id} (no instance id)`);
    return { saved, skipped };
  }

  // Prefer the raw uploaded 3mf; fall back to the sliced preview.
  for (const type of ['original', 'preview']) {
    try {
      const buf = await fetchFile(F3MF(instanceId, { type, devModelName }), auth);
      if (!buf) {
        skipped.push(`instance ${instanceId} type=${type} (auth rejected / no bytes)`);
        continue;
      }
      const fname = `${slug(meta.title) || id}_${instanceId}_${type}.3mf`;
      await writeFile(`${dir}/${fname}`, buf);
      saved.push(`${dir}/${fname}`);
      console.error(`  saved ${fname} (${(buf.length / 1024 / 1024).toFixed(1)} MB)`);

      // Direct-to-S3: upload straight to the backend's private models3d disk so
      // the CSV ref resolves without a separate push step. Keyed on the SHARED
      // canonical ref (identical to the CSV model_file_ref). No-op without creds.
      const ref = modelRef(id);
      try {
        await uploadModelFile(ref, buf);
      } catch (e) {
        console.error(`  ! S3 upload failed for ${ref}: ${e.message}`);
      }
      break; // got it
    } catch (e) {
      skipped.push(`instance ${instanceId} type=${type} (${e.message})`);
    }
  }
  return { saved, skipped };
}

/**
 * Fetch f3mf bytes with auth headers. The endpoint either streams the 3mf or
 * returns JSON with a presigned URL (short TTL) that we then fetch. Returns a
 * Buffer, or null when auth is rejected / no file.
 */
async function fetchFile(url, auth) {
  const res = await fetch(url, {
    headers: { 'User-Agent': UA, Referer: 'https://makerworld.com/', Accept: '*/*', ...auth.headers },
  });
  const ct = res.headers.get('content-type') || '';
  if (ct.includes('application/json')) {
    const j = await res.json().catch(() => ({}));
    if (j.error) return null; // "Please log in to download models."
    const presigned = j.url || j.downloadUrl || j.data?.url;
    if (!presigned) return null;
    const dl = await fetch(presigned, { headers: { 'User-Agent': UA } });
    return dl.ok ? Buffer.from(await dl.arrayBuffer()) : null;
  }
  if (!res.ok) return null;
  return Buffer.from(await res.arrayBuffer());
}

const slug = (s) => (s || '').replace(/[^\w\-]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 60);

// --- CLI -----------------------------------------------------------------
// Usage: node download.mjs <modelId> [<modelId> ...]
if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  const ids = process.argv.slice(2).filter((a) => /^\d+$/.test(a));
  (async () => {
    if (!ids.length) {
      console.error('Usage: node download.mjs <modelId> [...]  (needs token.txt or MW_TOKEN)');
      process.exit(1);
    }
    const auth = await loadAuth();
    console.error(`Auth loaded from ${auth.source}.`);
    let ok = 0;
    for (const id of ids) {
      console.error(`\n# model ${id}`);
      const { saved, skipped } = await downloadModel({ id, auth });
      ok += saved.length;
      console.error(`  -> saved ${saved.length}, skipped ${skipped.length}`);
      skipped.forEach((s) => console.error(`     skip: ${s}`));
    }
    console.error(`\nDone. ${ok} file(s) saved.`);
    if (!ok) process.exitCode = 2;
  })().catch((e) => {
    console.error('Download failed:', e.message);
    process.exit(1);
  });
}
