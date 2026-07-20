import api, { ensureCsrf } from './api';

/**
 * Outcome of a preview-URL exchange.
 *
 * Deliberately NOT just `string | null`: collapsing failure into null is what
 * let a rate-limited preview render as nothing at all, with no error and no
 * placeholder, for as long as it did. Callers must be able to tell "this line
 * has no artwork" (nothing to show - correct) from "this line HAS artwork we
 * could not load" (say so).
 */
export type ArtworkPreviewResult = { ok: true; url: string } | { ok: false };

/**
 * Re-issue a short-lived preview URL for a stored artwork ref so a saved
 * customization can be shown (e.g. in the cart or on the order detail page).
 */
export async function fetchArtworkPreview(ref: string): Promise<ArtworkPreviewResult> {
  try {
    const { data } = await api.get<{ url: string }>('/uploads/artwork/preview', { params: { ref } });
    return data.url ? { ok: true, url: data.url } : { ok: false };
  } catch {
    return { ok: false };
  }
}

/**
 * Convenience wrapper for callers that genuinely have nothing useful to show on
 * failure and only need the URL or nothing.
 */
export async function fetchArtworkPreviewUrl(ref: string): Promise<string | null> {
  const result = await fetchArtworkPreview(ref);
  return result.ok ? result.url : null;
}

function dataUrlToBlob(dataUrl: string): Blob {
  const [header, base64] = dataUrl.split(',');
  const mime = header.match(/:(.*?);/)?.[1] ?? 'image/png';
  const binary = atob(base64);
  const bytes = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i += 1) bytes[i] = binary.charCodeAt(i);
  return new Blob([bytes], { type: mime });
}

/**
 * Upload the designer's captured artwork to the backend and return the stored
 * ref (object-store key). This ref becomes the line's customization artwork_ref
 * and, once a proof is approved, the production print file.
 */
export async function uploadArtwork(dataUrl: string): Promise<string> {
  await ensureCsrf();
  const form = new FormData();
  form.append('artwork', dataUrlToBlob(dataUrl), 'artwork.png');
  const { data } = await api.post<{ ref: string; url: string }>('/uploads/artwork', form, {
    headers: { 'Content-Type': 'multipart/form-data' },
  });
  return data.ref;
}

/**
 * Upload a raw File (reference image for the "upload finished look" fallback,
 * or a logo file) to the same private artwork store and return its ref. Reuses
 * POST /uploads/artwork, which accepts png/jpg/jpeg/webp up to 10 MB.
 */
export async function uploadArtworkFile(file: File): Promise<string> {
  await ensureCsrf();
  const form = new FormData();
  form.append('artwork', file, file.name || 'reference');
  const { data } = await api.post<{ ref: string; url: string }>('/uploads/artwork', form, {
    headers: { 'Content-Type': 'multipart/form-data' },
  });
  return data.ref;
}
