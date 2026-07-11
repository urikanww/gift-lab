/**
 * Tokenless MakerWorld model listing via the public search API.
 *
 *   https://makerworld.com/api/v1/search-service/select/design2
 *     ?categories=&orderBy=hotScore&entrance=list&designType=0&limit=20&offset=0
 *
 * This endpoint answers anonymous requests (no Cloudflare, no token) and each
 * hit already carries title, thumbnails, tags, license, gating flags, and a
 * `designExtension.model_files[]` list naming the printable .3mf/.stl files and
 * their sizes. It does NOT carry the instance id or a download URL — those come
 * from the detail API (see enrich.mjs) and the login-gated f3mf endpoint
 * (see download.mjs).
 *
 * Replaces the old Playwright listing path entirely: plain HTTP, much faster.
 */

const SEARCH_API = 'https://makerworld.com/api/v1/search-service/select/design2';
const PAGE = 20; // server caps page size around here; paginate with offset.

const UA =
  'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 ' +
  '(KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

/**
 * List models from MakerWorld.
 *
 * @param {object} [opts]
 * @param {number} [opts.limit=60]          How many models to return.
 * @param {number} [opts.offset=0]          Skip this many models first (resume a
 *                                          prior run: 0-50 then --offset 50).
 * @param {string} [opts.orderBy='hotScore'] hotScore | newest | mostDownload | ...
 * @param {string} [opts.category='']         Category filter (empty = all).
 * @param {number} [opts.designType=0]        0 = models.
 * @param {(m:string)=>void} [opts.log=console.error]
 * @returns {Promise<Array<object>>} Normalized model records.
 */
export async function listModels(opts = {}) {
  const {
    limit = 60,
    offset: startOffset = 0,
    orderBy = 'hotScore',
    category = '',
    designType = 0,
    delayMs = 0, // wait between page fetches (human-like pacing); 0 = none
    log = (m) => console.error(m),
  } = opts;

  // Snap the start offset down to a page boundary so the API's limit/offset
  // paging stays aligned; trim any overshoot from the first page afterward.
  const base = Math.max(0, Math.floor(startOffset / PAGE) * PAGE);
  const drop = Math.max(0, startOffset - base); // rows to discard off page 1

  const out = [];
  for (let offset = base; out.length < limit + drop; offset += PAGE) {
    if (offset > 0 && delayMs > 0) {
      await new Promise((r) => setTimeout(r, delayMs));
    }
    const url =
      `${SEARCH_API}?categories=${encodeURIComponent(category)}` +
      `&orderBy=${encodeURIComponent(orderBy)}&entrance=list` +
      `&designType=${designType}&limit=${PAGE}&offset=${offset}`;

    const res = await fetch(url, { headers: { 'User-Agent': UA, Accept: 'application/json' } });
    if (!res.ok) throw new Error(`list API HTTP ${res.status} at offset ${offset}`);
    const json = await res.json();
    const hits = Array.isArray(json.hits) ? json.hits : [];
    if (!hits.length) {
      log(`no more results at offset ${offset} (total=${json.total ?? '?'})`);
      break;
    }
    out.push(...hits.map(normalize));
    log(`fetched ${out.length}${json.total ? '/' + json.total : ''} models`);
  }
  return out.slice(drop, drop + limit);
}

/** Map a raw search hit to our normalized shape. */
function normalize(h = {}) {
  const ext = h.designExtension || {};
  const files = (ext.model_files || []).map((f) => ({
    name: f.modelName || f.thumbnailName || '',
    type: f.modelType || guessType(f.modelName),
    size: f.modelSize || 0,
  }));
  const thumbnails = unique(
    [
      h.cover,
      h.coverPortrait,
      h.coverLandscape,
      ...(ext.design_pictures || []).map((p) => p?.url),
    ]
      .filter(Boolean)
      .map(stripOss),
  );

  return {
    id: String(h.id ?? ''),
    title: h.title || h.titleTranslated || '',
    url: `https://makerworld.com/en/models/${h.id}`,
    creator: h.designCreator?.name || h.designCreator?.handle || '',
    thumbnails,
    files, // [{ name, type, size }] — .3mf/.stl names, no URL (login-gated)
    tags: h.tags || [],
    license: h.license || '',
    likeCount: h.likeCount ?? 0,
    downloadCount: h.downloadCount ?? 0,
    printCount: h.printCount ?? 0,
    collectionCount: h.collectionCount ?? 0,
    boostCnt: h.boostCnt ?? 0,
    // Download-gating flags (note: list API uses snake_case for some):
    isPrintable: h.is_printable ?? null,
    isPointRedeemable: h.is_point_redeemable ?? false,
    isExclusive: h.isExclusive ?? false, // platform-exclusive BADGE, not a paywall
    isOfficial: h.is_official ?? false,
    // `free` = directly downloadable once logged in. Only points/paid actually
    // gate the bytes; isExclusive does NOT (it's just a MakerWorld badge).
    free: !h.is_point_redeemable,
  };
}

function guessType(name) {
  const m = /\.([a-z0-9]+)$/i.exec(name || '');
  return m ? m[1].toLowerCase() : '';
}
function stripOss(u) {
  return typeof u === 'string' ? u.replace(/\?x-oss-process=.*$/, '') : u;
}
function unique(a) {
  return [...new Set(a)];
}

// --- CLI -----------------------------------------------------------------
// Usage: node list.mjs [limit] [--offset N] [--order hotScore|newest|...] [--free] [--out f.json]
if (process.argv[1] && (await import('node:url')).pathToFileURL(process.argv[1]).href === import.meta.url) {
  const { writeFile } = await import('node:fs/promises');
  const args = process.argv.slice(2);
  const limit = Number(args.find((a) => /^\d+$/.test(a))) || 60;
  const oi = args.indexOf('--order');
  const orderBy = oi !== -1 ? args[oi + 1] : 'hotScore';
  const offIdx = args.indexOf('--offset');
  const offset = offIdx !== -1 ? Number(args[offIdx + 1]) || 0 : 0;
  const freeOnly = args.includes('--free');
  const outIdx = args.indexOf('--out');
  const outFile = outIdx !== -1 ? args[outIdx + 1] : null;

  let models = await listModels({ limit: freeOnly ? limit * 3 : limit, offset, orderBy });
  if (freeOnly) models = models.filter((m) => m.free).slice(0, limit);

  const json = JSON.stringify(models, null, 2);
  if (outFile) {
    await writeFile(outFile, json);
    console.error(`Wrote ${models.length} models -> ${outFile}`);
  } else {
    console.log(json);
  }
}
