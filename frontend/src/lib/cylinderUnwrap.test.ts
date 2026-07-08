import { describe, it, expect } from 'vitest';
import { arcLengthMm, mmToPx, unwrapSizePx } from './cylinderUnwrap';

describe('cylinderUnwrap', () => {
  it('arc length = radius * angle(rad)', () => {
    expect(arcLengthMm(30, 180)).toBeCloseTo(Math.PI * 30, 4);
    expect(arcLengthMm(30, 360)).toBeCloseTo(2 * Math.PI * 30, 4);
  });
  it('mmToPx converts at the given DPI (1 inch = 25.4mm)', () => {
    expect(mmToPx(25.4, 300)).toBeCloseTo(300, 4);
    expect(mmToPx(10, 300)).toBeCloseTo(10 * 300 / 25.4, 4);
  });
  it('unwrapSizePx maps (radius, angle, height, dpi) to integer px dims', () => {
    const s = unwrapSizePx({ radius_mm: 30, angle_extent_deg: 120, height_mm: 80, dpi: 300 });
    const widthMm = (120 / 180) * Math.PI * 30;
    expect(s.width_px).toBe(Math.round(widthMm * 300 / 25.4));
    expect(s.height_px).toBe(Math.round(80 * 300 / 25.4));
    expect(Number.isInteger(s.width_px)).toBe(true);
    expect(Number.isInteger(s.height_px)).toBe(true);
  });
});
