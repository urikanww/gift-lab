import api from './api';

/**
 * Fetch a model file (STL/GLB/…) as an ArrayBuffer through the authed axios
 * instance (so the Sanctum cookie rides along) WITH determinate download
 * progress. The viewers already fetched via `api.get(url, { responseType:
 * 'arraybuffer' })`; this adds `onDownloadProgress` so a real percentage can be
 * shown instead of an indeterminate spinner.
 *
 * `total` comes from `progressEvent.total` (populated from Content-Length). It
 * is null when the server didn't send a length - the caller should then fall
 * back to an indeterminate state rather than divide by an unknown total.
 */
export async function loadModelWithProgress(
  url: string,
  onProgress?: (loaded: number, total: number | null) => void,
): Promise<ArrayBuffer> {
  const res = await api.get(url, {
    responseType: 'arraybuffer',
    onDownloadProgress: (evt) => {
      const total = typeof evt.total === 'number' && evt.total > 0 ? evt.total : null;
      onProgress?.(evt.loaded ?? 0, total);
    },
  });
  return res.data as ArrayBuffer;
}
