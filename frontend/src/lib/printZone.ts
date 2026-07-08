import * as THREE from 'three';

/** Model-space print zone: where + how big the decoration surface is (mm). */
export interface PrintZone {
  normal: [number, number, number];
  center: [number, number, number];
  up: [number, number, number];
  width_mm: number;
  height_mm: number;
}

/**
 * Orthonormal basis for a zone: n (outward normal), u (surface "right"),
 * v (surface "up"). Used to orient the decal/camera and to map mm to surface.
 */
export function zoneBasis(zone: PrintZone): { n: THREE.Vector3; u: THREE.Vector3; v: THREE.Vector3 } {
  const n = new THREE.Vector3(...zone.normal).normalize();
  let up = new THREE.Vector3(...zone.up);
  // Re-orthogonalise up against n (admin input may be slightly off-plane).
  up = up.sub(n.clone().multiplyScalar(up.dot(n)));
  if (up.lengthSq() < 1e-6) {
    // Degenerate up: pick any vector not parallel to n.
    up = Math.abs(n.x) < 0.9 ? new THREE.Vector3(1, 0, 0) : new THREE.Vector3(0, 1, 0);
    up = up.sub(n.clone().multiplyScalar(up.dot(n)));
  }
  const v = up.normalize();
  const u = new THREE.Vector3().crossVectors(v, n).normalize();
  return { n, u, v };
}
