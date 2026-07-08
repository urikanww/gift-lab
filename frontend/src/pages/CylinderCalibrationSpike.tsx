import { useMemo, useRef, useState, useEffect } from 'react';
import { unwrapSizePx, mmToPx, arcLengthMm } from '../lib/cylinderUnwrap';

export default function CylinderCalibrationSpike() {
  const [radius, setRadius] = useState(30);
  const [height, setHeight] = useState(80);
  const [angle, setAngle] = useState(120);
  const [dpi, setDpi] = useState(300);
  const [grid, setGrid] = useState(10);
  const canvasRef = useRef<HTMLCanvasElement | null>(null);

  const size = useMemo(
    () => unwrapSizePx({ radius_mm: radius, angle_extent_deg: angle, height_mm: height, dpi }),
    [radius, height, angle, dpi],
  );
  const arcMm = arcLengthMm(radius, angle);

  useEffect(() => {
    const cv = canvasRef.current;
    if (!cv) return;
    cv.width = size.width_px;
    cv.height = size.height_px;
    const ctx = cv.getContext('2d');
    if (!ctx) return;
    const pxPerMm = mmToPx(1, dpi);
    ctx.clearRect(0, 0, cv.width, cv.height);
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, cv.width, cv.height);
    ctx.lineWidth = 1;
    for (let mm = 0; mm <= arcMm; mm += grid) {
      const x = mm * pxPerMm;
      ctx.strokeStyle = mm % 50 === 0 ? '#888' : '#d0d0d0';
      ctx.beginPath(); ctx.moveTo(x, 0); ctx.lineTo(x, cv.height); ctx.stroke();
    }
    for (let mm = 0; mm <= height; mm += grid) {
      const y = mm * pxPerMm;
      ctx.strokeStyle = mm % 50 === 0 ? '#888' : '#d0d0d0';
      ctx.beginPath(); ctx.moveTo(0, y); ctx.lineTo(cv.width, y); ctx.stroke();
    }
    ctx.strokeStyle = '#e0567a'; ctx.lineWidth = 2;
    ctx.strokeRect(10 * pxPerMm, 10 * pxPerMm, 40 * pxPerMm, 20 * pxPerMm);
    ctx.fillStyle = '#111'; ctx.font = `${Math.round(4 * pxPerMm)}px sans-serif`;
    ctx.fillText('40x20mm', 11 * pxPerMm, 18 * pxPerMm);
    ctx.fillStyle = '#000';
    ctx.fillText('TOP ↑', 2 * pxPerMm, 6 * pxPerMm);
    ctx.save();
    ctx.translate(2 * pxPerMm, cv.height / 2);
    ctx.fillText('SEAM → θ=0', 0, 0);
    ctx.restore();
  }, [size, arcMm, dpi, grid, height]);

  const download = () => {
    const cv = canvasRef.current;
    if (!cv) return;
    const a = document.createElement('a');
    a.href = cv.toDataURL('image/png');
    a.download = `cyl-cal_r${radius}_h${height}_a${angle}_${dpi}dpi.png`;
    a.click();
  };

  const num = (label: string, val: number, set: (n: number) => void) => (
    <label style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
      {label}
      <input type="number" value={val} onChange={(e) => set(Number(e.target.value) || 0)} style={{ width: 90 }} />
    </label>
  );

  return (
    <div style={{ padding: 24, display: 'flex', flexDirection: 'column', gap: 12 }}>
      <h1>Cylinder unwrap calibration (spike)</h1>
      <div style={{ display: 'flex', gap: 16, flexWrap: 'wrap' }}>
        {num('radius mm', radius, setRadius)}
        {num('height mm', height, setHeight)}
        {num('angle °', angle, setAngle)}
        {num('DPI', dpi, setDpi)}
        {num('grid mm', grid, setGrid)}
      </div>
      <p>
        Unwrap: <b>{arcMm.toFixed(1)}mm</b> (arc) × <b>{height}mm</b> →{' '}
        <b>{size.width_px}×{size.height_px}px</b> at {dpi} DPI ({mmToPx(1, dpi).toFixed(2)} px/mm)
      </p>
      <button onClick={download} style={{ width: 200 }}>Download print PNG</button>
      <div style={{ overflow: 'auto', border: '1px solid #ccc', maxHeight: 400 }}>
        <canvas ref={canvasRef} style={{ width: Math.min(600, size.width_px), height: 'auto' }} />
      </div>
    </div>
  );
}
