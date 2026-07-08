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

**Live-refresh approach (performance-critical).** The decal texture IS the fabric
canvas. Do NOT re-export a 4× PNG per frame during a drag (a PNG encode per frame
janks badly). Instead wrap the fabric canvas DOM element in a `THREE.CanvasTexture`
and set `texture.needsUpdate = true` when it changes. The decal GEOMETRY does not
rebuild on a move (the zone is unchanged — only the texture content shifts);
geometry rebuilds only if the zone itself changes. `toDataURL` is used ONLY for the
final capture/print file, never in the drag loop.

**Verification prerequisite.** The interaction is WebGL and can only be confirmed
by driving the running app. Before implementing Group D, confirm a **flat
`MODEL_3D` product WITH a print zone** exists to test against (seed one via the
admin flow or a factory/seeder if none is present) and that the frontend dev
server + backend API can be started. If the stack can't be started in the build
environment, the D1 preview acceptance runs in the user's dev environment.

**Rotate scope.** Move is the core value; rotate (Group C2) is lower value and can
ship as a fast-follow if it complicates C1 — do C1 + the sync (D1) first, then C2.

---

## File Structure

**Create:**
- `frontend/src/lib/zoneMapping.ts` — pure mesh↔zone↔canvas coordinate functions.
- `frontend/src/lib/zoneMapping.test.ts`

**Modify:**
- `frontend/src/components/DesignerCanvas.tsx` — expose an imperative handle (via `forwardRef`): get/set the active logo's placement in zone fractions, get the live canvas element, and emit `onPlacementChange`. Fabric stays authoritative.
- `frontend/src/components/Model3dDecalPreview.tsx` — interactive drag (move, off-zone-guarded) + a rotate control on the flat face; a `CanvasTexture` over the live fabric canvas for zero-encode refresh; decal geometry built once per (mesh, zone); auto-rotate disabled while editing.
- `frontend/src/pages/ProductDesignerPage.tsx` — wire the canvas ref ↔ preview (pass the live canvas element + a dirty tick) so a drag on the model moves the fabric logo and the `CanvasTexture` refreshes, and a 2D-pad move reflects on the model.

---

## Group 0 — Spike (throwaway, de-risk before committing)

Purpose: prove the two risky things — the mesh→zone coordinate feel and the
`CanvasTexture` live-refresh smoothness — on one real product BEFORE building the
full, tested feature. This code is disposable; do not polish or test it.

- [ ] **Step 1: Confirm a target product.** Identify (or seed) a flat `MODEL_3D`
  product with a print zone and a streamable mesh. Note its id/slug.

- [ ] **Step 2: Minimal spike branch.** In a scratch branch, hack `Model3dDecalPreview`
  to: (a) build a `THREE.CanvasTexture` from a throwaway `<canvas>` you draw a
  coloured rectangle into; (b) on `pointermove` over the mesh, raycast, project the
  hit with `worldToZoneFraction` (write a quick inline version), redraw the rect at
  that fraction, and set `texture.needsUpdate = true`. No fabric, no sync, no tests.

- [ ] **Step 3: Run the app and judge two things.** `preview_start`, open the product:
  - Does dragging on the face move the rectangle to roughly where the cursor is, on
    the correct part of the model? (coordinate feel)
  - Is it smooth (no per-frame PNG encode)? (perf)

- [ ] **Step 4: Decide.** If the feel/perf are good → proceed to Group A with
  confidence. If the mapping is off or perf is bad → STOP and report findings; the
  full plan's assumptions need revisiting before investing in the tested build.

- [ ] **Step 5: Discard the spike.** `git checkout -- .` / delete the scratch branch.
  Nothing from the spike is merged.

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
  /** Move the active logo to a zone fraction (fv is world-up; centre origin). */
  setLogoFraction: (fu: number, fv: number) => void;
  /** Set the active logo's rotation in degrees. */
  setLogoAngle: (deg: number) => void;
  /** Current active-logo placement (zone fraction), or null if no logo. */
  getLogoPlacement: () => { fu: number; fv: number; angle: number } | null;
  /**
   * The live fabric render surface, for the 3D preview to wrap in a
   * THREE.CanvasTexture (no per-frame PNG encode). Transparent-bg design layers
   * only — exactly the decal artwork.
   */
  getCanvasElement: () => HTMLCanvasElement | null;
}
```

(No `exportArtwork` on the handle: the final capture keeps using the existing
`DesignerCanvas` "Use this design" button → `onCapture` (4× PNG), and the decal
print-file keeps using `Model3dDecalPreview.generatePrintFile()`. Nothing on the
page re-exports the canvas.)

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
    // fabric v6 renders into `lowerCanvasEl`; that element carries the pixels the
    // CanvasTexture samples. Fall back to the mounted element defensively.
    getCanvasElement: () => canvasRef.current?.lowerCanvasEl ?? elRef.current ?? null,
  }), [dims.w, dims.h, onPlacementChange]);
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
  /** Live fabric canvas to wrap as a CanvasTexture; null → use artworkDataUrl. */
  liveCanvas?: HTMLCanvasElement | null;
  /** Bumped by the page on any placement change → flag texture needsUpdate. */
  dirtyTick?: number;
  /** Called with the new zone fraction (v-up) as the buyer drags on the face. */
  onDragPlacement?: (fu: number, fv: number) => void;
  /** Called with the accumulated angle (deg) from the rotate control. */
  onRotate?: (deg: number) => void;
  /* `zone` is already an existing prop. */
```

- [ ] **Step 2: Add raycast drag handlers** mirroring `Model3dZoneEditor`'s click-vs-orbit discrimination. In the loader effect, after the mesh is added, wire pointer handlers on `renderer.domElement`:

```ts
    // Interactive placement: a short drag on the mesh face (not an orbit) maps
    // the hit point to a zone fraction and reports it. Orbit still works when
    // the gesture starts off the face or exceeds the drag threshold as a spin.
    const raycaster = new THREE.Raycaster();
    let dragging = false;
    const ndc = new THREE.Vector2();
    // Only accept hits on the PRINTABLE face: the hit's world normal must be
    // ~parallel to the zone normal, else the buyer dragged onto a side face and
    // we must ignore it (not snap the logo to the zone edge). ~25° tolerance.
    const zoneNormal = new THREE.Vector3(...zone.normal).normalize();
    const NORMAL_DOT_MIN = Math.cos((25 * Math.PI) / 180);
    const toFraction = (e: PointerEvent): { fu: number; fv: number } | null => {
      const mesh = meshRef.current;
      if (!mesh) return null;
      const rect = renderer.domElement.getBoundingClientRect();
      ndc.set(((e.clientX - rect.left) / rect.width) * 2 - 1, -((e.clientY - rect.top) / rect.height) * 2 + 1);
      raycaster.setFromCamera(ndc, camera);
      const hits = raycaster.intersectObject(mesh, false);
      const hit = hits[0];
      if (!hit || !hit.face) return null;
      // World-space face normal.
      const nm = new THREE.Matrix3().getNormalMatrix(mesh.matrixWorld);
      const worldNormal = hit.face.normal.clone().applyMatrix3(nm).normalize();
      if (worldNormal.dot(zoneNormal) < NORMAL_DOT_MIN) return null; // off the printable face
      return worldToZoneFraction(hit.point.clone(), zone); // from zoneMapping
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

- [ ] **Step 3: Live texture via `CanvasTexture` (no per-frame PNG encode).** Add a
  prop `liveCanvas?: HTMLCanvasElement | null`. When `interactive && liveCanvas`,
  the decal's texture is a `THREE.CanvasTexture(liveCanvas)` instead of the
  `TextureLoader(artworkDataUrl)` path. Refresh rules:
  - Build the decal GEOMETRY once per (mesh, zone) — NOT per texture change. The
    existing artwork effect rebuilds geometry inside the texture `load` callback;
    restructure so a texture/content change only sets `texture.needsUpdate = true`
    and does NOT dispose/rebuild `decalGeo`. Geometry rebuilds only when `zone`
    (or the mesh) changes.
  - While a placement drag is in progress (and for one frame after any
    `onDragPlacement`/external placement change), set `canvasTexture.needsUpdate =
    true` in the render loop so the moved logo re-uploads to the GPU. When idle,
    stop flagging it (avoid a needless per-frame canvas upload).
  - Keep the non-interactive path (static `artworkDataUrl` → `TextureLoader`)
    unchanged for display-only cases.

- [ ] **Step 4: Preview verification (coordinator).** No unit test (WebGL). Verified in Group D's preview pass: dragging on the face moves the logo smoothly (no jank), and starting a drag off the printable face still orbits (off-zone guard).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/components/Model3dDecalPreview.tsx
git commit -m "feat: drag-to-move logo on the flat face via live CanvasTexture"
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

- [ ] **Step 1: Hold a canvas ref + expose its element + a dirty tick.** Add:

```ts
  const canvasHandle = useRef<DesignerCanvasHandle>(null);
  // The fabric render surface the preview wraps in a CanvasTexture.
  const [liveCanvas, setLiveCanvas] = useState<HTMLCanvasElement | null>(null);
  // Bumped whenever placement changes (from EITHER editor) so the preview knows
  // to flag its CanvasTexture needsUpdate for a frame. Cheap integer, no re-export.
  const [decalDirty, setDecalDirty] = useState(0);
```

Populate `liveCanvas` after mount / when the product changes, from the handle:

```ts
  useEffect(() => {
    setLiveCanvas(canvasHandle.current?.getCanvasElement() ?? null);
  }, [product?.id]);
```

- [ ] **Step 2: Pass the ref + placement callback to `DesignerCanvas`:**

```tsx
        <DesignerCanvas
          ref={canvasHandle}
          /* …existing props… */
          onPlacementChange={() => setDecalDirty((n) => n + 1)}
        />
```

- [ ] **Step 3: Make the preview interactive for flat-with-zone items.** Pass to `Model3dDecalPreview`:

```tsx
          <Model3dDecalPreview
            /* …existing props… */
            artworkDataUrl={artwork?.dataUrl ?? null}   {/* static fallback / non-interactive */}
            interactive={is3d && !!zone}
            liveCanvas={is3d && zone ? liveCanvas : null}
            dirtyTick={decalDirty}
            onDragPlacement={(fu, fv) => {
              canvasHandle.current?.setLogoFraction(fu, fv);
              setDecalDirty((n) => n + 1);
            }}
            onRotate={(deg) => {
              canvasHandle.current?.setLogoAngle(deg);
              setDecalDirty((n) => n + 1);
            }}
          />
```

`Model3dDecalPreview` (Task C1 Step 3) uses `liveCanvas` as a `CanvasTexture` when
present and flags `needsUpdate` on `dirtyTick` changes + during a drag; otherwise
it falls back to the static `artworkDataUrl`. Only flat zones are in scope; the
decal projection already handles the flat case.

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
- Live decal refresh uses a `THREE.CanvasTexture` over the fabric canvas element
  (GPU re-upload of the canvas on `needsUpdate`, no PNG encode). This is the
  cheap path; the Group 0 spike validates it feels smooth before the full build.
  The decal geometry is built once per (mesh, zone) and never rebuilt on a move.
