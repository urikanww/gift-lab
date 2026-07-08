# Product Designer — Phase 2 Implementation Plan (flat drag-on-model)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the buyer position their logo by dragging (move + rotate) directly on the flat face of the 3D model, kept perfectly in sync with the existing 2D pad, with no new capture or print pipeline.

**Architecture:** The fabric `DesignerCanvas` stays the single source of truth and keeps the entire capture pipeline. `Model3dDecalPreview` becomes an interactive second controller: a raycast drag on the flat face maps to a zone `(u,v)`, which is written back into the fabric logo's position; a rotate handle sets its angle. Both views reflect the same fabric object, so they stay in sync. The mesh↔zone↔canvas coordinate math is extracted into pure, unit-tested functions; the three.js interaction glue is verified in the live preview (WebGL can't run in the jsdom test env).

**Tech Stack:** React + TypeScript + Vite (Vitest), three.js (raycaster, decal), Fabric.js, Tailwind.

**Design spec:** `docs/superpowers/specs/2026-07-08-product-designer-enhancement-design.md` (Phase 2 section).

**Scope:** flat `MODEL_3D` items with a print zone (detected or admin-set). No-zone items keep today's flow. The 2D pad remains available as the alternate editor.

---

## Prerequisites / context for the implementer

- `frontend/src/lib/printZone.ts` exports `PrintZone { normal, center, up, width_mm, height_mm }` and `zoneBasis(zone) → { n, u, v }` (orthonormal). A zone spans `±width_mm/2` along `u` and `±height_mm/2` along `v`, centred at `center`.
- `frontend/src/components/Model3dDecalPreview.tsx` already: loads the STL, renders the decal from an `artworkDataUrl` prop over the zone, auto-rotates via `OrbitControls`, and exposes `generatePrintFile()`. It does NOT recenter geometry, so world space == model space == zone space.
- `frontend/src/components/Model3dZoneEditor.tsx` already raycasts pointer events onto the mesh and distinguishes a click from an orbit drag with a 5px move threshold (`onPointerDown`/`onPointerUp`, `raycaster.intersectObject`). Reuse this pattern.
- `frontend/src/components/DesignerCanvas.tsx` is the fabric canvas. The active logo is a `FabricImage`; `dims.w/dims.h` are the live canvas pixel size; logos use a centre origin (`originX/Y='center'`), so `obj.left/obj.top` ARE the centre. Size bands (S/M/L) live in `LOGO_BANDS`. It already emits `onLogoChange`.
- `frontend/src/pages/ProductDesignerPage.tsx` renders `Model3dDecalPreview` (when `is3d && zone`) and `DesignerCanvas` with `canvasMm = { width: zone.width_mm, height: zone.height_mm }` for zoned items.

---

## File Structure

**Create:**
- `frontend/src/lib/zoneMapping.ts` — pure mesh↔zone↔canvas coordinate functions.
- `frontend/src/lib/zoneMapping.test.ts`

**Modify:**
- `frontend/src/components/DesignerCanvas.tsx` — expose an imperative handle (via `forwardRef`) to get/set the active logo's placement in normalized zone coords + emit a throttled live placement/artwork change.
- `frontend/src/components/Model3dDecalPreview.tsx` — interactive drag (move) + a rotate handle on the flat face, calling back placement changes; disable auto-rotate while editing.
- `frontend/src/pages/ProductDesignerPage.tsx` — wire the canvas ref ↔ preview so a drag on the model moves the fabric logo and the decal texture refreshes (throttled).

---

## Group A — Pure coordinate math (fully testable)

### Task A1: zoneMapping module

**Files:**
- Create: `frontend/src/lib/zoneMapping.ts`, `frontend/src/lib/zoneMapping.test.ts`

The zone is a plane centred at `center` with orthonormal axes `u` (right), `v` (up), spanning `±width_mm/2` × `±height_mm/2`. Canvas pixels have origin top-left, y DOWN. We map a world point on the face → normalized zone fraction `(fu, fv)` in `[0,1]` → canvas centre pixel `(x, y)`, and the inverse.

- [ ] **Step 1: Write the failing test** (`zoneMapping.test.ts`):

```ts
import { describe, it, expect } from 'vitest';
import * as THREE from 'three';
import { worldToZoneFraction, zoneFractionToCanvas, canvasToZoneFraction } from './zoneMapping';
import type { PrintZone } from './printZone';

// Axis-aligned zone: centre at origin, normal +z, up +y, 100mm x 60mm.
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

  it('maps the +u,+v corner to (1, 0) — v is up, canvas y is down', () => {
    // +u = +x (right, since u = v×n = +y×+z = +x), +v = +y (up)
    const f = worldToZoneFraction(new THREE.Vector3(50, 30, 0), zone);
    expect(f.fu).toBeCloseTo(1, 5); // right edge
    expect(f.fv).toBeCloseTo(1, 5); // top edge (world up)
  });

  it('zoneFractionToCanvas puts fraction (0.5,0.5) at canvas centre and flips v', () => {
    const c = zoneFractionToCanvas({ fu: 0.5, fv: 0.5 }, { w: 200, h: 120 });
    expect(c.x).toBeCloseTo(100, 5);
    expect(c.y).toBeCloseTo(60, 5);
    // fv=1 (world-up) → canvas top (y=0)
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
```

- [ ] **Step 2: Run, verify it fails**

Run: `cd frontend && npx vitest run src/lib/zoneMapping.test.ts`
Expected: FAIL (module not found).

- [ ] **Step 3: Implement** (`zoneMapping.ts`):

```ts
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
  const du = local.dot(u); // -w/2..+w/2
  const dv = local.dot(v); // -h/2..+h/2
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
```

- [ ] **Step 4: Run, verify passes**

Run: `cd frontend && npx vitest run src/lib/zoneMapping.test.ts`
Expected: PASS (5 tests)

- [ ] **Step 5: Commit**

```bash
git add frontend/src/lib/zoneMapping.ts frontend/src/lib/zoneMapping.test.ts
git commit -m "feat: pure zone<->canvas coordinate mapping for drag-on-model"
```

---

## Group B — DesignerCanvas placement API

### Task B1: Expose an imperative placement handle

The page (and the 3D preview via the page) needs to move/rotate the active logo in normalized zone coords, and be notified when placement changes so it can refresh the decal. Keep fabric authoritative — this is a thin adapter over the existing active object.

**Files:**
- Modify: `frontend/src/components/DesignerCanvas.tsx`
- Test: `frontend/src/components/DesignerCanvas.test.tsx`

- [ ] **Step 1: Define the handle type + convert the component to `forwardRef`.** At the top of `DesignerCanvas.tsx` add:

```ts
export interface DesignerCanvasHandle {
  /** Move the active logo to a normalized canvas fraction (centre origin). */
  setLogoFraction: (fu: number, fv: number) => void;
  /** Set the active logo's rotation in degrees. */
  setLogoAngle: (deg: number) => void;
  /** Current active-logo placement, or null if no logo. */
  getLogoPlacement: () => { fu: number; fv: number; angle: number } | null;
  /** Live production-resolution PNG of the current design (same as capture's export). */
  exportArtwork: () => string | null;
}
```

Change the component signature to `forwardRef<DesignerCanvasHandle, DesignerCanvasProps>(function DesignerCanvas(props, ref) { ... })` and add a default export wrapper if the file currently `export default function`. (It does — wrap it: `const DesignerCanvas = forwardRef(...); export default DesignerCanvas;`.)

- [ ] **Step 2: Add a placement-change callback prop.** Extend `DesignerCanvasProps`:

```ts
  /**
   * Fires (throttled to animation frames) whenever the active logo's position or
   * angle changes, so an external 3D view can refresh its decal. Coordinates are
   * normalized canvas fractions with a centre origin.
   */
  onPlacementChange?: (p: { fu: number; fv: number; angle: number }) => void;
```

- [ ] **Step 3: Implement the handle + fire the callback.** The handle speaks **zone fractions** (`fv` is world-up, matching the 3D preview and `zoneMapping`), NOT raw canvas fractions (canvas y is down). Reuse the tested `zoneMapping` helpers so the single v-flip lives in one place — `import { zoneFractionToCanvas, canvasToZoneFraction } from '../lib/zoneMapping';`. Inside the component:

```ts
  const activeLogo = (): FabricImage | null => {
    const canvas = canvasRef.current;
    const img = canvas?.getObjects().find((o): o is FabricImage => o instanceof FabricImage);
    return img ?? null;
  };

  // Fabric centre pixel (img.left/top) <-> zone fraction (v-up), via the shared
  // mapping so the flip is defined once and matches the 3D side exactly.
  const px = () => ({ w: dims.w, h: dims.h });

  const emitPlacement = () => {
    const img = activeLogo();
    if (!img) return;
    const { fu, fv } = canvasToZoneFraction({ x: img.left ?? 0, y: img.top ?? 0 }, px());
    onPlacementChange?.({ fu, fv, angle: img.angle ?? 0 });
  };

  useImperativeHandle(ref, () => ({
    setLogoFraction: (fu, fv) => {
      const canvas = canvasRef.current;
      const img = activeLogo();
      if (!canvas || !img) return;
      const { x, y } = zoneFractionToCanvas({ fu, fv }, px());
      img.set({ left: x, top: y });
      img.setCoords();
      clampToPrintAreaRef.current?.(img);
      canvas.requestRenderAll();
      markDirty();
    },
    setLogoAngle: (deg) => {
      const canvas = canvasRef.current;
      const img = activeLogo();
      if (!canvas || !img) return;
      img.rotate(((deg % 360) + 360) % 360);
      img.setCoords();
      clampToPrintAreaRef.current?.(img);
      canvas.requestRenderAll();
      markDirty();
    },
    getLogoPlacement: () => {
      const img = activeLogo();
      if (!img) return null;
      const { fu, fv } = canvasToZoneFraction({ x: img.left ?? 0, y: img.top ?? 0 }, px());
      return { fu, fv, angle: img.angle ?? 0 };
    },
    exportArtwork: () => {
      const canvas = canvasRef.current;
      if (!canvas) return null;
      const exportWidthPx = width * 4;
      const multiplier = exportWidthPx / dims.w;
      return canvas.toDataURL({ format: 'png', multiplier });
    },
  }), [dims.w, dims.h, width, onPlacementChange]);
```

Then call `emitPlacement()` from the EXISTING `onMoving`, `onModified`, and `onRotating` handlers (so a drag on the 2D pad notifies the 3D view too) and after the arrow-key nudge. Import `forwardRef, useImperativeHandle` from React.

Note: the `setLogoFraction` test in Step 4 must account for the flip — `setLogoFraction(0.25, 0.75)` sets canvas `top = (1 - 0.75) * h`, and `getLogoPlacement` inverts it back to `fv ≈ 0.75`. Assert the round-trip (`set` then `get`) rather than the raw pixel, so the test is flip-agnostic.

- [ ] **Step 4: Update callers for the ref.** In `DesignerCanvas.test.tsx`, existing tests render `<DesignerCanvas .../>` without a ref — that still works (ref optional). Add a test that exercises the handle:

```tsx
it('setLogoFraction moves the active logo and getLogoPlacement reflects it', async () => {
  const ref = createRef<DesignerCanvasHandle>();
  // render with ref + add a logo the same way the other tests do (reuse the file's helper)
  // then:
  ref.current!.setLogoFraction(0.25, 0.75);
  const p = ref.current!.getLogoPlacement();
  expect(p!.fu).toBeCloseTo(0.25, 2);
  expect(p!.fv).toBeCloseTo(0.75, 2);
});
```

Match the file's existing async canvas/logo setup (it already adds a logo via a data URL in other tests — reuse that helper). Import `createRef`.

- [ ] **Step 5: Run tests + typecheck**

Run: `cd frontend && npx vitest run src/components/DesignerCanvas.test.tsx && npx tsc --noEmit`
Expected: PASS + clean. (Do NOT run the full vitest suite — it hangs.)

- [ ] **Step 6: Commit**

```bash
git add frontend/src/components/DesignerCanvas.tsx frontend/src/components/DesignerCanvas.test.tsx
git commit -m "feat: imperative placement handle + placement-change callback on DesignerCanvas"
```

---

## Group C — Interactive drag + rotate on the model

WebGL/three.js cannot run in the jsdom test env, so these tasks are verified in the live preview by the coordinator, not by unit tests. Keep the pure math in `zoneMapping` (already tested) and the fabric writes behind the tested handle — the three.js layer here is thin glue.

### Task C1: Drag-to-move on the flat face

**Files:**
- Modify: `frontend/src/components/Model3dDecalPreview.tsx`

- [ ] **Step 1: Add props for interactivity.** Extend `Props`:

```ts
  /** When true, dragging on the flat face moves the logo (Phase 2). */
  interactive?: boolean;
  /** Zone the logo is constrained to (same zone used for the decal). */
  /* (already present as `zone`) */
  /** Called with the new normalized zone fraction as the buyer drags on the face. */
  onDragPlacement?: (fu: number, fv: number) => void;
```

- [ ] **Step 2: Add raycast drag handlers** mirroring `Model3dZoneEditor`'s click-vs-orbit discrimination. In the loader effect, after the mesh is added, wire pointer handlers on `renderer.domElement`:

```ts
    // Interactive placement: a short drag on the mesh face (not an orbit) maps
    // the hit point to a zone fraction and reports it. Orbit still works when
    // the gesture starts off the face or exceeds the drag threshold as a spin.
    const raycaster = new THREE.Raycaster();
    let dragging = false;
    const ndc = new THREE.Vector2();
    const toFraction = (e: PointerEvent): { fu: number; fv: number } | null => {
      const mesh = meshRef.current;
      if (!mesh) return null;
      const rect = renderer.domElement.getBoundingClientRect();
      ndc.set(((e.clientX - rect.left) / rect.width) * 2 - 1, -((e.clientY - rect.top) / rect.height) * 2 + 1);
      raycaster.setFromCamera(ndc, camera);
      const hits = raycaster.intersectObject(mesh, false);
      if (!hits.length) return null;
      return worldToZoneFraction(hits[0].point.clone(), zone); // from zoneMapping
    };
    const onDown = (e: PointerEvent) => {
      if (!interactiveRef.current) return;
      const f = toFraction(e);
      if (!f) return; // started off the mesh → let OrbitControls handle it
      dragging = true;
      controls.enableRotate = false; // don't spin while placing
      onDragPlacementRef.current?.(f.fu, f.fv);
    };
    const onMove = (e: PointerEvent) => {
      if (!dragging) return;
      const f = toFraction(e);
      if (f) onDragPlacementRef.current?.(f.fu, f.fv);
    };
    const onUp = () => {
      dragging = false;
      controls.enableRotate = true;
    };
    renderer.domElement.addEventListener('pointerdown', onDown);
    renderer.domElement.addEventListener('pointermove', onMove);
    renderer.domElement.addEventListener('pointerup', onUp);
    renderer.domElement.addEventListener('pointerleave', onUp);
```

Use refs (`interactiveRef`, `onDragPlacementRef`) updated via `useEffect` so the loader effect (which runs once per product/colour) always sees the latest prop values without re-downloading the STL. Import `worldToZoneFraction` from `../lib/zoneMapping`. Remove all four listeners + set `controls.enableRotate = true` in the cleanup. Also set `controls.autoRotate = false` when `interactive` (auto-spin fights placement) — gate the existing `autoRotate = true` on `!interactive`.

- [ ] **Step 3: Preview verification (coordinator).** No unit test (WebGL). Verified in Group D's preview pass: dragging on the face moves the logo; starting a drag off the face still orbits.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/components/Model3dDecalPreview.tsx
git commit -m "feat: drag-to-move the logo on the flat face of the 3D model"
```

### Task C2: Rotate handle on the model

**Files:**
- Modify: `frontend/src/components/Model3dDecalPreview.tsx`

- [ ] **Step 1: Add a rotate affordance.** Add an `onRotate?: (deg: number) => void` prop and a small on-screen rotate control anchored over the preview (a circular handle button, or +/−15° buttons in the preview's corner overlay) that calls `onRotate` with the accumulated angle. A full 3D gizmo is out of scope; a corner rotate control (drag a ring, or two nudge buttons) is sufficient and testable-by-eye. Keep the current rotation in a ref so the buttons accumulate.

- [ ] **Step 2: Preview verification (coordinator).** Verified in Group D.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/components/Model3dDecalPreview.tsx
git commit -m "feat: rotate control for the logo on the 3D model"
```

---

## Group D — Wire it together + verify

### Task D1: Sync canvas ↔ preview in ProductDesignerPage

**Files:**
- Modify: `frontend/src/pages/ProductDesignerPage.tsx`

- [ ] **Step 1: Hold a canvas ref + live artwork state.** Add:

```ts
  const canvasHandle = useRef<DesignerCanvasHandle>(null);
  // Live artwork for the interactive decal, refreshed (throttled) as the logo moves.
  const [liveArtwork, setLiveArtwork] = useState<string | null>(null);
  const rafRef = useRef<number | null>(null);
  const refreshDecalThrottled = useCallback(() => {
    if (rafRef.current != null) return;
    rafRef.current = requestAnimationFrame(() => {
      rafRef.current = null;
      setLiveArtwork(canvasHandle.current?.exportArtwork() ?? null);
    });
  }, []);
```

- [ ] **Step 2: Pass the ref + placement callback to `DesignerCanvas`:**

```tsx
        <DesignerCanvas
          ref={canvasHandle}
          /* …existing props… */
          onPlacementChange={refreshDecalThrottled}
        />
```

- [ ] **Step 3: Make the preview interactive for flat-with-zone items.** Pass to `Model3dDecalPreview`:

```tsx
          <Model3dDecalPreview
            /* …existing props… */
            artworkDataUrl={liveArtwork ?? artwork?.dataUrl ?? null}
            interactive={is3d && !!zone}
            onDragPlacement={(fu, fv) => {
              canvasHandle.current?.setLogoFraction(fu, fv);
              refreshDecalThrottled();
            }}
            onRotate={(deg) => {
              canvasHandle.current?.setLogoAngle(deg);
              refreshDecalThrottled();
            }}
          />
```

Only flat zones are in scope this phase; the decal projection already handles the flat case. Cancel the pending rAF on unmount.

- [ ] **Step 4: Typecheck**

Run: `cd frontend && npx tsc --noEmit`
Expected: clean.

- [ ] **Step 5: Live preview verification (coordinator).** This is the acceptance gate for the WebGL interaction that unit tests can't cover:
  - `preview_start` the frontend dev server; open a flat `MODEL_3D` product's design route (one with a print zone).
  - Upload a logo. Confirm it appears on both the 2D pad and the 3D decal.
  - Drag the logo on the 3D model → it moves on the model AND the 2D pad moves in lockstep.
  - Rotate via the control → both views rotate.
  - Drag toward the face edge → clamps (snaps back), never leaves the printable zone.
  - Move on the 2D pad → the 3D decal follows.
  - `preview_console_logs` (errors) clean; `preview_screenshot` for the record.
  - "Use this design" → confirm the captured artwork/print file matches the placement (same pipeline as before).

- [ ] **Step 6: Commit**

```bash
git add frontend/src/pages/ProductDesignerPage.tsx
git commit -m "feat: sync drag-on-model with the 2D pad in the product designer"
```

---

## Final verification

- [ ] `cd frontend && npx vitest run src/lib/zoneMapping.test.ts src/components/DesignerCanvas.test.tsx` — green.
- [ ] `npx tsc --noEmit` — clean.
- [ ] Live preview acceptance (Task D1 Step 5) — recorded with a screenshot.
- [ ] Regression: a no-zone 3D item and a 2D (`SCRAPED_UV`) item still design + capture exactly as before (2D pad only, no interactive drag).

---

## Spec coverage check

- Drag-to-move on the flat model → Group C1 + D1.
- Rotate on the model → Group C2 + D1.
- 2D pad ↔ 3D view stay in sync (single fabric source of truth) → Group B (handle + `onPlacementChange`) + D1.
- Size stays on the S/M/L band selector (no resize-on-model) → unchanged; not touched here.
- Scope = flat MODEL_3D with a zone; no-zone + 2D items unchanged → Group D1 gating + final regression check.
- No new capture/print pipeline → capture path untouched; `exportArtwork` reuses the existing export settings.

## Known limitations / deferred

- The three.js interaction (drag/rotate/orbit) is verified in the live preview, not unit-tested — the jsdom test env has no WebGL. The pure coordinate math (`zoneMapping`) and the fabric placement handle ARE unit-tested.
- Rotate uses a corner control, not a full 3D gizmo on the mesh (YAGNI for Phase 1 of the interaction; revisit if buyers want direct-grab rotation).
- Curved/cylindrical faces are Phase 3 — this phase gates on flat zones only.
- Live decal refresh re-exports the canvas per animation frame during a drag; if profiling shows jank on large logos, switch to a lightweight sprite during drag and commit the full texture on pointer-up.
