import { expect, it } from 'vitest';
import * as THREE from 'three';
import { buildDecalGeometry } from './modelDecal';
import type { PrintZone } from './printZone';

// Note: renderPrintFile is not unit-tested here; it requires a WebGL context
// (browser only) and is exercised in manual preview instead.

it('builds decal geometry covering the zone on a flat mesh', () => {
  const mesh = new THREE.Mesh(new THREE.BoxGeometry(60, 40, 4));
  const zone: PrintZone = {
    normal: [0, 0, 1],
    center: [0, 0, 2],
    up: [0, 1, 0],
    width_mm: 40,
    height_mm: 30,
  };
  const geo = buildDecalGeometry(mesh, zone);
  expect(geo).not.toBeNull();
  expect(geo!.getAttribute('position').count).toBeGreaterThan(0);
});
