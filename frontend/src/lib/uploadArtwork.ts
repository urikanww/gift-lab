import api, { ensureCsrf } from './api';

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
