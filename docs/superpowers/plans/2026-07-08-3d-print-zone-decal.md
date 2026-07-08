# 3D Print-Zone Detection, Decal Preview & Model Replace — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the bbox-min-axis "flat face" guess with real planar detection + an admin-marked print zone, preview logos as a real decal on the actual 3D mesh, and let admins replace a product's model file.

**Architecture:** STL/3MF stays the canonical model (feeds the untouched PrusaSlicer path). An optional authored GLB is additive for material realism. A per-product `print_zone` (model-space normal + center + size) is the single source of truth for both the customer preview and the production print file. `THREE.DecalGeometry` supplies the decal's own UVs, so STL alone suffices — no server-side 3D conversion, no new dependency.

**Tech Stack:** Laravel 11 (Pest tests), React + TypeScript (Vitest), three.js (already bundled), fabric v6.

**Spec:** `docs/superpowers/specs/2026-07-08-3d-print-zone-decal-design.md`

---

## File Structure

**Backend**
- `database/migrations/2026_07_08_000001_add_print_zone_to_products.php` (create) — `decor_glb_ref` string + `print_zone` json columns.
- `app/Models/Product.php` (modify) — add both to `$fillable`; cast `print_zone` to `array`.
- `app/Http/Controllers/AdminCatalogueController.php` (modify) — `uploadModelFile` → true replace (glb, orphan cleanup, zone invalidation); new `savePrintZone`; new `adminModel` stream.
- `routes/api.php` (modify) — routes for `savePrintZone` + `adminModel`.

**Frontend**
- `frontend/src/lib/planarDetect.ts` (create) — pure detector over a `THREE.BufferGeometry`.
- `frontend/src/lib/planarDetect.test.ts` (create) — unit tests.
- `frontend/src/lib/printZone.ts` (create) — shared `PrintZone` type + helpers (basis from normal+up, mm↔fraction).
- `frontend/src/lib/modelFaceSnapshot.ts` (modify) — use `planarDetect`; add model-version cache-bust.
- `frontend/src/lib/modelDecal.ts` (create) — build a `DecalGeometry` from a `PrintZone` + artwork texture; render UV-flattened print PNG.
- `frontend/src/components/Model3dZoneEditor.tsx` (create) — admin 3D zone marker.
- `frontend/src/components/Model3dDecalPreview.tsx` (create) — live mesh + decal for the customer designer.
- `frontend/src/pages/ProductDesignerPage.tsx` (modify) — constrain designer to `print_zone`, mount decal preview.
- `frontend/src/pages/ProductAdminDetailPage.tsx` (modify) — model-replace panel + zone editor for MODEL_3D.
- `frontend/src/types.ts` (modify) — `print_zone`, `decor_glb_ref`, `has_glb` on product types.

---

## Task 1: Migration + model casts

**Files:**
- Create: `database/migrations/2026_07_08_000001_add_print_zone_to_products.php`
- Modify: `app/Models/Product.php` (`$fillable` ~line 36-60, `$casts` ~line 75)
- Test: `tests/Feature/Model3dPrintZoneTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\Product;

it('persists print_zone as an array and decor_glb_ref', function (): void {
    $zone = [
        'normal' => [0.0, 0.0, 1.0],
        'center' => [0.0, 0.0, 5.0],
        'up' => [0.0, 1.0, 0.0],
        'width_mm' => 40.0,
        'height_mm' => 30.0,
    ];

    $p = Product::factory()->create([
        'class' => 'MODEL_3D',
        'print_zone' => $zone,
        'decor_glb_ref' => 'models3d/decor-1.glb',
    ]);

    $fresh = $p->fresh();
    expect($fresh->print_zone)->toBe($zone)
        ->and($fresh->decor_glb_ref)->toBe('models3d/decor-1.glb');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=Model3dPrintZoneTest`
Expected: FAIL — column `print_zone` does not exist.

- [ ] **Step 3: Write the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Decoration geometry for MODEL_3D products. `print_zone` is the single source
 * of truth for both the customer decal preview and the production print file:
 * a model-space normal + center + size (mm) locating the printable surface.
 * `decor_glb_ref` is an optional authored GLB for material realism; when absent
 * the viewer decorates the canonical STL directly. Both nullable — the STL
 * `model_file_ref` remains the slicer source.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('decor_glb_ref')->nullable()->after('model_file_ref');
            $table->json('print_zone')->nullable()->after('decor_glb_ref');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['decor_glb_ref', 'print_zone']);
        });
    }
};
```

- [ ] **Step 4: Add to `$fillable` and `$casts` in `app/Models/Product.php`**

In the `$fillable` array (after `'model_file_ref'`):

```php
        'model_file_ref',
        'decor_glb_ref',
        'print_zone',
```

In the `$casts`/`casts()` block (beside `'dimensions' => 'array'`):

```php
            'dimensions' => 'array',
            'print_zone' => 'array',
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=Model3dPrintZoneTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_08_000001_add_print_zone_to_products.php app/Models/Product.php tests/Feature/Model3dPrintZoneTest.php
git commit -m "feat(3d): add print_zone + decor_glb_ref columns"
```

---

## Task 2: Staff model-stream endpoint

The public `CatalogueController::model` requires `publish_state->isPublic()`, so admins can't preview an unpublished mesh in the zone editor. Add a staff-only stream that serves the mesh (or GLB) for any product.

**Files:**
- Modify: `app/Http/Controllers/AdminCatalogueController.php`
- Modify: `routes/api.php` (after line 109)
- Test: `tests/Feature/Model3dPrintZoneTest.php`

- [ ] **Step 1: Write the failing test**

```php
it('streams a model file to staff regardless of publish state', function (): void {
    Illuminate\Support\Facades\Storage::fake('local');
    Illuminate\Support\Facades\Storage::disk('local')->put('models3d/manual-7.stl', 'solid x');

    $product = Product::factory()->create([
        'id' => 7,
        'class' => 'MODEL_3D',
        'publish_state' => 'PENDING',
        'model_file_ref' => 'models3d/manual-7.stl',
    ]);

    $staff = App\Models\User::factory()->create(['role' => 'staff']);

    $this->actingAs($staff)
        ->get("/api/admin/products/{$product->id}/model?kind=mesh")
        ->assertOk()
        ->assertHeader('content-type', 'application/octet-stream');
});

it('forbids the staff model stream to non-staff', function (): void {
    $product = Product::factory()->create(['class' => 'MODEL_3D']);
    $buyer = App\Models\User::factory()->create(['role' => 'buyer']);

    $this->actingAs($buyer)
        ->get("/api/admin/products/{$product->id}/model?kind=mesh")
        ->assertForbidden();
});
```

Confirm the role values (`staff`, `buyer`, `superadmin`) against `App\Models\User` / the user factory before running; adjust if the project uses different role strings.

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=Model3dPrintZoneTest`
Expected: FAIL — route not defined (404).

- [ ] **Step 3: Add the `adminModel` method** to `AdminCatalogueController` (add `use Illuminate\Support\Facades\Storage;` and `use Symfony\Component\HttpFoundation\StreamedResponse;` at the top if absent):

```php
    /**
     * Staff-only model stream for the admin zone editor + decal preview, served
     * for ANY publish state (the public CatalogueController::model requires a
     * public product). `kind=glb` serves the authored decoration GLB when set;
     * anything else serves the canonical mesh (STL/3MF/OBJ).
     */
    public function adminModel(Request $request, Product $product): StreamedResponse|JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);

        $kind = $request->string('kind')->toString();
        $ref = $kind === 'glb'
            ? (string) ($product->decor_glb_ref ?? '')
            : (string) ($product->model_file_ref ?? '');

        if ($ref === '' || str_starts_with($ref, 'http') || ! Storage::disk('local')->exists($ref)) {
            return response()->json(['message' => 'Model not available.'], 404);
        }

        return Storage::disk('local')->response($ref, basename($ref), [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'no-store',
        ]);
    }
```

- [ ] **Step 4: Register the route** in `routes/api.php` after line 109:

```php
    Route::post('/admin/products/{product}/model-file', [AdminCatalogueController::class, 'uploadModelFile']);
    Route::get('/admin/products/{product}/model', [AdminCatalogueController::class, 'adminModel']);
```

- [ ] **Step 5: Run to verify it passes**

Run: `php artisan test --filter=Model3dPrintZoneTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/AdminCatalogueController.php routes/api.php tests/Feature/Model3dPrintZoneTest.php
git commit -m "feat(3d): staff model-stream endpoint for admin editor"
```

---

## Task 3: uploadModelFile → true replace + savePrintZone

Upgrade `uploadModelFile` to accept GLB, clean up an orphaned old file on extension change, and invalidate `print_zone` on a mesh replace. Add `savePrintZone`.

**Files:**
- Modify: `app/Http/Controllers/AdminCatalogueController.php` (`uploadModelFile` ~line 155)
- Modify: `routes/api.php`
- Test: `tests/Feature/Model3dPrintZoneTest.php`

- [ ] **Step 1: Write the failing tests**

```php
it('deletes the orphaned old mesh when the extension changes', function (): void {
    Illuminate\Support\Facades\Storage::fake('local');
    $disk = Illuminate\Support\Facades\Storage::disk('local');
    $disk->put('models3d/manual-9.stl', 'old');

    $product = Product::factory()->create([
        'id' => 9,
        'class' => 'MODEL_3D',
        'model_file_ref' => 'models3d/manual-9.stl',
        'print_zone' => ['normal' => [0, 0, 1], 'center' => [0, 0, 0], 'up' => [0, 1, 0], 'width_mm' => 10, 'height_mm' => 10],
    ]);
    $staff = App\Models\User::factory()->create(['role' => 'staff']);

    $file = Illuminate\Http\UploadedFile::fake()->create('part.obj', 4, 'text/plain');
    $this->actingAs($staff)
        ->post("/api/admin/products/{$product->id}/model-file", ['file' => $file])
        ->assertOk();

    expect($disk->exists('models3d/manual-9.stl'))->toBeFalse();
    expect($disk->exists('models3d/manual-9.obj'))->toBeTrue();
    // A mesh replace invalidates the old zone — geometry changed.
    expect($product->fresh()->print_zone)->toBeNull();
});

it('stores an uploaded glb into decor_glb_ref and keeps the mesh + zone', function (): void {
    Illuminate\Support\Facades\Storage::fake('local');
    $product = Product::factory()->create([
        'id' => 11,
        'class' => 'MODEL_3D',
        'model_file_ref' => 'models3d/manual-11.stl',
        'print_zone' => ['normal' => [0, 0, 1], 'center' => [0, 0, 0], 'up' => [0, 1, 0], 'width_mm' => 10, 'height_mm' => 10],
    ]);
    $staff = App\Models\User::factory()->create(['role' => 'staff']);

    $file = Illuminate\Http\UploadedFile::fake()->create('decor.glb', 8, 'model/gltf-binary');
    $this->actingAs($staff)
        ->post("/api/admin/products/{$product->id}/model-file", ['file' => $file])
        ->assertOk();

    $fresh = $product->fresh();
    expect($fresh->decor_glb_ref)->toBe('models3d/decor-11.glb');
    // GLB is display-only: canonical mesh + existing zone untouched.
    expect($fresh->model_file_ref)->toBe('models3d/manual-11.stl');
    expect($fresh->print_zone)->not->toBeNull();
});

it('saves a print zone for a MODEL_3D product', function (): void {
    $product = Product::factory()->create(['class' => 'MODEL_3D']);
    $staff = App\Models\User::factory()->create(['role' => 'staff']);
    $zone = ['normal' => [0, 0, 1], 'center' => [1, 2, 3], 'up' => [0, 1, 0], 'width_mm' => 42.5, 'height_mm' => 20];

    $this->actingAs($staff)
        ->postJson("/api/admin/products/{$product->id}/print-zone", ['print_zone' => $zone])
        ->assertOk();

    expect($product->fresh()->print_zone)->toBe($zone);
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test --filter=Model3dPrintZoneTest`
Expected: FAIL — glb rejected / print-zone route missing / orphan not deleted.

- [ ] **Step 3: Rewrite `uploadModelFile`** (replace the body from the `$extension` check through `save()`):

```php
        $upload = $request->file('file');
        $extension = strtolower((string) $upload->getClientOriginalExtension());

        $meshExts = ['stl', '3mf', 'obj'];
        $isGlb = $extension === 'glb';

        if (! $isGlb && ! in_array($extension, $meshExts, true)) {
            return response()->json(['message' => 'Model file must be .stl, .3mf, .obj or .glb.'], 422);
        }

        if ($isGlb) {
            // GLB is display-only: replace the decoration model, leave the
            // canonical mesh + slicer estimates + zone untouched.
            $old = (string) ($product->decor_glb_ref ?? '');
            $path = $upload->storeAs('models3d', "decor-{$product->id}.glb", 'local');
            if ($old !== '' && $old !== $path && Storage::disk('local')->exists($old)) {
                Storage::disk('local')->delete($old);
            }
            $product->decor_glb_ref = $path;
            $product->save();

            return response()->json([
                'publish_state' => $product->publish_state->value,
                'model_file_ref' => $product->model_file_ref,
                'decor_glb_ref' => $product->decor_glb_ref,
            ]);
        }

        // Mesh replace: new geometry invalidates the slicer measurement AND any
        // marked print zone (the surface it referenced may no longer exist).
        $old = (string) ($product->model_file_ref ?? '');
        $path = $upload->storeAs('models3d', "manual-{$product->id}.{$extension}", 'local');
        if ($old !== '' && $old !== $path && ! str_starts_with($old, 'http') && Storage::disk('local')->exists($old)) {
            Storage::disk('local')->delete($old);
        }

        $product->model_file_ref = $path;
        $product->is_printable = true;
        $product->estimates_verified = false;
        $product->print_zone = null;
        $product->save();

        $product = $this->model3d->regate($product);

        return response()->json([
            'publish_state' => $product->publish_state->value,
            'model_file_ref' => $product->model_file_ref,
            'decor_glb_ref' => $product->decor_glb_ref,
        ]);
```

Also raise the upload cap to accommodate GLB textures — change `'file' => ['required', 'file', 'max:102400']` if GLB assets exceed 100 MB; keep 100 MB otherwise.

- [ ] **Step 4: Add `savePrintZone`** to the controller:

```php
    /**
     * Persist the admin-marked (or auto-detected) print zone for a MODEL_3D
     * product. Model-space normal + center + up + size (mm); the single source
     * of truth for the customer decal preview and the production print file.
     */
    public function savePrintZone(Request $request, Product $product): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);

        if ($product->class !== ProductClass::Model3d) {
            return response()->json(['message' => 'Only MODEL_3D products carry a print zone.'], 422);
        }

        $validated = $request->validate([
            'print_zone' => ['required', 'array'],
            'print_zone.normal' => ['required', 'array', 'size:3'],
            'print_zone.normal.*' => ['required', 'numeric'],
            'print_zone.center' => ['required', 'array', 'size:3'],
            'print_zone.center.*' => ['required', 'numeric'],
            'print_zone.up' => ['required', 'array', 'size:3'],
            'print_zone.up.*' => ['required', 'numeric'],
            'print_zone.width_mm' => ['required', 'numeric', 'gt:0'],
            'print_zone.height_mm' => ['required', 'numeric', 'gt:0'],
        ]);

        $product->print_zone = $validated['print_zone'];
        $product->save();

        return response()->json(['print_zone' => $product->print_zone]);
    }
```

- [ ] **Step 5: Register the route** in `routes/api.php` beside the other admin product routes:

```php
    Route::post('/admin/products/{product}/print-zone', [AdminCatalogueController::class, 'savePrintZone']);
```

- [ ] **Step 6: Run to verify they pass**

Run: `php artisan test --filter=Model3dPrintZoneTest`
Expected: PASS (all).

- [ ] **Step 7: Include `print_zone` + `has_glb` in the admin product `show` payload.** Locate `AdminProductController::show` (`app/Http/Controllers/AdminProductController.php` ~line 560 where `'dimensions' => $product->dimensions`) and add to that response array:

```php
            'dimensions' => $product->dimensions,
            'print_zone' => $product->print_zone,
            'has_model' => $product->model_file_ref !== null && ! str_starts_with((string) $product->model_file_ref, 'http'),
            'has_glb' => $product->decor_glb_ref !== null,
```

Verify against the actual response shape in that method; match its key style.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/AdminCatalogueController.php app/Http/Controllers/AdminProductController.php routes/api.php tests/Feature/Model3dPrintZoneTest.php
git commit -m "feat(3d): true model replace (glb, orphan cleanup) + savePrintZone"
```

---

## Task 4: Planar detector

Pure function over a loaded geometry. Cluster triangles by quantized normal, pick the dominant cluster, compute its oriented extent → a `PrintZone`. Returns null when no meaningful flat region exists.

**Files:**
- Create: `frontend/src/lib/printZone.ts`
- Create: `frontend/src/lib/planarDetect.ts`
- Test: `frontend/src/lib/planarDetect.test.ts`

- [ ] **Step 1: Write `printZone.ts` (shared type + basis helper)**

```ts
import * as THREE from 'three';

/** Model-space print zone: where + how big the decoration surface is (mm). */
export interface PrintZone {
  normal: [number, number, number];
  center: [number, number, number];
  up: [number, number, number];
  width_mm: number;
  height_mm: number;
}

/**
 * Orthonormal basis for a zone: n (outward normal), u (surface "right"),
 * v (surface "up"). Used to orient the decal/camera and to map mm↔surface.
 */
export function zoneBasis(zone: PrintZone): { n: THREE.Vector3; u: THREE.Vector3; v: THREE.Vector3 } {
  const n = new THREE.Vector3(...zone.normal).normalize();
  let up = new THREE.Vector3(...zone.up);
  // Re-orthogonalise up against n (admin input may be slightly off-plane).
  up = up.sub(n.clone().multiplyScalar(up.dot(n)));
  if (up.lengthSq() < 1e-6) {
    // Degenerate up — pick any vector not parallel to n.
    up = Math.abs(n.x) < 0.9 ? new THREE.Vector3(1, 0, 0) : new THREE.Vector3(0, 1, 0);
    up = up.sub(n.clone().multiplyScalar(up.dot(n)));
  }
  const v = up.normalize();
  const u = new THREE.Vector3().crossVectors(v, n).normalize();
  return { n, u, v };
}
```

- [ ] **Step 2: Write the failing tests** (`planarDetect.test.ts`)

```ts
import { describe, expect, it } from 'vitest';
import * as THREE from 'three';
import { detectPrintZone } from './planarDetect';

// A flat plaque: 60 (x) × 40 (y) × 4 (z) box. Largest flat faces are the two
// z-facing 60×40 planes; the detector should return one of them.
function boxGeometry(w: number, h: number, d: number): THREE.BufferGeometry {
  return new THREE.BoxGeometry(w, h, d).toNonIndexed();
}

describe('detectPrintZone', () => {
  it('finds the largest flat face of an axis-aligned plaque', () => {
    const zone = detectPrintZone(boxGeometry(60, 40, 4));
    expect(zone).not.toBeNull();
    // Normal is ±Z (the 60×40 face), not ±X/±Y.
    const [nx, ny, nz] = zone!.normal;
    expect(Math.abs(nz)).toBeGreaterThan(0.9);
    expect(Math.abs(nx)).toBeLessThan(0.1);
    expect(Math.abs(ny)).toBeLessThan(0.1);
    // Extent matches the 60×40 face (order-independent).
    const dims = [zone!.width_mm, zone!.height_mm].sort((a, b) => a - b);
    expect(dims[0]).toBeCloseTo(40, 0);
    expect(dims[1]).toBeCloseTo(60, 0);
  });

  it('detects a non-axis-aligned flat face', () => {
    const geo = boxGeometry(60, 40, 4);
    geo.rotateY(Math.PI / 5); // tilt so the flat face is off-axis
    const zone = detectPrintZone(geo);
    expect(zone).not.toBeNull();
    // Face area preserved regardless of orientation.
    const dims = [zone!.width_mm, zone!.height_mm].sort((a, b) => a - b);
    expect(dims[0]).toBeCloseTo(40, 0);
    expect(dims[1]).toBeCloseTo(60, 0);
  });

  it('returns null for a fully curved part with no flat region', () => {
    const zone = detectPrintZone(new THREE.SphereGeometry(20, 16, 12).toNonIndexed());
    expect(zone).toBeNull();
  });
});
```

- [ ] **Step 3: Run to verify they fail**

Run: `cd frontend && npx vitest run src/lib/planarDetect.test.ts`
Expected: FAIL — `detectPrintZone` not defined.

- [ ] **Step 4: Implement `planarDetect.ts`**

```ts
import * as THREE from 'three';
import type { PrintZone } from './printZone';

/**
 * Detect the largest flat printable face of a mesh. Groups triangles by
 * quantized face normal, sums per-group area weighted by centroid proximity,
 * and returns the dominant group's oriented bounds as a PrintZone (model mm).
 *
 * Returns null when the flattest group covers too little of the surface (a
 * genuinely curved part), so callers fall back to an admin-placed zone rather
 * than inventing a face that doesn't exist.
 */
export function detectPrintZone(
  geometry: THREE.BufferGeometry,
  opts: { minAreaFraction?: number; normalBins?: number } = {},
): PrintZone | null {
  const minAreaFraction = opts.minAreaFraction ?? 0.15;
  const bins = opts.normalBins ?? 12; // quantization granularity per axis

  const geo = geometry.index ? geometry.toNonIndexed() : geometry;
  const pos = geo.getAttribute('position');
  const triCount = pos.count / 3;
  if (triCount < 1) return null;

  const a = new THREE.Vector3();
  const b = new THREE.Vector3();
  const c = new THREE.Vector3();
  const ab = new THREE.Vector3();
  const ac = new THREE.Vector3();
  const faceNormal = new THREE.Vector3();

  type Group = { normal: THREE.Vector3; area: number; centroid: THREE.Vector3; tris: number[] };
  const groups = new Map<string, Group>();
  let totalArea = 0;

  const key = (n: THREE.Vector3): string =>
    `${Math.round(n.x * bins)}|${Math.round(n.y * bins)}|${Math.round(n.z * bins)}`;

  for (let t = 0; t < triCount; t++) {
    a.fromBufferAttribute(pos, t * 3);
    b.fromBufferAttribute(pos, t * 3 + 1);
    c.fromBufferAttribute(pos, t * 3 + 2);
    ab.subVectors(b, a);
    ac.subVectors(c, a);
    faceNormal.crossVectors(ab, ac);
    const area = faceNormal.length() * 0.5;
    if (area < 1e-9) continue;
    faceNormal.normalize();
    totalArea += area;

    const k = key(faceNormal);
    let g = groups.get(k);
    if (!g) {
      g = { normal: faceNormal.clone(), area: 0, centroid: new THREE.Vector3(), tris: [] };
      groups.set(k, g);
    }
    g.area += area;
    g.centroid.add(a.clone().add(b).add(c).multiplyScalar(area / 3));
    g.tris.push(t);
  }

  if (totalArea <= 0) return null;

  let best: Group | null = null;
  for (const g of groups.values()) {
    if (!best || g.area > best.area) best = g;
  }
  if (!best || best.area / totalArea < minAreaFraction) return null;

  best.centroid.multiplyScalar(1 / best.area);

  // Oriented extent of the dominant group: build a basis from its normal and
  // measure the spread of its triangle vertices in-plane.
  const n = best.normal.clone().normalize();
  let up = Math.abs(n.y) < 0.9 ? new THREE.Vector3(0, 1, 0) : new THREE.Vector3(1, 0, 0);
  up = up.sub(n.clone().multiplyScalar(up.dot(n))).normalize();
  const right = new THREE.Vector3().crossVectors(up, n).normalize();

  let minU = Infinity, maxU = -Infinity, minV = Infinity, maxV = -Infinity;
  const p = new THREE.Vector3();
  for (const t of best.tris) {
    for (let i = 0; i < 3; i++) {
      p.fromBufferAttribute(pos, t * 3 + i).sub(best.centroid);
      const du = p.dot(right);
      const dv = p.dot(up);
      if (du < minU) minU = du;
      if (du > maxU) maxU = du;
      if (dv < minV) minV = dv;
      if (dv > maxV) maxV = dv;
    }
  }

  const width = maxU - minU;
  const height = maxV - minV;
  if (width < 1e-6 || height < 1e-6) return null;

  // Center the zone on the mid-point of the measured span (not the area
  // centroid, which skews toward denser triangulation).
  const center = best.centroid.clone()
    .add(right.clone().multiplyScalar((minU + maxU) / 2))
    .add(up.clone().multiplyScalar((minV + maxV) / 2));

  return {
    normal: [n.x, n.y, n.z],
    center: [center.x, center.y, center.z],
    up: [up.x, up.y, up.z],
    width_mm: width,
    height_mm: height,
  };
}
```

- [ ] **Step 5: Run to verify they pass**

Run: `cd frontend && npx vitest run src/lib/planarDetect.test.ts`
Expected: PASS (3 tests). If the sphere test is flaky at the boundary, lower `minAreaFraction` default until a plaque passes and a sphere fails cleanly (a UV sphere's largest coplanar bin is a tiny fraction of its area; 0.15 gives ample margin).

- [ ] **Step 6: Commit**

```bash
git add frontend/src/lib/printZone.ts frontend/src/lib/planarDetect.ts frontend/src/lib/planarDetect.test.ts
git commit -m "feat(3d): planar print-zone detector"
```

---

## Task 5: Rewire modelFaceSnapshot + cache-bust

Use `detectPrintZone` to orient the fallback face render, and key the cache by a model version so a replaced model never serves a stale render.

**Files:**
- Modify: `frontend/src/lib/modelFaceSnapshot.ts`
- Test: `frontend/src/lib/modelFaceSnapshot.test.ts` (create if absent)

- [ ] **Step 1: Add a `version` param to the cache key.** Change `renderModelFace`'s signature to accept an opaque version token and fold it into `cacheKey`:

```ts
export function renderModelFace(
  productKey: string,
  filamentColor: string,
  version = '',
  widthPx = 1000,
  heightPx = 760,
): Promise<ModelFaceSnapshot> {
  const cacheKey = `${productKey}|${filamentColor}|${version}|${widthPx}x${heightPx}`;
```

Thread `version` through to `renderFresh` unchanged otherwise.

- [ ] **Step 2: Replace the bbox-min-axis block** (lines ~71-88) with a `detectPrintZone` call, falling back to the old heuristic only when detection returns null:

```ts
  const detected = detectPrintZone(geometry);

  let faceWidthMm: number;
  let faceHeightMm: number;
  if (detected) {
    // Orient the detected face normal onto +Z for the orthographic render.
    const { zoneBasis } = await import('./printZone');
    const { n } = zoneBasis(detected);
    const q = new THREE.Quaternion().setFromUnitVectors(n, new THREE.Vector3(0, 0, 1));
    mesh.applyQuaternion(q);
    faceWidthMm = detected.width_mm;
    faceHeightMm = detected.height_mm;
  } else {
    // No flat face — keep the legacy smallest-extent framing so the customer
    // still gets a neutral render (the designer will require an admin zone).
    const extents = [size.x, size.y, size.z];
    const minAxis = extents.indexOf(Math.min(...extents));
    if (minAxis === 0) mesh.rotation.y = Math.PI / 2;
    if (minAxis === 1) mesh.rotation.x = -Math.PI / 2;
    faceWidthMm = minAxis === 0 ? size.z : size.x;
    faceHeightMm = minAxis === 1 ? size.z : size.y;
  }
```

Add `import { detectPrintZone } from './planarDetect';` at the top. Remove the now-dead `minAxis`/rotation lines that were outside this block.

- [ ] **Step 3: Write a regression test** (`modelFaceSnapshot.test.ts`) that the cache key includes the version — mock `STLLoader` + `WebGLRenderer` minimally, or (simpler) unit-test only the cache-key composition by extracting `cacheKeyFor(productKey, color, version, w, h)` into a tiny exported helper and asserting two versions differ:

```ts
import { expect, it } from 'vitest';
import { cacheKeyFor } from './modelFaceSnapshot';

it('cache key changes when the model version changes', () => {
  expect(cacheKeyFor('slug', 'White', 'v1', 1000, 760))
    .not.toBe(cacheKeyFor('slug', 'White', 'v2', 1000, 760));
});
```

Export `cacheKeyFor` from `modelFaceSnapshot.ts` and build `cacheKey` from it.

- [ ] **Step 4: Run tests**

Run: `cd frontend && npx vitest run src/lib/modelFaceSnapshot.test.ts`
Expected: PASS.

- [ ] **Step 5: Update the one caller** in `ProductDesignerPage.tsx` (line ~119) to pass a version. Use the product's `updated_at` (or a new `model_version` field if the API exposes one) as the token:

```ts
    renderModelFace(id, filamentColor, product.updated_at ?? '')
```

Confirm `updated_at` exists on the `Product` type; if not, use `String(product.id)` for now and note the cache is per-id (a replace within a session still busts via a full reload).

- [ ] **Step 6: Commit**

```bash
git add frontend/src/lib/modelFaceSnapshot.ts frontend/src/lib/modelFaceSnapshot.test.ts frontend/src/pages/ProductDesignerPage.tsx
git commit -m "feat(3d): face render uses planar detection + version cache-bust"
```

---

## Task 6: Decal builder + UV-flattened print file

`modelDecal.ts` builds a `DecalGeometry` from a `PrintZone` and an artwork texture (live preview), and renders the decal flattened to its own UV as the production print PNG.

**Files:**
- Create: `frontend/src/lib/modelDecal.ts`
- Test: `frontend/src/lib/modelDecal.test.ts`

- [ ] **Step 1: Write the failing test** (geometry-only; no GPU)

```ts
import { expect, it } from 'vitest';
import * as THREE from 'three';
import { buildDecalGeometry } from './modelDecal';
import type { PrintZone } from './printZone';

it('builds decal geometry covering the zone on a flat mesh', () => {
  const mesh = new THREE.Mesh(new THREE.BoxGeometry(60, 40, 4));
  const zone: PrintZone = {
    normal: [0, 0, 1],
    center: [0, 0, 2],
    up: [0, 1, 0],
    width_mm: 40,
    height_mm: 30,
  };
  const geo = buildDecalGeometry(mesh, zone);
  expect(geo).not.toBeNull();
  expect(geo!.getAttribute('position').count).toBeGreaterThan(0);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd frontend && npx vitest run src/lib/modelDecal.test.ts`
Expected: FAIL — `buildDecalGeometry` not defined.

- [ ] **Step 3: Implement `modelDecal.ts`**

```ts
import * as THREE from 'three';
import { DecalGeometry } from 'three/examples/jsm/geometries/DecalGeometry.js';
import { zoneBasis, type PrintZone } from './printZone';

/**
 * Build a decal projected onto `mesh` over the given print zone. Orientation is
 * derived from the zone basis; the decal's size is the zone's mm footprint and
 * projection depth spans the local geometry so it wraps a curved surface.
 * Returns null if the projection produced no geometry (zone off the mesh).
 */
export function buildDecalGeometry(mesh: THREE.Mesh, zone: PrintZone): THREE.BufferGeometry | null {
  const { n, u, v } = zoneBasis(zone);
  const position = new THREE.Vector3(...zone.center);

  // Orientation matrix (u,v,n) → Euler for DecalGeometry.
  const basis = new THREE.Matrix4().makeBasis(u, v, n);
  const orientation = new THREE.Euler().setFromRotationMatrix(basis);

  // Projector box: zone footprint in-plane, generous depth through the surface.
  const depth = Math.max(zone.width_mm, zone.height_mm);
  const size = new THREE.Vector3(zone.width_mm, zone.height_mm, depth);

  mesh.updateMatrixWorld(true);
  const geo = new DecalGeometry(mesh, position, orientation, size);
  return geo.getAttribute('position').count > 0 ? geo : null;
}

/**
 * Render the decal region flattened to its own UV space as the production print
 * file: a transparent PNG at `printPx` wide, artwork exactly as placed. This is
 * the file the UV printer/jig consumes (flat zone → identity mapping; wrapped
 * zone → the decal's UV unwrap). Requires a document/WebGL context (browser).
 */
export function renderPrintFile(
  decal: THREE.BufferGeometry,
  artwork: THREE.Texture,
  printPx: number,
  aspect: number,
): string {
  const scene = new THREE.Scene();
  const material = new THREE.MeshBasicMaterial({ map: artwork, transparent: true });

  // Remap the decal's UVs onto a flat quad in clip space so an orthographic
  // camera sees the artwork undistorted at print resolution.
  const uv = decal.getAttribute('uv');
  const flat = new THREE.BufferGeometry();
  const positions: number[] = [];
  for (let i = 0; i < uv.count; i++) {
    positions.push(uv.getX(i) * 2 - 1, uv.getY(i) * 2 - 1, 0);
  }
  flat.setAttribute('position', new THREE.Float32BufferAttribute(positions, 3));
  flat.setAttribute('uv', uv.clone());
  scene.add(new THREE.Mesh(flat, material));

  const camera = new THREE.OrthographicCamera(-1, 1, 1, -1, 0.1, 10);
  camera.position.set(0, 0, 1);

  const heightPx = Math.round(printPx / aspect);
  const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true, preserveDrawingBuffer: true });
  try {
    renderer.setSize(printPx, heightPx);
    renderer.render(scene, camera);
    return renderer.domElement.toDataURL('image/png');
  } finally {
    material.dispose();
    flat.dispose();
    renderer.dispose();
    renderer.forceContextLoss();
  }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `cd frontend && npx vitest run src/lib/modelDecal.test.ts`
Expected: PASS. (`renderPrintFile` is not unit-tested — it needs WebGL; it is exercised in the preview verification, Task 9.)

- [ ] **Step 5: Commit**

```bash
git add frontend/src/lib/modelDecal.ts frontend/src/lib/modelDecal.test.ts
git commit -m "feat(3d): decal geometry builder + UV-flattened print file"
```

---

## Task 7: Admin zone editor component + wiring

A three.js view (mesh + orbit) that pre-fills the detected zone, lets the admin click the mesh to move it and drag/resize, and saves via the `print-zone` endpoint.

**Files:**
- Create: `frontend/src/components/Model3dZoneEditor.tsx`
- Modify: `frontend/src/pages/ProductAdminDetailPage.tsx`
- Modify: `frontend/src/types.ts`

- [ ] **Step 1: Add types** to `frontend/src/types.ts` — a `PrintZone` interface mirroring `printZone.ts` and add `print_zone?: PrintZone | null`, `has_glb?: boolean`, `has_model?: boolean` to `AdminProduct`.

- [ ] **Step 2: Build `Model3dZoneEditor.tsx`.** Props: `{ productId: number; hasGlb: boolean; initialZone: PrintZone | null; onSaved: (z: PrintZone) => void }`. Behaviour:
  - Load the mesh from `GET /api/admin/products/{id}/model?kind=mesh` (STL/OBJ/3MF via the matching three loader by extension; GLB from `kind=glb` when `hasGlb`). Use `api.defaults.baseURL` for the absolute origin (mirror `ModelViewer.tsx:59`).
  - Render with `OrbitControls` (no auto-rotate — this is an editing view).
  - On mount, if `initialZone` is null, run `detectPrintZone(geometry)` and use it; if that is null, show "No flat face detected — click the model to place the print zone."
  - Draw the zone as a translucent quad (a `PlaneGeometry` sized `width_mm × height_mm`, positioned/oriented from `zoneBasis`).
  - Pointer-down raycasts against the mesh; on hit, set `center` to the hit point and `normal` to the interpolated face normal (`intersection.face.normal` transformed by the mesh normal matrix). Re-derive `up` from the previous up projected onto the new plane.
  - Width/height controlled by two number inputs (mm) beside the canvas (drag-handles are a nice-to-have; number inputs are the reliable MVP and keep the value explicit for production).
  - "Save print zone" POSTs `{ print_zone }` to `/api/admin/products/{id}/print-zone`, then calls `onSaved`.
  - Dispose the renderer/scene on unmount (mirror `ModelViewer.tsx` cleanup).

  Reference the exact three.js lifecycle, loader, and disposal patterns already in `frontend/src/components/ModelViewer.tsx` — copy its structure rather than inventing a new one.

- [ ] **Step 3: Mount it** in `ProductAdminDetailPage.tsx`'s `DetailBody`, inside a `MODEL_3D`-only card:

```tsx
{product.class === 'MODEL_3D' && product.has_model && (
  <Card padding="md" className="flex flex-col gap-3">
    <h3 className="font-display text-lg">Print zone</h3>
    <p className="text-sm text-fg-muted">
      Mark the surface your logo prints on. Auto-detected where possible — click the
      model to reposition, and set the size in millimetres.
    </p>
    <Model3dZoneEditor
      productId={product.id}
      hasGlb={!!product.has_glb}
      initialZone={product.print_zone ?? null}
      onSaved={() => { toast({ title: 'Print zone saved', tone: 'success' }); onChanged(); }}
    />
  </Card>
)}
```

- [ ] **Step 4: Typecheck + build**

Run: `cd frontend && npx tsc --noEmit`
Expected: no errors.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/components/Model3dZoneEditor.tsx frontend/src/pages/ProductAdminDetailPage.tsx frontend/src/types.ts
git commit -m "feat(3d): admin print-zone editor"
```

---

## Task 8: Model-replace UI

An upload control in the admin detail page wired to `POST /admin/products/{id}/model-file`, accepting mesh + GLB, refreshing the page state on success.

**Files:**
- Modify: `frontend/src/pages/ProductAdminDetailPage.tsx`

- [ ] **Step 1: Add a replace panel** in the same `MODEL_3D` card (or a sibling card). A file input (`accept=".stl,.3mf,.obj,.glb"`) that on change:
  - Calls `ensureCsrf()` (already imported), then `api.post(\`/admin/products/${product.id}/model-file\`, formData)` with the file under key `file` and `Content-Type: multipart/form-data`.
  - On success: toast "Model replaced", call `onChanged()` to reload (which re-fetches `print_zone` — now null after a mesh replace — and re-runs auto-detect in the editor).
  - On error: surface `apiError(err)` inline.
  - Show current state: "Mesh: {basename or 'none'}" and "Decoration GLB: {present/none}", plus a note that replacing the mesh clears the saved print zone.

- [ ] **Step 2: Typecheck**

Run: `cd frontend && npx tsc --noEmit`
Expected: no errors.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/pages/ProductAdminDetailPage.tsx
git commit -m "feat(3d): admin model-replace upload (mesh + glb)"
```

---

## Task 9: Customer decal preview + designer constraint

Mount a live mesh + decal beside the fabric designer, and constrain the designer to the `print_zone` so placement maps to the real surface. On capture, produce the UV-flattened print file.

**Files:**
- Create: `frontend/src/components/Model3dDecalPreview.tsx`
- Modify: `frontend/src/pages/ProductDesignerPage.tsx`
- Modify: `frontend/src/types.ts` (add `print_zone`, `has_glb` to public `Product`)

- [ ] **Step 1: Expose `print_zone` publicly.** In `CatalogueController` product detail transform (the `show`/detail payload), add `'print_zone' => $product->print_zone` and `'has_glb' => $product->decor_glb_ref !== null` for MODEL_3D. Add matching fields to the `Product` type in `types.ts`. Add a Pest assertion in `CatalogueTest.php` that a published MODEL_3D detail includes `print_zone`.

- [ ] **Step 2: Build `Model3dDecalPreview.tsx`.** Props: `{ productKey: string; hasGlb: boolean; filamentColor: string; zone: PrintZone; artworkDataUrl: string | null }`. It:
  - Loads GLB (`/catalogue/{key}/model` stays STL; add `?kind=glb` support to the public model route OR load STL and rely on decal UVs — MVP: load STL, colour by filament).
  - Renders the mesh in `filamentColor`, orbit controls, and — when `artworkDataUrl` is set — overlays `buildDecalGeometry(mesh, zone)` textured with the artwork (`new THREE.TextureLoader().load(artworkDataUrl)`), material `MeshStandardMaterial({ map, transparent, polygonOffset, polygonOffsetFactor: -4 })`.
  - Re-projects the decal whenever `artworkDataUrl` or `zone` changes.

- [ ] **Step 3: Wire into `ProductDesignerPage.tsx`.** For `is3d` items with a `print_zone`:
  - Keep the fabric `DesignerCanvas` as the placement UI, but set its `canvasMm` from `zone.width_mm/height_mm` (so mm mapping is exact) instead of the snapshot footprint.
  - Render `Model3dDecalPreview` beside/above the canvas, feeding it `artwork?.dataUrl` so the buyer sees the decal update live on capture.
  - If `print_zone` is null: show the existing "3D face preview unavailable — design on the neutral stage" path (unchanged), so an un-zoned product still works.

- [ ] **Step 4: Production print file on capture.** In `ProductDesignerPage.handleCapture` (or inside `DesignerCanvas.capture` when `canvasMm` is present), when a `print_zone` exists, additionally compute the UV-flattened print PNG via `buildDecalGeometry` + `renderPrintFile` and attach it to the uploaded artwork as the print asset (the on-canvas PNG stays the proof). Keep the existing mm placement record. Confirm the production/proof consumer (`uploadArtwork` + cart line customization) — attach as `customization.print_file_ref` after a second `uploadArtwork` call, or replace `artwork_ref` for MODEL_3D with the flattened file. Decide based on how `ProofController`/production reads it; default: keep `artwork_ref` as the flattened print file for MODEL_3D and add `proof_ref` for the mockup.

- [ ] **Step 5: Typecheck + unit tests**

Run: `cd frontend && npx tsc --noEmit && npx vitest run`
Expected: no type errors; existing designer tests still pass.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/components/Model3dDecalPreview.tsx frontend/src/pages/ProductDesignerPage.tsx frontend/src/types.ts app/Http/Controllers/CatalogueController.php tests/Feature/CatalogueTest.php
git commit -m "feat(3d): customer decal preview + zone-constrained designer"
```

---

## Task 10: End-to-end verification

**Files:** none (verification only).

- [ ] **Step 1: Full backend suite**

Run: `php artisan test`
Expected: all green (new `Model3dPrintZoneTest`, unchanged `SlicerServiceTest`, `CatalogueTest`).

- [ ] **Step 2: Full frontend suite + typecheck + build**

Run: `cd frontend && npx tsc --noEmit && npx vitest run && npx vite build`
Expected: all green.

- [ ] **Step 3: Manual preview** (use the preview tools, not Bash). Start the dev server, then:
  - Admin: open a MODEL_3D product detail → replace its model (upload an STL) → confirm the zone editor loads the new mesh and auto-detects a face → adjust size → save.
  - Customer: open that product's designer → confirm the logo previews as a decal on the real mesh in the chosen filament colour → capture → confirm a print file is produced.
  - Check `preview_console_logs` (level error) and `preview_network` (failed) are clean.

- [ ] **Step 4: Final commit / branch is ready for review**

```bash
git status   # confirm clean tree
```

---

## Self-Review Notes (addressed)

- **Spec coverage:** data model (T1), STL-canonical/GLB-additive (T3), planar detect B (T4), admin zone A (T7), decal C (T6/T9), replace flow (T3/T8), print file (T6/T9), cache-bust (T5), slicer untouched (verified T10). All spec sections mapped.
- **Slicer regression** guarded explicitly in T10 step 1.
- **Un-zoned / curved fallback** preserved in T5 + T9 step 3 (never a silent bad guess).
- **Open integration decision** flagged in T9 step 4 (which ref carries the flattened print file) — resolve against `ProofController`/production reader during execution; default stated.
