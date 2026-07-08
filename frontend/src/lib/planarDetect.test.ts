import { describe, expect, it } from 'vitest';
import * as THREE from 'three';
import { detectPrintZone } from './planarDetect';

// A flat plaque: 60 (x) x 40 (y) x 4 (z) box. Largest flat faces are the two
// z-facing 60x40 planes; the detector should return one of them.
function boxGeometry(w: number, h: number, d: number): THREE.BufferGeometry {
  return new THREE.BoxGeometry(w, h, d).toNonIndexed();
}

describe('detectPrintZone', () => {
  it('finds the largest flat face of an axis-aligned plaque', () => {
    const zone = detectPrintZone(boxGeometry(60, 40, 4));
    expect(zone).not.toBeNull();
    const [nx, ny, nz] = zone!.normal;
    expect(Math.abs(nz)).toBeGreaterThan(0.9);
    expect(Math.abs(nx)).toBeLessThan(0.1);
    expect(Math.abs(ny)).toBeLessThan(0.1);
    const dims = [zone!.width_mm, zone!.height_mm].sort((a, b) => a - b);
    expect(dims[0]).toBeCloseTo(40, 0);
    expect(dims[1]).toBeCloseTo(60, 0);
  });

  it('detects a non-axis-aligned flat face', () => {
    const geo = boxGeometry(60, 40, 4);
    geo.rotateY(Math.PI / 5);
    const zone = detectPrintZone(geo);
    expect(zone).not.toBeNull();
    const dims = [zone!.width_mm, zone!.height_mm].sort((a, b) => a - b);
    expect(dims[0]).toBeCloseTo(40, 0);
    expect(dims[1]).toBeCloseTo(60, 0);
  });

  it('returns null for a fully curved part with no flat region', () => {
    const zone = detectPrintZone(new THREE.SphereGeometry(20, 16, 12).toNonIndexed());
    expect(zone).toBeNull();
  });
});
