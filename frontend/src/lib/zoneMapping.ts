import * as THREE from 'three';
import { zoneBasis, type PrintZone } from './printZone';

export interface ZoneFraction {
  /** 0..1 along the zone's u axis (left→right). */
  fu: number;
  /** 0..1 along the zone's v axis (bottom→top, i.e. world-up). */
  fv: number;
}

const clamp01 = (n: number) => Math.min(1, Math.max(0, n));

/**
 * Project a world-space point on (or near) the zone plane to a normalized zone
 * fraction. The zone spans ±width/2 along u and ±height/2 along v about `center`;
 * (0.5,0.5) is the centre. Out-of-zone hits clamp to [0,1].
 */
export function worldToZoneFraction(point: THREE.Vector3, zone: PrintZone): ZoneFraction {
  const { u, v } = zoneBasis(zone);
  const center = new THREE.Vector3(...zone.center);
  const local = point.clone().sub(center);
  const du = local.dot(u);
  const dv = local.dot(v);
  return {
    fu: clamp01(du / zone.width_mm + 0.5),
    fv: clamp01(dv / zone.height_mm + 0.5),
  };
}

/**
 * Zone fraction → canvas CENTRE pixel. Canvas y is DOWN, zone v is UP, so v is
 * flipped. `px` is the live fabric canvas pixel size.
 */
export function zoneFractionToCanvas(f: ZoneFraction, px: { w: number; h: number }): { x: number; y: number } {
  return { x: f.fu * px.w, y: (1 - f.fv) * px.h };
}

/** Inverse of zoneFractionToCanvas: canvas centre pixel → zone fraction. */
export function canvasToZoneFraction(c: { x: number; y: number }, px: { w: number; h: number }): ZoneFraction {
  return { fu: clamp01(c.x / px.w), fv: clamp01(1 - c.y / px.h) };
}
