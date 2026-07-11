import { test } from 'node:test';
import assert from 'node:assert/strict';
import { modelRef } from './model-ref.mjs';

// Pins the ONE model-file path convention shared across:
//   - the S3 upload key (download.mjs / export.mjs)
//   - the CSV model_file_ref (export.mjs)
//   - the Laravel backend (Model3dFileStore / AssetStore, spaces_models rooted
//     at DO_STORAGE_FOLDER=GIFT_LAB)
// The PHP AssetStoreTest asserts the SAME literal on the backend side, so the
// two languages can't silently drift apart.

test('modelRef is the canonical flat backend ref', () => {
  assert.equal(modelRef('3018896'), 'models3d/makerworld-3018896.3mf');
  assert.equal(modelRef(3018896, { source: 'thingiverse', ext: 'stl' }), 'models3d/thingiverse-3018896.stl');
});

test('S3 upload key = DO_STORAGE_FOLDER + ref = the key spaces_models (root GIFT_LAB) resolves', () => {
  const folder = 'GIFT_LAB'; // DO_STORAGE_FOLDER, = the spaces_models disk root
  assert.equal(`${folder}/${modelRef('3018896')}`, 'GIFT_LAB/models3d/makerworld-3018896.3mf');
});

test('ref passes the backend CSV importer model_file_ref regex (no sub-slashes)', () => {
  const re = /^models3d\/[\w.\- ]+\.(3mf|stl|obj)$/;
  assert.match(modelRef('3018896'), re);
  assert.match(modelRef('3018896', { ext: 'stl' }), re);
});
