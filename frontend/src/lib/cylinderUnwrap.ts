const MM_PER_INCH = 25.4;
export function arcLengthMm(radius: number, angleDeg: number): number {
  return radius * (angleDeg * Math.PI) / 180;
}
export function mmToPx(mm: number, dpi: number): number {
  return (mm * dpi) / MM_PER_INCH;
}
export interface UnwrapParams { radius_mm: number; angle_extent_deg: number; height_mm: number; dpi: number; }
export function unwrapSizePx(p: UnwrapParams): { width_px: number; height_px: number } {
  const widthMm = arcLengthMm(p.radius_mm, p.angle_extent_deg);
  return { width_px: Math.round(mmToPx(widthMm, p.dpi)), height_px: Math.round(mmToPx(p.height_mm, p.dpi)) };
}
