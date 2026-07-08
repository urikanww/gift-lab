import { describe, it, expect } from 'vitest';
import * as THREE from 'three';
import { worldToZoneFraction, zoneFractionToCanvas, canvasToZoneFraction } from './zoneMapping';
import type { PrintZone } from './printZone';

const zone: PrintZone = {
  normal: [0, 0, 1],
  center: [0, 0, 0],
  up: [0, 1, 0],
  width_mm: 100,
  height_mm: 60,
};

describe('zoneMapping', () => {
  it('maps the zone centre to fraction (0.5, 0.5)', () => {
    const f = worldToZoneFraction(new THREE.Vector3(0, 0, 0), zone);
    expect(f.fu).toBeCloseTo(0.5, 5);
    expect(f.fv).toBeCloseTo(0.5, 5);
  });

  it('maps the +u,+v corner to (1, 1)', () => {
    const f = worldToZoneFraction(new THREE.Vector3(50, 30, 0), zone);
    expect(f.fu).toBeCloseTo(1, 5);
    expect(f.fv).toBeCloseTo(1, 5);
  });

  it('zoneFractionToCanvas puts (0.5,0.5) at canvas centre and flips v', () => {
    const c = zoneFractionToCanvas({ fu: 0.5, fv: 0.5 }, { w: 200, h: 120 });
    expect(c.x).toBeCloseTo(100, 5);
    expect(c.y).toBeCloseTo(60, 5);
    const top = zoneFractionToCanvas({ fu: 0.5, fv: 1 }, { w: 200, h: 120 });
    expect(top.y).toBeCloseTo(0, 5);
  });

  it('canvasToZoneFraction is the inverse of zoneFractionToCanvas', () => {
    const px = { w: 200, h: 120 };
    const f = { fu: 0.3, fv: 0.8 };
    const c = zoneFractionToCanvas(f, px);
    const back = canvasToZoneFraction(c, px);
    expect(back.fu).toBeCloseTo(f.fu, 5);
    expect(back.fv).toBeCloseTo(f.fv, 5);
  });

  it('clamps fractions to [0,1] when a hit lands outside the zone', () => {
    const f = worldToZoneFraction(new THREE.Vector3(999, -999, 0), zone);
    expect(f.fu).toBe(1);
    expect(f.fv).toBe(0);
  });
});
