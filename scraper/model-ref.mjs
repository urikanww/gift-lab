/**
 * The ONE canonical storage ref for a model file, shared by the S3 upload
 * (download.mjs) and the CSV `model_file_ref` (export.mjs). It MUST match the
 * backend exactly:
 *   - Laravel Model3dFileStore + AssetStore store at  models3d/{source}-{id}.{ext}
 *   - the private `spaces_models` disk is rooted at   {DO_STORAGE_FOLDER} (GIFT_LAB)
 *     so this ref resolves to  GIFT_LAB/models3d/{source}-{id}.{ext}
 *   - the CSV importer's model_file_ref regex only accepts a FLAT ref
 *     (^models3d/[\w.\- ]+\.(3mf|stl|obj)$) - no sub-slashes.
 *
 * If any of the three (upload key / CSV ref / backend) drifts from this, the
 * uploaded object and the ref the app looks up stop matching and models 404.
 * path.test.mjs + the PHP AssetStore test pin this literal on both sides.
 *
 * @param {string|number} id
 * @param {{source?: string, ext?: string}} [opts]
 * @returns {string} e.g. "models3d/makerworld-3018896.3mf"
 */
export function modelRef(id, { source = 'makerworld', ext = '3mf' } = {}) {
  const s = String(source).toLowerCase().replace(/[^a-z0-9._-]+/g, '-');
  const i = String(id).toLowerCase().replace(/[^a-z0-9._-]+/g, '-');
  return `models3d/${s}-${i}.${ext.replace(/^\.+/, '')}`;
}
