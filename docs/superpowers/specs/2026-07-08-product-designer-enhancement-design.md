# Product Designer Enhancement — Design

**Date:** 2026-07-08
**Status:** Approved for planning
**Scope:** The customer customization page (`ProductDesignerPage`) and the
supporting designer components, plus the per-product minimum-order-quantity and
the upload-finished-look production fallback.

---

## 1. Context

The customization page (`frontend/src/pages/ProductDesignerPage.tsx`, "Design
studio") lets a buyer configure a product before adding it to the cart. Today it
supports three product classes:

- `CORE` / `SCRAPED_UV` — flat 2D items (many sourced from Shopee/Lazada). The
  buyer places a logo on a 2D fabric canvas (`DesignerCanvas`) over the product
  photo.
- `MODEL_3D` — 3D-printed (FDM) items, then UV-decorated. The buyer picks a
  filament colour, places a logo on a flattened 2D pad constrained to an
  admin/auto zone, and a live decal-on-real-mesh preview
  (`Model3dDecalPreview`) shows the logo on the actual model. On capture the
  decal is flattened to a UV print file.

Existing machinery worth reusing:

- `lib/planarDetect.ts::detectPrintZone` — auto-detects the largest flat
  printable face of a mesh; returns `null` for genuinely curved parts.
- `components/Model3dZoneEditor.tsx` — admin editor that already raycasts
  clicks onto the mesh and orients a zone quad (the "place on the model"
  mechanic already exists, for admins).
- `components/Model3dDecalPreview.tsx` — projects captured artwork as a decal
  on the real mesh and flattens it to a print file.
- Proof workflow — `Quote` → `PROOFING` → `Proof` (`SENT` →
  `CHANGES_REQUESTED` / `APPROVED`). "Production rejects/clarifies" is a proof
  moved to `CHANGES_REQUESTED` with notes.

### Problems being solved

1. **UI** — the studio is a narrow centred column, leaving large empty margins
   and scattered small cards (heavy whitespace).
2. **Name/text tool** — live text risks font-rendering mismatch in print.
3. **Placement fidelity** — buyers want to see and position their logo on the
   real product; cylindrical products (tumblers, bottles) are currently
   unsupported by the designer (flat-only detection).
4. **Escape hatch** — when the tool can't express what the buyer wants (or a
   product has no printable flat/round surface), there is no structured way to
   say "make it look like this" and let production confirm.

### Production hardware constraint

The floor runs **flatbed + rotary/cylindrical UV printers**. This defines what
is printable:

- **Flat face** → flatbed. Logo must sit on a flat surface.
- **Cylindrical wall** → rotary. Logo can wrap a round wall (developable
  surface), not compound curvature.
- **Freeform** (spheres, figurines, organic shapes) → not UV-printable; needs a
  human-reviewed fallback or an alternative process.

### Catalogue reality

Significant cylindrical share **and** heavy freeform/mixed share. So all three
producibility tiers are real, and the freeform fallback is a primary path, not
an edge case.

---

## 2. Goals / Non-goals

**Goals**

- Cut production rejections (preview matches producible reality).
- Boost buyer confidence (preview feels real).
- Keep build effort proportional (reuse existing auto-detect / decal / proof
  machinery; don't over-build flat polish).
- Fix the immediate UI whitespace/layout.

**Non-goals**

- Free "place anywhere on any surface" with no producibility constraint (it
  manufactures unprintable placements → more rejections).
- Compound-curvature UV printing (not physically supported).
- Replacing the live designer with a manual-upload-only flow.

---

## 3. Core model: producibility tiers

On the designer, classify each `MODEL_3D` product's surface once, then route to
the flow it can actually print:

**Placement is drag-directly-on-the-3D-model for every producible tier** — the
buyer drags the logo on the real mesh (realistic preview = the core confidence
goal). The logo is constrained to the producible surface (flat face or round
wall); dragging toward an unprintable spot snaps it back. What they drag is what
prints.

| Tier | Detection | Buyer flow | Print output |
|------|-----------|-----------|--------------|
| **1 · Flat** | `detectPrintZone` succeeds | Drag logo **on the model**, clamped to the flat face | Flatbed UV file (existing flatten) |
| **2 · Cylindrical** | new `detectCylinder` succeeds | Drag logo **on the model**, around (θ) + height, clamped to the round wall's extent | Rotary UV file (cylinder → flat unwrap) |
| **3 · Freeform** | neither detector succeeds | No live placement; **upload-finished-look fallback** | Human-proofed before print |

Classification order: try flat → try cylinder → else freeform. Admin can
override the detected surface (extends the existing zone editor).

2D products (`CORE` / `SCRAPED_UV`) keep the flat-photo canvas flow, unchanged
except for the shared UI redesign, text removal, MOQ, and the availability of
the upload-finished-look fallback.

---

## 4. The upload-finished-look fallback (option A)

**In-app designer is primary; the fallback is the escape hatch.** It appears in
three situations:

1. **Buyer feels off** — a "Upload finished look" mode toggle is available on
   every product's studio rail, so the buyer can always switch to it.
2. **Freeform products** — Tier 3 defaults to this mode (no live designer).
3. **Production rejects/clarifies** — a proof set to `CHANGES_REQUESTED` routes
   the buyer back to provide/adjust the finished-look reference.

**What the buyer provides (structured, to minimise rejections):**

- One or more **reference images** of the desired final look.
- Their **logo/artwork file**.
- **Placement notes** (free text, e.g. "centre of lid, ~4 cm wide").

**How it flows:** the line item is marked as buyer-uploaded intent (not a
ready-to-print file). It enters the normal quote → proof loop. Staff review,
then either issue a proof (`SENT`) they can produce, or request changes
(`CHANGES_REQUESTED`) with a note asking the buyer to clarify. Approved proof is
terminal and immutable (existing `ProofState` rule).

This reuses the entire existing proof/quote mechanism — no new review workflow.

---

## 5. Phases

Each phase is independently shippable. Priority order confirmed 1 → 2 → 3.

### Phase 1 — UI redesign + text removal + qty/MOQ + upload-finished-look

Frontend-weight, low risk, immediately covers the biggest catalogue gap
(freeform) via the fallback.

**5.1 Layout redesign (problem 1)**

- Widen the studio container; make the **live preview the hero** on the left
  (interactive 3D/decal preview for `MODEL_3D`; the canvas for 2D).
- Collapse the scattered cards into **one sticky control rail** on the right:
  mode toggle → filament colour (3D only) → logo upload/size → quantity →
  live price → primary CTA.
- Move delivery estimate + "need it by" into a **slim top bar** (was a large
  card).
- Preserve the mobile sticky action bar; the rail stacks under the preview on
  narrow viewports.
- Keep the existing 3D decal preview and (for zoned items) the 2D pad, switchable
  via a preview toggle (3D ⇄ 2D pad).

**5.2 Remove name/text (problem 2)**

- Delete the `TextTool`, `hasText` state, and the NAME/TEXT section from
  `DesignerCanvas`.
- Remove `has_text` from the price-estimate request and the personalisation-fee
  path; drop the `Customization.text` field usage in the designer. Buyers who
  want text upload it as part of their image.
- Server: the personalisation-fee input tied to `has_text` is removed from the
  designer's estimate call. (Confirm no other caller depends on it before
  deleting the server-side branch — leave the server field tolerant if other
  flows use it.)

**5.3 Adjustable quantity with per-product MOQ (new requirement)**

- New product field **`min_order_qty`** (integer, default `1`), **editable by
  superadmin** only — mirrors the existing per-product `price_override`
  superadmin pattern.
  - Migration adds the column; `Product` model + `ProductResource` (public) +
    `AdminProduct` resource expose it; admin product form (superadmin-gated)
    edits it.
- Designer quantity control changes from a fixed `Select` to an **adjustable
  number stepper/input**, with `min = product.min_order_qty` and initial value
  = `min_order_qty`. Below-min input is clamped with an inline message.
- Enforce MOQ server-side on quote creation/validation (`StoreQuoteRequest` /
  quote service) so the client control isn't the only guard.
- Price estimate + volume tiers respect the new minimum.

**5.4 Upload-finished-look fallback (problem 4 / section 4)**

- Add a **mode toggle** to the rail: `Design here` | `Upload finished look`.
- Fallback mode UI: reference-image dropzone(s), logo/artwork upload, placement
  notes textarea, and a submit that adds the line in "buyer-uploaded intent"
  state with copy explaining the team proofs it before printing.
- Data model: extend `Customization` with fallback fields, e.g.
  `mode?: 'designer' | 'buyer_uploaded'`, `reference_refs?: string[]`,
  `placement_notes?: string | null`. Reuse the existing `uploadArtwork` upload
  path + orphan-prune command for the reference files.
- Freeform (Tier 3) products open directly in this mode.
- Staff/production surface: show the reference images + notes on the line item
  in the production/quote views so a proof can be raised or changes requested.

### Phase 2 — Drag-the-logo-on-the-model (flat surfaces)

The realistic-preview core: the buyer positions the logo by dragging it on the
real 3D model, not only on a side 2D pad. Flat first because it reuses the most
existing machinery and de-risks the interaction before the harder round-surface
maths.

**Design principle — the 2D pad stays authoritative; the 3D view becomes a
second controller.** A flat face has a known print zone (`detectPrintZone` or
admin-set), so a point on the face maps to a `(u,v)` inside the zone — exactly
what a position on the 2D `DesignerCanvas` already is. Drag-on-model and the 2D
pad are therefore two views of ONE placement. We do NOT build a second capture
or print pipeline; we add an input method.

- **Single source of truth:** the fabric `DesignerCanvas` remains authoritative
  and keeps the entire capture pipeline (artwork PNG + layout + UV print file)
  unchanged. It exposes a small imperative API to read/set the active logo's
  placement — position + rotation in normalized zone coords (and the current
  size band, read-only from the 3D side).
- **Interactive preview:** `Model3dDecalPreview` gains pointer handlers. A drag
  on the flat face is raycast to the mesh, the hit projected into zone `(u,v)`
  (reusing the plane projection the admin `Model3dZoneEditor` already does), and
  written back via the canvas placement API. A rotate handle on the mesh sets
  the angle. Because both the 2D pad and the decal reflect the same fabric
  object, they stay in sync automatically — no duplicate state.
- **On-model interaction:** drag to MOVE (clamped to the flat face/zone; snaps
  back past the edge) + a rotate handle. Size stays on the existing S/M/L band
  selector — no resize-on-model, so the pricing bands are untouched.
- **Scope:** flat `MODEL_3D` items with a zone (detected or admin-set). No-zone
  items keep today's flow. The 2D pad remains available (toggle) as the alternate
  editor; neither is removed.
- **Capture output unchanged** — the same placement flattens to the same
  flatbed UV file.

**Main risks:** (1) exposing a clean placement API from the fabric canvas
without tangling its internals; (2) accurate raycast → zone `(u,v)` mapping
including rotation; (3) drag feel + clamping on the mesh (distinguishing an orbit
drag from a logo drag, as the admin editor already does with a move threshold).

### Phase 3 — Drag-on-model for round / cylindrical surfaces (Tier 2)

Same drag-on-model experience, extended to round walls. The real engineering.
Additive; flat + fallback flows untouched.

- **`lib/cylinderDetect.ts::detectCylinder(geometry)`** — detect the dominant
  cylindrical surface: axis, radius, height range, angular extent. Return
  `null` when no dominant cylinder (falls through to freeform).
- **Surface model** — generalise the flat `PrintZone` into a discriminated
  `PrintSurface`:
  - `{ kind: 'flat', ...PrintZone }` (existing)
  - `{ kind: 'cylinder', axis, radius_mm, center, height_mm, angle_center,
    angle_extent }`
  - Migration/serialisation updated; existing flat zones map to
    `kind: 'flat'` (backward compatible).
- **Placement** — the buyer drags the logo on the round wall (raycast → wrap
  onto the cylinder), around (θ) and along height, clamped to the detected
  extent; the decal follows the drag on the real mesh.
- **Print file** — unwrap the cylindrical region to a flat rectangle
  (θ → x arc-length, height → y) at the rotary's print resolution; store as
  `print_file_ref` like the flat path. **Validate the unwrap with a real test
  print early** (a spike) before building the full UI.
- **Admin editor** — extend `Model3dZoneEditor` to detect + edit a cylindrical
  surface (radius/height/angular extent controls).
- **Metadata** — carry cylindrical placement in mm (arc length, height) in the
  captured layout for production.

---

## 6. Data model summary

- **Product**: `+ min_order_qty INT DEFAULT 1` (superadmin-editable).
- **Customization** (client + line-item JSON):
  - `+ mode?: 'designer' | 'buyer_uploaded'`
  - `+ reference_refs?: string[]`
  - `+ placement_notes?: string | null`
  - remove designer reliance on `text`
- **Print surface** (Phase 2): `PrintZone` → `PrintSurface` discriminated union
  (`flat` | `cylinder`), backward compatible.

---

## 7. Testing

- **Phase 1**: MOQ clamp (client + server reject below-min); text tool removed
  (no `has_text` in estimate payload); fallback line carries `mode`,
  `reference_refs`, `placement_notes`; freeform product opens in fallback mode;
  layout renders responsively (existing component tests updated).
- **Phase 2**: raycast drag-on-model clamps to the flat face; capture output
  matches the existing flat flatten for the same placement; decal follows the
  drag.
- **Phase 3**: `detectCylinder` unit tests against known geometries (tumbler
  cylinder detected; flat plaque + sphere return `null`); unwrap round-trips a
  known placement to expected print-space coordinates; flat path unchanged.

Follow the repo's existing Vitest (frontend) + PHPUnit (backend) patterns.

---

## 8. Risks / open items

- **MOQ granularity** assumed **per-product** (matches `price_override`). Confirm
  no global default is also wanted.
- **`has_text` server branch** — verify no non-designer caller depends on the
  personalisation fee before removing the server path; keep the field tolerant
  if so.
- **Cylinder detection robustness** — real meshes (chamfers, handles, threads)
  may fragment the cylinder; tune the area/normal thresholds like
  `detectPrintZone` did, and fall through to freeform when ambiguous.
- **Unwrap accuracy** — the rotary print file mapping must match the machine's
  expected input orientation/resolution; validate with a real test print early
  in Phase 2.
- **Fallback abuse** — buyers defaulting to "upload finished look" on printable
  products shifts load to production. Mitigate with structured fields and by
  keeping the live designer the primary, pre-selected mode.
