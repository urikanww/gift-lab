# 3D Customization: Print-Zone Detection, Decal Preview & Model Replace

**Date:** 2026-07-08
**Status:** Approved design, pre-implementation

## Problem

Customization of `MODEL_3D` products "looks very off." The app cannot reliably
detect the flat surface a logo should be UV-printed onto.

Root cause: there is no real surface detection. `frontend/src/lib/modelFaceSnapshot.ts`
picks the **smallest bounding-box axis** and assumes the largest projected face is
the printable one:

```ts
const extents = [size.x, size.y, size.z];
const minAxis = extents.indexOf(Math.min(...extents));
```

It then renders a flat orthographic snapshot that the customer designs on with a 2D
fabric canvas (`DesignerCanvas.tsx`). The "3D customization" is really a 2D decal on
a photo. That heuristic breaks when:

- The surface is curved (mug, ball, figurine) — no flat face exists, the snapshot lies.
- The flat face is not axis-aligned (angled plaque) — bbox-min axis is the wrong normal.
- There are multiple flat faces — it picks one arbitrarily, with no way to choose.
- STL carries no UVs and no materials, so nothing can be textured from the source.

Separately, admins have no way to **replace** a product's model file from the UI,
even though a staff-only upload endpoint already exists but is unwired.

## Production reality (confirmed)

- The item is FDM-printed, then UV-decorated. The UV setup can decorate **any
  surface** (full wrap / rotary jig), so a decal on the real mesh — flat or curved —
  is genuinely producible. The production print file is the decal region flattened to
  its UV space.
- Slicing for `est_grams` uses **PrusaSlicer CLI**, which reads STL/3MF/OBJ off local
  disk (`SlicerService.php`). It cannot read GLB. It is config-gated and often runs in
  manual-fallback mode.
- There is no GLB tooling anywhere server-side today.

## Approach

**STL canonical, GLB additive.** Keep STL/3MF as the canonical model file that feeds
the (working, untouched) slicer and the whole legacy catalogue. Add an **optional**
authored GLB purely for material/color realism. This is chosen over "GLB canonical"
or "two mandatory refs" because:

- `THREE.DecalGeometry` generates its own UVs for the decal mesh, so the **base mesh
  does not need authored UVs**. STL is sufficient for both the decal preview and the
  production print file. GLB only adds authored color/texture realism.
- The slicer path stays byte-for-byte unchanged — no risk to a working measurement
  pipeline, no bulk conversion of the existing STL catalogue.
- No new server-side dependency. The three.js already bundled in the frontend does all
  decoration and unwrap work.

GLB migration is therefore progressive enhancement, not a rewrite.

## Data model

New nullable columns on `products` (one migration):

- `decor_glb_ref` (string, nullable) — optional authored GLB. When present it is the
  viewer/decal source; when absent the viewer/decal loads `model_file_ref` (the STL)
  directly and generates UVs client-side.
- `print_zone` (json, nullable) — single source of truth for both preview and
  production, in model space (STL convention, mm):

  ```json
  {
    "normal":  [x, y, z],
    "center":  [x, y, z],
    "up":      [x, y, z],
    "width_mm":  number,
    "height_mm": number
  }
  ```

`model_file_ref` is unchanged and remains the slice source.

## Components

### 1. Planar detection — `frontend/src/lib/planarDetect.ts` (new)

Pure function over a loaded `THREE.BufferGeometry`:

- Cluster triangles by normal direction (quantized), then find the largest connected
  coplanar region within the dominant cluster.
- Return the suggested zone: `{ normal, center, up, width_mm, height_mm }` derived
  from that region's oriented extent.
- Returns `null` when no meaningful flat region exists (fully curved parts) so callers
  can fall back to an admin-placed zone.

This replaces the bbox-min-axis logic currently inside `modelFaceSnapshot.ts`.

### 2. Admin zone editor — `frontend/src/components/Model3dZoneEditor.tsx` (new)

Mounted in the product detail admin page for `MODEL_3D` products:

- Renders the mesh (GLB if present, else STL) with orbit controls.
- On open, runs `planarDetect` to pre-fill a suggested zone; if detection returns
  null, prompts the admin to place one.
- Admin click-on-mesh raycasts to a surface point + normal to move the zone; drag
  handles resize (`width_mm` / `height_mm`); a rotate control sets `up`. A full-wrap
  item can have its zone placed on a curved patch.
- Saves `print_zone` via the product update endpoint.

### 3. Customer decal — updates to `ProductDesignerPage.tsx` + a decal renderer

Replaces the flat-snapshot backdrop flow:

- Load the decoration model (GLB or STL) into a live three.js scene, colored by the
  selected filament color (reuse `FILAMENT_HEX`).
- The existing fabric 2D `DesignerCanvas` continues to own logo bands, snapping,
  print-inset clamping, undo, and mm mapping — all reused. The designer is constrained
  to the `print_zone` bounds.
- The captured 2D artwork projects onto the mesh as a `THREE.DecalGeometry` positioned
  by `print_zone` for a real WYSIWYG preview in the chosen color.
- **Production print file:** render the decal region UV-flattened to a transparent PNG
  at print resolution, alongside the existing machine-readable mm placement record
  (`DesignerCanvas` capture layout). For a flat zone this reduces to today's mm math
  unchanged; for a wrapped zone it is the decal's UV unwrap.

### 4. Replace-model flow

Wire the admin product detail UI to the existing
`AdminCatalogueController::uploadModelFile`, and upgrade that endpoint to a true
replace:

- Accept `stl`, `3mf`, `obj`, **and `glb`**. A `glb` upload sets `decor_glb_ref`; a
  mesh upload sets `model_file_ref`.
- On an extension change, delete the orphaned previous file (today it can leave a stale
  `manual-{id}.stl` when the new upload is `.obj`).
- Reset `estimates_verified` and re-run the slicer gate (already done).
- **Invalidate `print_zone`** and re-run auto-detect after a mesh replace.
- Bust the face-render cache — add the model file version/mtime to the cache key in
  `modelFaceSnapshot.ts` (and the decal loader) so a replaced model never serves a
  stale render.

## Data flow

1. Admin uploads/replaces a mesh (and optionally a GLB) → server stores refs, clears
   verification, re-gates, invalidates `print_zone`.
2. Admin opens the zone editor → `planarDetect` suggests a zone → admin adjusts →
   `print_zone` saved.
3. Customer opens the designer → live mesh loads in filament color → 2D designer is
   constrained to `print_zone` → artwork previews as a decal on the mesh.
4. Customer captures → transparent PNG (UV-flattened decal region) + mm placement
   record persisted on the cart line, as today.
5. Nightly slicer sweep measures `est_grams` from the unchanged STL path.

## Error handling

- `planarDetect` returns null (curved part, no flat region) → admin must place the zone
  manually; the customer designer shows the admin-placed zone. No silent bad guess.
- No `print_zone` set on a published item → customer designer falls back to the neutral
  stage (as today) with the status spelled out; never the scraped marketing photo.
- GLB load failure → fall back to the STL mesh; decal still works via generated UVs.
- Model replace with a slicer-rejected mesh → printability signal surfaces via the
  existing gate; publish state reflects it.
- Orphaned-file deletion is best-effort and logged; a failure to delete never blocks
  the replace.

## Testing

- `planarDetect` unit tests: flat plaque (axis-aligned), angled face (non-axis normal),
  multi-face part (largest region wins), fully curved part (returns null).
- `print_zone` persistence + re-detect on mesh replace.
- Orphaned-file cleanup on extension change.
- Decal print-file mm mapping matches the current flat-path baseline for a flat zone.
- Slicer path regression: `est_grams` measurement unchanged after the migration.
- Replace endpoint accepts glb into `decor_glb_ref`; rejects unsupported extensions.

## Out of scope

- Server-side STL↔GLB conversion tooling (not needed; decal works from STL).
- Bulk conversion/migration of the existing STL catalogue.
- Multiple print zones per product (single zone only for now).
- Changes to the slicer, pricing, or procurement pipelines.
