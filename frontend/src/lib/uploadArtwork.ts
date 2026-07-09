import api, { ensureCsrf } from './api';

/**
 * Re-issue a short-lived preview URL for a stored artwork ref so a saved
 * customization can be shown (e.g. in the cart). Returns null on failure.
 */
export async function fetchArtworkPreviewUrl(ref: string): Promise<string | null> {
  try {
    const { data } = await api.get<{ url: string }>('/uploads/artwork/preview', { params: { ref } });
    return data.url ?? null;
  } catch {
    return null;
  }
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
