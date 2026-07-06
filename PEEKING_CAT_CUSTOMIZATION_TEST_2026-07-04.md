# Customization/Personalization Test — Peeking Cat Bookmark (real 3D model)

Focused live test of the designer on a real Thingiverse-sourced 3D model
(`peeking-cat-bookmark`, id 73, class MODEL_3D, FDM, licence CC via creator
credit "sameenkarim", `has_model:true`). Run against SPA :5173 / API :8000,
first-time-user perspective, desktop. Complements the C-row checklist and the
Pass 2 findings (kept).

## What works

- Designer is public (no account), loads the model product, live price shown.
- Logo upload (PNG) accepted; drag + resize + snap guides function.
- Size tiers price correctly for a 3D product: **S 1.74 / M 2.14 / L 2.64 per unit** (surcharge 0 / 0.40 / 0.90 confirmed after ruling out a debounce read-race). 3D landed cost = filament grams + machine time.
- Filament colour (Black/White/Grey) is captured into the FA.
- "Use this design" captures; cart line stores `{artwork_ref, filament_color:"Grey", logo_size:"L"}`, class MODEL_3D, print_method FDM. Colour + size + artwork all persisted.

## Findings

### G1 — The design surface is the raw scraped marketing photo, not the product
The designer canvas background is the Thingiverse listing photo — a black cat
bookmark **lying on an open book** (`img src = resize.thingiverse.com/...JPG`).
You place your logo on a photo that includes the book pages, table, and lighting,
not a clean product render or the model. The dashed "Print area" rectangle spans
the **book text on the left third**, not just the bookmark. This is the v1
scraped-image tech debt (Pass 1 B8) surfacing as the *interactive design
surface*, which is far worse than a catalogue thumbnail.
**Severity:** major. **Repro:** open `/design/peeking-cat-bookmark`, observe the
photo + print-area overlay covering the book background.

### G2 — Logo is placed on a 2D photo, never on the 3D model (confirmed root cause)
**This is the core defect.** The model exists and works — `has_model:true`, the
STL streams from `/api/catalogue/{key}/model`, and the product page renders it in
three.js. But the *designer* never touches the model. It places the logo on a
flat 2D image instead of the 3D surface, which is wrong.

Root cause, exact path:
- `ModelViewer` (three.js `STLLoader`, the real 3D renderer) is imported **only**
  in `ProductDetailPage.tsx:18,225` — the product page. It is never used in the
  designer.
- The designer (`ProductDesignerPage.tsx:287-293`) renders `DesignerCanvas`
  **unconditionally for every product class**, passing
  `backgroundUrl={product.image_url}` — the scraped 2D listing photo.
- `DesignerCanvas` draws that URL as a plain `<img>` backdrop
  (`DesignerCanvas.tsx:443-445`) behind a flat Fabric canvas. The logo is placed
  in 2D pixel space over that photo.
- For MODEL_3D the only 3D-specific addition is `Model3dPersonalizer`
  (`ProductDesignerPage.tsx:286`), which contributes a filament-colour select and
  nothing about placement.

So the buyer positions a logo on a photograph of the cat lying on a book, and the
captured coordinates are pixels on that photo — with no relationship to the STL
geometry, the printable face, or any UV coordinate on the physical part. C8
("placement on 3D model maps to surface without distortion") is unimplemented for
the exact class that requires it. The code comments
(`ProductDesignerPage.tsx:45-47, 283-285`) show this is a deliberate v1 shortcut
("UV-decorated on its flat face — the placement mockup is a producible production
step") — but the surface shown is the raw marketing photo, not a flat-face
render, so the mockup is not producible as-is.

**Severity:** major (blocker for the 3D track). **Fix direction:** for MODEL_3D,
drive placement off the actual model — reuse the existing `ModelViewer`/STL
pipeline to project the logo as a decal onto the 3D surface (or, minimally, onto a
clean render of the specific printable face with real UV/mm coordinates), and
capture placement in model space, not photo pixels. The 2D-photo path is
acceptable only for CORE/scraped-UV blanks that genuinely have one flat print face.
**Repro:** open `/design/peeking-cat-bookmark` → only two Fabric canvases, no
WebGL; the STL loads on the product page but not here.

### G3 — Filament colour has zero effect on the preview
Switching Black → White → Grey leaves the design canvas **pixel-identical**
(full-canvas hash unchanged across all three). A buyer selecting White filament
still sees the black-cat photo. The colour is captured for production, but the
"live preview" misrepresents the chosen product. The UI leans on "the formal
proof you approve shows the exact result" to cover a preview that is simply wrong.
**Severity:** major. **Repro:** change filament select; canvas hash constant.

### G4 — Track/method contradiction: FDM 3D part sold with "UV-printed logo on a flat face"
Product is `print_method: FDM` (3D-fabricated). Designer copy: "Your item is
3D-printed in this colour, then your design is UV-printed onto its flat face."
A peeking-cat bookmark is an irregular 3D shape with no obvious flat face, and
the placement tool is a rectangle on a photo. Spec separates the tracks (3D =
fabricate from model file; UV = decorate a blank) — this product fuses them with
no designer support for *where* on the 3D part the logo actually lands.
**Severity:** major (buyer can approve a placement that is physically
unrealizable on the printed part). **Repro:** designer copy vs `print_method:FDM`.

### G5 — Logo can be dragged out of the rendered area and vanish (repro of C7 on 3D)
Dragging the placed logo toward the canvas edge pushed it entirely out of view:
canvas shows zero logo pixels while the "1 element" badge and the selection
toolbar persist, and the price still reflects a logo. The at-add clamp is not
re-applied on move, so the buyer can save/checkout a design whose logo is off the
visible product (and, per G1, the print area even includes off-product
background). **Severity:** major. **Repro:** add logo, drag to edge → element
count 1, no visible logo, price unchanged.

### G6 — "Best contrast" guidance contradicted by the Black default
Copy: "Light colours give the best contrast for UV-printed logos." Default
filament is **Black** (darkest, worst contrast). The default nudges first-time
buyers into the exact choice the copy warns against, and — per G3 — they get no
preview feedback to notice. **Severity:** minor. **Repro:** filament select
defaults to Black.

### G7 — UV decoration on a 3D product isn't costed (only the size surcharge)
`PricingService::unitPrice` skips the per-unit print fee (`print_cost.per_unit`)
for MODEL_3D (machine time is already in landed cost). But this product *also*
gets a UV-printed logo ("UV-printed onto its flat face"), and the UV pass
(ink/labour/setup) is not priced for MODEL_3D — only the flat customization fee +
setup + size surcharge apply. A decorated 3D item under-recovers the UV work vs a
UV-track blank. **Severity:** minor (revenue leakage, not a break).
**Repro:** `PricingService.php:57-62` gates print-per-unit on non-Model3d.

### G8 — Model3D published and priced with null dimensions
Product 73 has `dimensions: null` (Thingiverse scrape didn't supply them) yet is
PUBLISHED and orderable. Delivery is weight-driven; a missing physical footprint
weakens the shipping estimate. The completeness gate requires dims+weight for
scraped items; the MODEL_3D path evidently publishes without them.
**Severity:** minor. **Repro:** `/api/catalogue/peeking-cat-bookmark` →
`dimensions:null`, still purchasable.

## Bottom line

For this real 3D model, the personalization experience is effectively theater:
the buyer decorates a scraped photo of a cat-on-a-book, the chosen filament colour
doesn't render, the logo can sit on the book background or vanish off-canvas, and
nothing maps to the actual 3D part they'll receive. The FA does capture colour +
size + artwork, and pricing is arithmetically correct — but "what you see" and
"what gets made" are disconnected, and the app leans entirely on the manual proof
step to catch it. For a make-to-order 3D SKU that is a launch risk: the proof
becomes mandatory manual rework on every order, which is exactly the bottleneck
the spec's §7 open-decision flags.
