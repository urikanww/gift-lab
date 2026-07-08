import * as THREE from 'three';
import { STLLoader } from 'three/examples/jsm/loaders/STLLoader.js';
import api from './api';
import { detectPrintZone } from './planarDetect';
import { zoneBasis } from './printZone';

/**
 * Offscreen render of a MODEL_3D product's decoration face, used as the
 * designer backdrop (audit G1/G2/G3): the buyer places their logo on a clean
 * orthographic render of the ACTUAL model - in the chosen filament colour -
 * instead of the scraped marketing photo. The orthographic frustum is known in
 * model units (STL convention: mm), so canvas placement maps to physical mm.
 */

export interface ModelFaceSnapshot {
  /** PNG data URL of the face render (transparent background). */
  dataUrl: string;
  /** Physical size of the FULL canvas footprint in model mm. */
  canvasWidthMm: number;
  canvasHeightMm: number;
  /** Physical size of the decoration face itself in model mm. */
  faceWidthMm: number;
  faceHeightMm: number;
}

const FILAMENT_HEX: Record<string, number> = {
  Black: 0x2e2e2e,
  White: 0xf1f1ee,
  Grey: 0x9c9c9c,
};

// Cache renders per product+colour+version+size - a colour toggle back and
// forth should not re-download or re-render the STL, but a replaced model
// (new version token) must never serve a stale render.
const cache = new Map<string, Promise<ModelFaceSnapshot>>();

export function cacheKeyFor(
  productKey: string,
  filamentColor: string,
  version: string,
  widthPx: number,
  heightPx: number,
): string {
  return `${productKey}|${filamentColor}|${version}|${widthPx}x${heightPx}`;
}

export function renderModelFace(
  productKey: string,
  filamentColor: string,
  version = '',
  widthPx = 1000,
  heightPx = 760,
): Promise<ModelFaceSnapshot> {
  const cacheKey = cacheKeyFor(productKey, filamentColor, version, widthPx, heightPx);
  const hit = cache.get(cacheKey);
  if (hit) return hit;

  const promise = renderFresh(productKey, filamentColor, widthPx, heightPx).catch((err) => {
    // Don't cache failures - a transient network error should retry.
    cache.delete(cacheKey);
    throw err;
  });
  cache.set(cacheKey, promise);
  return promise;
}

async function renderFresh(
  productKey: string,
  filamentColor: string,
  widthPx: number,
  heightPx: number,
): Promise<ModelFaceSnapshot> {
  const geometry = await new STLLoader().loadAsync(
    `${api.defaults.baseURL}/catalogue/${productKey}/model`,
  );

  geometry.center();
  geometry.computeVertexNormals();
  geometry.computeBoundingBox();
  const size = new THREE.Vector3();
  geometry.boundingBox!.getSize(size);

  const mesh = new THREE.Mesh(
    geometry,
    new THREE.MeshStandardMaterial({
      color: FILAMENT_HEX[filamentColor] ?? FILAMENT_HEX.Grey,
      roughness: 0.55,
      metalness: 0.1,
    }),
  );

  const detected = detectPrintZone(geometry);

  let faceWidthMm: number;
  let faceHeightMm: number;
  if (detected) {
    // Orient the detected face normal onto +Z for the orthographic render.
    const { n } = zoneBasis(detected);
    const q = new THREE.Quaternion().setFromUnitVectors(n, new THREE.Vector3(0, 0, 1));
    mesh.applyQuaternion(q);
    faceWidthMm = detected.width_mm;
    faceHeightMm = detected.height_mm;
  } else {
    // No flat face detected: keep the legacy smallest-extent framing so the
    // customer still gets a neutral render (the designer will require an admin
    // zone for such parts).
    const extents = [size.x, size.y, size.z];
    const minAxis = extents.indexOf(Math.min(...extents));
    if (minAxis === 0) mesh.rotation.y = Math.PI / 2;
    if (minAxis === 1) mesh.rotation.x = -Math.PI / 2;
    faceWidthMm = minAxis === 0 ? size.z : size.x;
    faceHeightMm = minAxis === 1 ? size.z : size.y;
  }

  const scene = new THREE.Scene();
  scene.add(new THREE.AmbientLight(0xffffff, 0.85));
  const keyLight = new THREE.DirectionalLight(0xffffff, 1.1);
  keyLight.position.set(1, 2, 3);
  scene.add(keyLight);
  const fill = new THREE.DirectionalLight(0xffffff, 0.35);
  fill.position.set(-2, -1, 2);
  scene.add(fill);
  scene.add(mesh);

  // Orthographic frustum sized in model mm, expanded to the canvas aspect so
  // the render is undistorted and the mm-per-pixel mapping is exact.
  const margin = 1.15;
  const aspect = widthPx / heightPx;
  let viewW = faceWidthMm * margin;
  let viewH = faceHeightMm * margin;
  if (viewW / viewH > aspect) viewH = viewW / aspect;
  else viewW = viewH * aspect;

  const depth = Math.max(size.x, size.y, size.z) * 4;
  const camera = new THREE.OrthographicCamera(-viewW / 2, viewW / 2, viewH / 2, -viewH / 2, 0.1, depth);
  camera.position.set(0, 0, depth / 2);
  camera.lookAt(0, 0, 0);

  const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true, preserveDrawingBuffer: true });
  try {
    renderer.setSize(widthPx, heightPx);
    renderer.render(scene, camera);
    const dataUrl = renderer.domElement.toDataURL('image/png');
    return {
      dataUrl,
      canvasWidthMm: viewW,
      canvasHeightMm: viewH,
      faceWidthMm,
      faceHeightMm,
    };
  } finally {
    geometry.dispose();
    mesh.material.dispose();
    renderer.dispose();
    renderer.forceContextLoss();
  }
}
