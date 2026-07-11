/**
 * Anonymous per-model enrichment via the Bambu design-service API.
 *
 * `https://api.bambulab.com/v1/design-service/design/{id}` is NOT behind
 * Cloudflare and answers anonymous requests with the full model record:
 * title, thumbnails, tags, license, and an `instances[]` list that names the
 * printable files (.3mf / .stl / .scad) and their `profileId` (needed later
 * for authenticated download).
 *
 * It does NOT return downloadable file URLs — those require login (see
 * download.mjs).
 */

const DETAIL_API = 'https://api.bambulab.com/v1/design-service/design';

/**
 * Fetch and normalize the rich record for one model id.
 * @param {string|number} id
 * @returns {Promise<object|null>}
 */
export async function enrichModel(id) {
  const res = await fetch(`${DETAIL_API}/${id}`, {
    headers: {
      'User-Agent':
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 ' +
        '(KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
      Accept: 'application/json',
    },
  });
  if (!res.ok) return null;
  const d = await res.json();

  const instances = Array.isArray(d.instances) ? d.instances : [];
  const inst = instances[0] || {};
  const fil = (Array.isArray(inst.instanceFilaments) ? inst.instanceFilaments : [])[0] || {};

  // Thumbnails: main cover + every instance cover/picture.
  const thumbnails = unique(
    [
      d.coverUrl,
      d.coverLandscape,
      d.coverPortrait,
      ...instances.flatMap((i) => [
        i.cover,
        ...(Array.isArray(i.pictures) ? i.pictures.map((p) => p?.url || p) : []),
      ]),
    ]
      .filter(Boolean)
      .map(stripOss),
  );

  // Printable files: names + type, harvested from the summary/model listing.
  const files = extractFiles(d, instances);

  return {
    id: String(d.id ?? id),
    title: d.title || d.titleTranslated || '',
    url: `https://makerworld.com/en/models/${d.id ?? id}`,
    creator:
      d.designCreator?.name ||
      d.designCreator?.handle ||
      d.creator?.name ||
      '',
    thumbnails,
    files, // [{ name, type, profileId }]
    license: d.license?.name || d.license || '',
    tags: d.tags || [],
    categories: (d.categories || []).map((c) => c?.name || c).filter(Boolean),
    downloadCount: d.downloadCount ?? 0,
    printCount: d.printCount ?? 0,
    likeCount: d.likeCount ?? 0,
    // Download-gating flags — decide up front whether bytes are reachable.
    isPrintable: d.isPrintable ?? null,
    isPointRedeemable: d.isPointRedeemable ?? false,
    isExclusive: d.isExclusive ?? false,
    isPaid: d.paidSetting?.isPaid ?? false,
    // Needed by the authenticated downloader:
    defaultInstanceId: d.defaultInstanceId ?? instances[0]?.id ?? null,
    profileIds: instances.map((i) => i.profileId).filter(Boolean),
    // Bambu printer codes the sliced 3mf targets (C12=P1S, N7=P2S, O1D=H2D ...).
    // The f3mf endpoint wants one via ?devModelName=. First is a safe default.
    devModelNames: extractDevModels(d),
    // Print/cost inputs (from the default instance) for product base_cost/weight.
    weightGrams: num(inst.weight ?? fil.usedG),
    estPrintMinutes: inst.prediction ? Math.round(inst.prediction / 60) : 0,
    filamentMaterial: fil.type || '',
    filamentColor: fil.color || '',
    needAms: !!inst.needAms,
    bambuCategory: (d.categories || []).map((c) => c?.name || c).filter(Boolean)[0] || '',
  };
}

function num(v) {
  const n = Number(v);
  return Number.isFinite(n) ? n : 0;
}

/** Collect the distinct devModelName codes referenced anywhere in the record. */
function extractDevModels(d) {
  const s = JSON.stringify(d);
  return [...new Set([...s.matchAll(/"devModelName":"([^"]+)"/g)].map((m) => m[1]))];
}

/** Pull file name/type entries out of the model record (shape varies). */
function extractFiles(d, instances) {
  const out = [];
  const seen = new Set();
  const walk = (node, profileId) => {
    if (!node || typeof node !== 'object') return;
    const name = node.modelName || node.fileName || node.modelFileName;
    const type = node.modelType;
    if (name && !seen.has(name)) {
      seen.add(name);
      out.push({ name, type: type || guessType(name), profileId: profileId ?? null });
    }
    for (const v of Object.values(node)) {
      if (Array.isArray(v)) v.forEach((x) => walk(x, profileId));
      else if (v && typeof v === 'object') walk(v, profileId);
    }
  };
  instances.forEach((i) => walk(i, i.profileId));
  walk(d, d.defaultInstanceId);
  return out;
}

function guessType(name) {
  const m = /\.([a-z0-9]+)$/i.exec(name || '');
  return m ? m[1].toLowerCase() : '';
}

/** Drop MakerWorld's OSS resize query so thumbnails come back full-size. */
function stripOss(u) {
  return typeof u === 'string' ? u.replace(/\?x-oss-process=.*$/, '') : u;
}

function unique(arr) {
  return [...new Set(arr)];
}
