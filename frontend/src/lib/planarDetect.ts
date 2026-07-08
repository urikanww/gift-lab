import * as THREE from 'three';
import type { PrintZone } from './printZone';

/**
 * Detect the largest flat printable face of a mesh. Groups triangles by
 * quantized face normal, sums per-group area, and returns the dominant group's
 * oriented bounds as a PrintZone (model mm).
 *
 * Returns null when the flattest group covers too little of the surface (a
 * genuinely curved part), so callers fall back to an admin-placed zone rather
 * than inventing a face that doesn't exist.
 */
export function detectPrintZone(
  geometry: THREE.BufferGeometry,
  opts: { minAreaFraction?: number; normalBins?: number } = {},
): PrintZone | null {
  // A 60x40x4 plaque's single largest flat face covers ~0.43 of its total
  // surface area (measured, both axis-aligned and rotated); a 16x12-segment
  // sphere's largest quantized-normal bin covers ~0.008 (measured) since no
  // single triangle normal repeats often enough to dominate. 0.3 sits well
  // inside that gap, so it accepts genuinely flat parts while rejecting
  // curved ones. See planarDetect.test.ts for the geometries this was tuned
  // against.
  const minAreaFraction = opts.minAreaFraction ?? 0.3;
  const bins = opts.normalBins ?? 12;

  const geo = geometry.index ? geometry.toNonIndexed() : geometry;
  const pos = geo.getAttribute('position');
  const triCount = pos.count / 3;
  if (triCount < 1) return null;

  const a = new THREE.Vector3();
  const b = new THREE.Vector3();
  const c = new THREE.Vector3();
  const ab = new THREE.Vector3();
  const ac = new THREE.Vector3();
  const faceNormal = new THREE.Vector3();

  type Group = { normal: THREE.Vector3; area: number; centroid: THREE.Vector3; tris: number[] };
  const groups = new Map<string, Group>();
  let totalArea = 0;

  const key = (n: THREE.Vector3): string =>
    `${Math.round(n.x * bins)}|${Math.round(n.y * bins)}|${Math.round(n.z * bins)}`;

  for (let t = 0; t < triCount; t++) {
    a.fromBufferAttribute(pos, t * 3);
    b.fromBufferAttribute(pos, t * 3 + 1);
    c.fromBufferAttribute(pos, t * 3 + 2);
    ab.subVectors(b, a);
    ac.subVectors(c, a);
    faceNormal.crossVectors(ab, ac);
    const area = faceNormal.length() * 0.5;
    if (area < 1e-9) continue;
    faceNormal.normalize();
    totalArea += area;

    const k = key(faceNormal);
    let g = groups.get(k);
    if (!g) {
      g = { normal: faceNormal.clone(), area: 0, centroid: new THREE.Vector3(), tris: [] };
      groups.set(k, g);
    }
    g.area += area;
    g.centroid.add(a.clone().add(b).add(c).multiplyScalar(area / 3));
    g.tris.push(t);
  }

  if (totalArea <= 0) return null;

  let best: Group | null = null;
  for (const g of groups.values()) {
    if (!best || g.area > best.area) best = g;
  }
  if (!best || best.area / totalArea < minAreaFraction) return null;

  best.centroid.multiplyScalar(1 / best.area);

  const n = best.normal.clone().normalize();
  let up = Math.abs(n.y) < 0.9 ? new THREE.Vector3(0, 1, 0) : new THREE.Vector3(1, 0, 0);
  up = up.sub(n.clone().multiplyScalar(up.dot(n))).normalize();
  const right = new THREE.Vector3().crossVectors(up, n).normalize();

  let minU = Infinity, maxU = -Infinity, minV = Infinity, maxV = -Infinity;
  const p = new THREE.Vector3();
  for (const t of best.tris) {
    for (let i = 0; i < 3; i++) {
      p.fromBufferAttribute(pos, t * 3 + i).sub(best.centroid);
      const du = p.dot(right);
      const dv = p.dot(up);
      if (du < minU) minU = du;
      if (du > maxU) maxU = du;
      if (dv < minV) minV = dv;
      if (dv > maxV) maxV = dv;
    }
  }

  const width = maxU - minU;
  const height = maxV - minV;
  if (width < 1e-6 || height < 1e-6) return null;

  const center = best.centroid.clone()
    .add(right.clone().multiplyScalar((minU + maxU) / 2))
    .add(up.clone().multiplyScalar((minV + maxV) / 2));

  return {
    normal: [n.x, n.y, n.z],
    center: [center.x, center.y, center.z],
    up: [up.x, up.y, up.z],
    width_mm: width,
    height_mm: height,
  };
}
