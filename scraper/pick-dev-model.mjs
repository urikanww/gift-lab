/**
 * Choose which printer profile (devModelName) to download a MakerWorld .3mf for.
 *
 * Target printer: Bambu Lab H2S = MakerWorld device code `O1S`. A .3mf fetched
 * with devModelName=O1S is sliced/print-ready for the H2S, so we PREFER it
 * whenever the model lists it. Otherwise we fall back to the first advertised
 * profile and flag the model as needing a re-slice against the H2S profile.
 *
 * @param {{devModelNames?: string[]}} meta
 * @returns {{ devModelName: string, isH2s: boolean }}
 */
export function pickDevModelName(meta) {
  const names = Array.isArray(meta?.devModelNames) ? meta.devModelNames : [];
  const h2s = names.find((n) => String(n).toUpperCase() === 'O1S');
  if (h2s) {
    return { devModelName: h2s, isH2s: true };
  }

  return { devModelName: names[0] || '', isH2s: false };
}

/** The MakerWorld device code for the Bambu Lab H2S. */
export const H2S_DEV_MODEL = 'O1S';
