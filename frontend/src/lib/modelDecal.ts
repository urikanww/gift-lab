import * as THREE from 'three';
import { DecalGeometry } from 'three/examples/jsm/geometries/DecalGeometry.js';
import { zoneBasis, type PrintZone } from './printZone';

/**
 * Build a decal projected onto `mesh` over the given print zone. Orientation is
 * derived from the zone basis; the decal size is the zone's mm footprint and the
 * projection depth spans the local geometry so it wraps a curved surface.
 * Returns null if the projection produced no geometry (zone off the mesh).
 */
export function buildDecalGeometry(mesh: THREE.Mesh, zone: PrintZone): THREE.BufferGeometry | null {
  const { n, u, v } = zoneBasis(zone);
  const position = new THREE.Vector3(...zone.center);

  // Orientation matrix (u, v, n) -> Euler for DecalGeometry.
  const basis = new THREE.Matrix4().makeBasis(u, v, n);
  const orientation = new THREE.Euler().setFromRotationMatrix(basis);

  // Projector box: zone footprint in-plane, generous depth through the surface.
  const depth = Math.max(zone.width_mm, zone.height_mm);
  const size = new THREE.Vector3(zone.width_mm, zone.height_mm, depth);

  mesh.updateMatrixWorld(true);
  const geo = new DecalGeometry(mesh, position, orientation, size);
  return geo.getAttribute('position').count > 0 ? geo : null;
}

/**
 * Render the decal region flattened to its own UV space as the production print
 * file: a transparent PNG `printPx` wide, artwork exactly as placed. This is the
 * file the UV printer/jig consumes (flat zone -> identity mapping; wrapped zone
 * -> the decal's UV unwrap). Requires a document/WebGL context (browser only).
 */
export function renderPrintFile(
  decal: THREE.BufferGeometry,
  artwork: THREE.Texture,
  printPx: number,
  aspect: number,
): string {
  const scene = new THREE.Scene();
  const material = new THREE.MeshBasicMaterial({ map: artwork, transparent: true });

  // Remap the decal's UVs onto a flat quad in clip space so an orthographic
  // camera sees the artwork undistorted at print resolution.
  const uv = decal.getAttribute('uv');
  const flat = new THREE.BufferGeometry();
  const positions: number[] = [];
  for (let i = 0; i < uv.count; i++) {
    positions.push(uv.getX(i) * 2 - 1, uv.getY(i) * 2 - 1, 0);
  }
  flat.setAttribute('position', new THREE.Float32BufferAttribute(positions, 3));
  flat.setAttribute('uv', uv.clone());
  scene.add(new THREE.Mesh(flat, material));

  const camera = new THREE.OrthographicCamera(-1, 1, 1, -1, 0.1, 10);
  camera.position.set(0, 0, 1);

  const heightPx = Math.round(printPx / aspect);
  const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true, preserveDrawingBuffer: true });
  try {
    renderer.setSize(printPx, heightPx);
    renderer.render(scene, camera);
    return renderer.domElement.toDataURL('image/png');
  } finally {
    material.dispose();
    flat.dispose();
    renderer.dispose();
    renderer.forceContextLoss();
  }
}
