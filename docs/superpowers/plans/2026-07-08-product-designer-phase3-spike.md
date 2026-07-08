# Phase 3 Spike — Cylindrical Unwrap Calibration

> **For agentic workers:** this is a DE-RISKING SPIKE, not the full feature. Its job is to produce a calibration print file the operator validates on the physical rotary printer. Keep the throwaway UI minimal; unit-test only the pure math. REQUIRED SUB-SKILL for execution: superpowers:subagent-driven-development.

**Goal:** Ship a small parameterized generator that outputs a flat calibration PNG for a cylindrical print zone, so the operator can print it on the rotary, wrap it on a real test cylinder, and confirm the θ→x / height→y mapping, resolution, orientation, and flip BEFORE the full Phase 3 build.

**Key insight:** the rotary print file is a **flat 2D image** — the unwrapped wall, `width = radius·θ` (arc length) by `height`. Generating the calibration pattern is pure 2D canvas drawing at a known px-per-mm; NO three.js is needed for the spike. (The 3D wrap is only for the customer preview, which the spike doesn't touch.)

**Deliverable of the spike (not code):** locked values — px-per-mm at the chosen DPI, the seam origin (which edge is θ=0), the axis/up direction, and whether any mirror is needed — recorded back into the Phase 3 spec.

**Tech Stack:** React + TypeScript + Vite + Canvas 2D; Vitest for the pure math.

**Spec:** `docs/superpowers/specs/2026-07-08-product-designer-enhancement-design.md` (Phase 3 section).

---

## Scope / non-goals

- IN: a temp route/page with inputs (radius_mm, height_mm, angle_extent°, DPI, grid step), a canvas rendering the calibration unwrap, and a PNG download. Pure unit-tested helpers for the mm↔px + arc-length math.
- OUT (full Phase 3, after the spike passes): the `PrintSurface` model, the admin cylinder editor, the parametric patch decal / 3D preview, the drag `cylinderHitToFraction`, and persistence. Do NOT build these here.
- This code is throwaway. It may be deleted or gated once the calibration is confirmed; do not wire it into the customer flow.

---

## File Structure

**Create:**
- `frontend/src/lib/cylinderUnwrap.ts` — pure math (arc length, mm→px, px dims). Unit-tested. (This module is the ONE piece that survives into the full build.)
- `frontend/src/lib/cylinderUnwrap.test.ts`
- `frontend/src/pages/CylinderCalibrationSpike.tsx` — throwaway generator page.
- one temp route entry so the page is reachable.

---

## Task 1 — Pure unwrap math (survives into the full build)

**Files:**
- Create: `frontend/src/lib/cylinderUnwrap.ts`, `frontend/src/lib/cylinderUnwrap.test.ts`

- [ ] **Step 1: Write the failing test**

```ts
import { describe, it, expect } from 'vitest';
import { arcLengthMm, mmToPx, unwrapSizePx } from './cylinderUnwrap';

describe('cylinderUnwrap', () => {
  it('arc length = radius * angle(rad)', () => {
    // Half of a 30mm-radius wall (180°) = π·30 ≈ 94.248mm
    expect(arcLengthMm(30, 180)).toBeCloseTo(Math.PI * 30, 4);
    // Full wrap (360°) = circumference 2πr
    expect(arcLengthMm(30, 360)).toBeCloseTo(2 * Math.PI * 30, 4);
  });

  it('mmToPx converts at the given DPI (1 inch = 25.4mm)', () => {
    // 25.4mm at 300 DPI = 300px
    expect(mmToPx(25.4, 300)).toBeCloseTo(300, 4);
    expect(mmToPx(10, 300)).toBeCloseTo(10 * 300 / 25.4, 4);
  });

  it('unwrapSizePx maps (radius, angle, height, dpi) to integer px dims', () => {
    const s = unwrapSizePx({ radius_mm: 30, angle_extent_deg: 120, height_mm: 80, dpi: 300 });
    // width mm = arc length of 120° at r=30 = (120/180)·π·30
    const widthMm = (120 / 180) * Math.PI * 30;
    expect(s.width_px).toBe(Math.round(widthMm * 300 / 25.4));
    expect(s.height_px).toBe(Math.round(80 * 300 / 25.4));
    expect(Number.isInteger(s.width_px)).toBe(true);
    expect(Number.isInteger(s.height_px)).toBe(true);
  });
});
```

- [ ] **Step 2: Run, verify it fails**

Run: `cd frontend && npx vitest run src/lib/cylinderUnwrap.test.ts`
Expected: FAIL (module not found).

- [ ] **Step 3: Implement**

```ts
const MM_PER_INCH = 25.4;

/** Arc length (mm) of a `angleDeg` sweep on a cylinder of `radius` mm. */
export function arcLengthMm(radius: number, angleDeg: number): number {
  return radius * (angleDeg * Math.PI) / 180;
}

/** Millimetres → pixels at `dpi` (dots per inch). */
export function mmToPx(mm: number, dpi: number): number {
  return (mm * dpi) / MM_PER_INCH;
}

export interface UnwrapParams {
  radius_mm: number;
  angle_extent_deg: number;
  height_mm: number;
  dpi: number;
}

/** Pixel dimensions of the unwrapped wall rectangle at the given DPI. */
export function unwrapSizePx(p: UnwrapParams): { width_px: number; height_px: number } {
  const widthMm = arcLengthMm(p.radius_mm, p.angle_extent_deg);
  return {
    width_px: Math.round(mmToPx(widthMm, p.dpi)),
    height_px: Math.round(mmToPx(p.height_mm, p.dpi)),
  };
}
```

- [ ] **Step 4: Run, verify passes**

Run: `cd frontend && npx vitest run src/lib/cylinderUnwrap.test.ts`
Expected: PASS (3 tests). Then `npx tsc --noEmit` — clean.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/lib/cylinderUnwrap.ts frontend/src/lib/cylinderUnwrap.test.ts
git commit -m "feat: pure cylindrical unwrap math (arc length, mm->px, px dims)"
```

---

## Task 2 — Calibration generator page (throwaway UI)

**Files:**
- Create: `frontend/src/pages/CylinderCalibrationSpike.tsx`
- Modify: the router to add a temp route (find the app router — likely `frontend/src/App.tsx` — and add `<Route path="/spike/cylinder" element={<CylinderCalibrationSpike />} />` following the existing route pattern; lazy-import if the app lazy-imports pages).

- [ ] **Step 1: Implement the page.** No test (visual/throwaway). It renders number inputs and draws the calibration unwrap to a canvas at native px, then offers a PNG download. Draw:
  - The full unwrap rectangle at `unwrapSizePx(...)` (canvas backing store = those px; scale the on-screen display down with CSS so it fits, but export at native px).
  - A grid every `gridStepMm` (default 10mm) — light lines; heavier line every 50mm.
  - Ruler labels along the top (arc-length mm: `0 … arcLength`) and left (height mm: `0 … height`).
  - A bold **"TOP ↑"** near the top edge and **"SEAM → θ=0"** at the left edge, so orientation/flip are unambiguous when wrapped.
  - A filled reference rectangle of a known size (e.g. 40mm × 20mm) placed at a known offset (e.g. 10mm,10mm), labeled "40×20mm", so the operator can measure the printed result directly.
  - A small caption printing the exact params + computed px dims + px-per-mm.

```tsx
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

    // grid
    ctx.strokeStyle = '#c8c8c8';
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

    // known reference rectangle 40x20mm at (10,10)mm
    ctx.strokeStyle = '#e0567a'; ctx.lineWidth = 2;
    ctx.strokeRect(10 * pxPerMm, 10 * pxPerMm, 40 * pxPerMm, 20 * pxPerMm);
    ctx.fillStyle = '#111'; ctx.font = `${Math.round(4 * pxPerMm)}px sans-serif`;
    ctx.fillText('40x20mm', 11 * pxPerMm, 18 * pxPerMm);

    // orientation markers
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
```

- [ ] **Step 2: Wire the temp route.** Add the route to the app router. Confirm the page loads.

- [ ] **Step 3: Verify (coordinator, browser).** `preview_start`, open `/spike/cylinder`. Confirm the canvas renders the grid + reference rect + markers, the px dims match `unwrapSizePx`, and the download produces a PNG. `preview_console_logs` clean.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/pages/CylinderCalibrationSpike.tsx frontend/src/App.tsx
git commit -m "feat(spike): cylinder unwrap calibration generator page"
```

---

## Task 3 — Operator test protocol (documentation, no code)

- [ ] **Step 1: Write the protocol** into the Phase 3 spec section (or a short `docs/` note): how to run the spike and what to record.
  1. Open `/spike/cylinder`. Enter the physical dims of a real test cylinder (radius, height) and the extent you want to print (e.g. 120°). Start with DPI = the rotary's native resolution (guess 300 if unknown).
  2. Download the PNG. Send it to the rotary EXACTLY as the machine expects a wall file (no auto-scaling/fit).
  3. Wrap/print on the test cylinder. Measure the printed **40×20mm** reference rectangle with calipers.
  4. Record: does 40mm read as 40mm (scale/DPI correct)? Is "TOP ↑" up and "SEAM → θ=0" at the intended start (orientation)? Is the text mirrored (flip)? Is the printed arc-length right (θ mapping)?
  5. Adjust DPI (scale), and note any needed flip/rotation/seam offset. Re-generate and re-print until the reference measures correct.
- [ ] **Step 2: Record locked findings** — px-per-mm/DPI, seam origin edge, up direction, mirror flag — back into the Phase 3 spec's "spike-locked" values. These parameterize the full-build unwrap.

- [ ] **Step 3: Commit the findings** (spec update).

---

## Exit criteria

The spike PASSES when a printed calibration wraps a real cylinder with the reference rectangle measuring correct (±tolerance), orientation right, and no unexpected flip — and the DPI/seam/flip values are recorded in the spec. Only then plan/build the full Phase 3 (surface model, admin editor, patch decal, drag mapping, persistence).

If the print reveals the rotary wants a fundamentally different input (e.g. full-360 only, a specific origin, a non-linear map), STOP and revise the Phase 3 spec before building — that is exactly what this spike exists to catch.
