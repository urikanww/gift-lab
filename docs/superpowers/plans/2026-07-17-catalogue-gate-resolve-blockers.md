# Catalogue Gate — Resolve Blockers Inline — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let staff fix the three self-fixable `SCRAPED_UV` blockers (dimensions/weight, print method, price) from a popup on the catalogue gate, then re-gate and publish in one submit.

**Architecture:** A new public `ScrapedCatalogueService::regate()` mirrors the existing 3D one and makes staff edits re-evaluate the gate at all (today only a scraper fetch can). A narrow `POST /admin/products/{product}/resolve-blockers` on `AdminCatalogueController` validates only its six fields, saves, re-gates, and publishes if fully clear. The gate's blocker badges become buttons that open a `ResolveBlockersModal` rendering only the field groups the row's blockers name.

**Tech Stack:** Laravel 11 / PHP 8.3, Pest. React 18 + TypeScript, Vite, Zustand, Tailwind, Vitest + React Testing Library. No form library — plain controlled `useState`, matching the codebase.

**Spec:** [`docs/superpowers/specs/2026-07-17-catalogue-gate-resolve-blockers-design.md`](../specs/2026-07-17-catalogue-gate-resolve-blockers-design.md)

---

## Orientation — read before Task 1

The engineer needs these facts; none are guessable from the file names.

- **The gate's endpoints live under `/admin/products/{product}/…` even though they're on `AdminCatalogueController`** (`routes/api.php:125-141`). `AdminProductController` owns `/admin/products` CRUD. Confusing, established, follow it.
- **`weight` and `base_cost` are `decimal` casts, so they come back as *strings*** from Eloquent (`'weight' => 'decimal:3'`, `Product.php:79-104`). Assertions must account for that (`'12.500'`, not `12.5`).
- **`dimensions` is a JSON column cast to `array`**, shaped `{l, w, h, unit}`. Writes append `+ ['unit' => 'mm']`.
- **`Product::factory()->scrapedUv()`** already sets `stock_estimate` (`ProductFactory.php:47`), so a test that wants the `stock_unreadable` blocker must explicitly null it.
- **The whole scraped gate is `CompletenessGate::reasons()`** — 25 lines, `app/Services/Catalogue/CompletenessGate.php:20-44`. Read it first. `missing_dimensions` covers dims *and* weight; `not_printable` covers `is_printable` *and* `print_method`.
- **Tests run from the repo root:** `vendor/bin/pest` (backend), `cd frontend && npm run test` (frontend).

---

## File Structure

**Backend**

| File | Change | Responsibility |
|---|---|---|
| `app/Services/Catalogue/ScrapedCatalogueService.php` | Modify `:155-169` | Gains public `regate()`; private `evaluateAndSetState()` delegates to it |
| `app/Http/Controllers/AdminCatalogueController.php` | Modify `:34-38`, add method after `publish` (`:189`) | New `resolveBlockers()`; constructor gains `AuditLogger` |
| `routes/api.php` | Modify — add after `:125` | Route registration |
| `tests/Feature/AdminCatalogueTest.php` | Modify — append | Endpoint tests |
| `tests/Feature/ScrapedCatalogueTest.php` | Modify — append | `regate()` unit-ish test |

**Frontend**

| File | Change | Responsibility |
|---|---|---|
| `frontend/src/lib/api.ts` | Modify — add after `apiError` (`:63`) | New `apiFieldErrors()`; `apiError` untouched |
| `frontend/src/types.ts` | Modify `:391-408` | `AdminCatalogueItem` gains the five prefill fields |
| `frontend/src/stores/catalogueAdminStore.ts` | Modify | `resolveBlockers()` action |
| `frontend/src/components/admin/ResolveBlockersModal.tsx` | **Create** | The popup — form, validation, outcome rendering |
| `frontend/src/components/admin/ResolveBlockersModal.test.tsx` | **Create** | Component tests |
| `frontend/src/pages/CatalogueAdminPage.tsx` | Modify `:59-74`, `:694-704`, `:735-741` | Badges → buttons; modal wiring |

Order is bottom-up: the re-gate makes the endpoint possible, the endpoint makes the store possible, the store makes the modal possible. Each task ends green and committed.

---

## Task 1: Public `regate()` on ScrapedCatalogueService

**Files:**
- Modify: `app/Services/Catalogue/ScrapedCatalogueService.php:155-169`
- Test: `tests/Feature/ScrapedCatalogueTest.php` (append)

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/ScrapedCatalogueTest.php`:

```php
it('regates a fixed-up product to ReadyToApprove without publishing it', function (): void {
    // auto_publish ON: regate must still NOT jump straight to Published -
    // publication stays an explicit staff decision.
    PricingConfig::updateOrCreate(
        ['group' => 'catalogue', 'key' => 'auto_publish'],
        ['value' => '1', 'type' => 'bool'],
    );

    $product = Product::factory()->scrapedUv()->create([
        'publish_state' => 'CANNOT_PUBLISH',
        'cannot_publish_reasons' => ['missing_dimensions'],
        'base_cost' => 12.00,
        'dimensions' => ['l' => 10, 'w' => 10, 'h' => 10, 'unit' => 'mm'],
        'weight' => 250,
        'is_printable' => true,
        'print_method' => 'UV',
    ]);

    $service = app(ScrapedCatalogueService::class);
    $result = $service->regate($product);

    expect($result->publish_state)->toBe(PublishState::ReadyToApprove)
        ->and($result->cannot_publish_reasons)->toBeNull();
});

it('regates an incomplete product back to CannotPublish with fresh reasons', function (): void {
    $product = Product::factory()->scrapedUv()->create([
        'publish_state' => 'READY_TO_APPROVE',
        'cannot_publish_reasons' => null,
        'base_cost' => 12.00,
        'dimensions' => ['l' => 10, 'w' => 10, 'h' => 10, 'unit' => 'mm'],
        'weight' => null,          // → missing_dimensions
        'is_printable' => true,
        'print_method' => 'UV',
    ]);

    $result = app(ScrapedCatalogueService::class)->regate($product);

    expect($result->publish_state)->toBe(PublishState::CannotPublish)
        ->and($result->cannot_publish_reasons)->toBe(['missing_dimensions']);
});
```

Check the top of the file for the imports it already has — it needs `App\Enums\PublishState`, `App\Models\PricingConfig`, `App\Models\Product`, and `App\Services\Catalogue\ScrapedCatalogueService`. Add only the missing ones.

- [ ] **Step 2: Run it to make sure it fails**

```bash
vendor/bin/pest tests/Feature/ScrapedCatalogueTest.php --filter=regates
```

Expected: FAIL — `Call to protected method App\Services\Catalogue\ScrapedCatalogueService::regate()` (or "method does not exist").

- [ ] **Step 3: Implement**

Replace `ScrapedCatalogueService.php:155-169` with:

```php
    /**
     * Re-evaluate the completeness gate against the product's current facts
     * (after staff fill in a missing weight, print method, price) without
     * publishing. Mirrors Model3dCatalogueService::regate().
     */
    public function regate(Product $product): Product
    {
        $reasons = $this->gate->reasons($product);

        if ($reasons !== []) {
            return $this->markCannotPublish($product, $reasons);
        }

        // Never jump straight to Published from a re-gate; auto-publish is a
        // policy about scraper INGEST, not about staff edits. Publication stays
        // an explicit decision (mirrors Model3dCatalogueService::regate()).
        $product->publish_state = PublishState::ReadyToApprove;
        $product->cannot_publish_reasons = null;
        $product->save();

        return $product;
    }

    private function evaluateAndSetState(Product $product): void
    {
        $reasons = $this->gate->reasons($product);

        if ($reasons !== []) {
            $this->markCannotPublish($product, $reasons);

            return;
        }

        $autoPublish = (bool) PricingConfig::value('catalogue', 'auto_publish', false);
        $product->publish_state = $autoPublish ? PublishState::Published : PublishState::ReadyToApprove;
        $product->cannot_publish_reasons = null;
        $product->save();
    }
```

`evaluateAndSetState()` keeps its own body rather than delegating — it differs on exactly the auto-publish branch, and collapsing them would erase that distinction. The duplication is four lines and deliberate.

- [ ] **Step 4: Run the tests**

```bash
vendor/bin/pest tests/Feature/ScrapedCatalogueTest.php
```

Expected: PASS, including every pre-existing test in the file (ingest/resync auto-publish behaviour must be unchanged).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Catalogue/ScrapedCatalogueService.php tests/Feature/ScrapedCatalogueTest.php
git commit -m "feat(catalogue): add public ScrapedCatalogueService::regate()

Staff edits had no way to re-evaluate the scraped gate - evaluateAndSetState
was private and only reachable from a scraper fetch. Mirrors the 3D service's
regate(), including its rule that a re-gate never lands on Published."
```

---

## Task 2: The `resolve-blockers` endpoint

**Files:**
- Modify: `app/Http/Controllers/AdminCatalogueController.php` (constructor `:34-38`; new method after `publish`, `:189`)
- Modify: `routes/api.php` (after `:125`)
- Test: `tests/Feature/AdminCatalogueTest.php` (append)

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/AdminCatalogueTest.php`. The file's `beforeEach` already provides `$this->staff` and `$this->superadmin`.

```php
/** A CANNOT_PUBLISH scraped row missing everything the popup can fix. */
function blockedScrapedProduct(array $overrides = []): Product
{
    return Product::factory()->scrapedUv()->create(array_merge([
        'publish_state' => 'CANNOT_PUBLISH',
        'cannot_publish_reasons' => ['missing_price', 'missing_dimensions', 'not_printable'],
        'base_cost' => 0,
        'dimensions' => null,
        'weight' => null,
        'is_printable' => false,
        'print_method' => null,
    ], $overrides));
}

it('resolves every blocker and publishes in one call', function (): void {
    $product = blockedScrapedProduct();

    Sanctum::actingAs($this->staff);
    $this->postJson("/api/admin/products/{$product->id}/resolve-blockers", [
        'base_cost' => 12.5,
        'dimensions' => ['l' => 100, 'w' => 80, 'h' => 60],
        'weight' => 250,
        'is_printable' => true,
        'print_method' => 'UV',
    ])
        ->assertOk()
        ->assertJsonPath('published', true)
        ->assertJsonPath('cannot_publish_reasons', null);

    $product->refresh();
    expect($product->publish_state->value)->toBe('PUBLISHED')
        // decimal casts return strings
        ->and($product->weight)->toBe('250.000')
        ->and($product->dimensions)->toBe(['l' => 100, 'w' => 80, 'h' => 60, 'unit' => 'mm']);
});

it('saves the fix but does not publish when an unfixable blocker remains', function (): void {
    // stock_estimate is source-truth and NOT settable here, so the row stays
    // blocked - but the typed weight must still persist.
    $product = blockedScrapedProduct(['stock_estimate' => null]);

    Sanctum::actingAs($this->staff);
    $this->postJson("/api/admin/products/{$product->id}/resolve-blockers", [
        'base_cost' => 12.5,
        'dimensions' => ['l' => 100, 'w' => 80, 'h' => 60],
        'weight' => 250,
        'is_printable' => true,
        'print_method' => 'UV',
    ])
        ->assertOk()
        ->assertJsonPath('published', false)
        ->assertJsonPath('cannot_publish_reasons', ['stock_unreadable']);

    $product->refresh();
    expect($product->publish_state->value)->toBe('CANNOT_PUBLISH')
        ->and($product->weight)->toBe('250.000'); // work was NOT thrown away
});

it('rejects a non-positive weight and writes nothing', function (): void {
    $product = blockedScrapedProduct();

    Sanctum::actingAs($this->staff);
    $this->postJson("/api/admin/products/{$product->id}/resolve-blockers", ['weight' => 0])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['weight']);

    expect($product->refresh()->weight)->toBeNull();
});

it('rejects an absurd weight above the sanity ceiling', function (): void {
    $product = blockedScrapedProduct();

    Sanctum::actingAs($this->staff);
    $this->postJson("/api/admin/products/{$product->id}/resolve-blockers", ['weight' => 500000])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['weight']);
});

it('rejects an absurd dimension above the sanity ceiling', function (): void {
    $product = blockedScrapedProduct();

    Sanctum::actingAs($this->staff);
    $this->postJson("/api/admin/products/{$product->id}/resolve-blockers", [
        'dimensions' => ['l' => 5000, 'w' => 80, 'h' => 60],
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['dimensions.l']);
});

it('rejects an unknown print method', function (): void {
    $product = blockedScrapedProduct();

    Sanctum::actingAs($this->staff);
    $this->postJson("/api/admin/products/{$product->id}/resolve-blockers", ['print_method' => 'LASER'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['print_method']);
});

it('requires every dimension when dimensions are sent at all', function (): void {
    $product = blockedScrapedProduct();

    Sanctum::actingAs($this->staff);
    $this->postJson("/api/admin/products/{$product->id}/resolve-blockers", [
        'dimensions' => ['l' => 100],
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['dimensions.w', 'dimensions.h']);
});

it('refuses to resolve blockers on a MODEL_3D product', function (): void {
    $product = Product::factory()->model3d()->create(['publish_state' => 'CANNOT_PUBLISH']);

    Sanctum::actingAs($this->staff);
    $this->postJson("/api/admin/products/{$product->id}/resolve-blockers", ['weight' => 250])
        ->assertStatus(422);
});

it('refuses to resolve blockers on an already-published product', function (): void {
    $product = Product::factory()->scrapedUv()->create(['publish_state' => 'PUBLISHED']);

    Sanctum::actingAs($this->staff);
    $this->postJson("/api/admin/products/{$product->id}/resolve-blockers", ['weight' => 250])
        ->assertStatus(422);
});

it('forbids a non-staff user from resolving blockers', function (): void {
    $product = blockedScrapedProduct();

    Sanctum::actingAs(User::factory()->create()); // buyer
    $this->postJson("/api/admin/products/{$product->id}/resolve-blockers", ['weight' => 250])
        ->assertStatus(403);
});

it('audit-logs a blocker resolution', function (): void {
    $product = blockedScrapedProduct();

    Sanctum::actingAs($this->staff);
    $this->postJson("/api/admin/products/{$product->id}/resolve-blockers", [
        'base_cost' => 12.5,
        'dimensions' => ['l' => 100, 'w' => 80, 'h' => 60],
        'weight' => 250,
        'is_printable' => true,
        'print_method' => 'UV',
    ])->assertOk();

    $this->assertDatabaseHas('audit_logs', [
        'auditable_type' => Product::class,
        'auditable_id' => $product->id,
        'event' => 'product.blockers_resolved',
        'user_id' => $this->staff->id,
    ]);
});
```

- [ ] **Step 2: Run them to make sure they fail**

```bash
vendor/bin/pest tests/Feature/AdminCatalogueTest.php --filter="blocker|resolve"
```

Expected: FAIL — 404, the route doesn't exist yet.

- [ ] **Step 3: Register the route**

In `routes/api.php`, immediately after line 125 (`.../publish`):

```php
    // Staff fix the self-fixable SCRAPED_UV blockers inline (dims/weight, print
    // method, price), then re-gate + publish in one call.
    Route::post('/admin/products/{product}/resolve-blockers', [AdminCatalogueController::class, 'resolveBlockers']);
```

- [ ] **Step 4: Add the AuditLogger dependency**

`AdminCatalogueController.php:34-38` — add the fourth parameter:

```php
    public function __construct(
        private readonly ScrapedCatalogueService $scraped,
        private readonly Model3dCatalogueService $model3d,
        private readonly Model3dApiClient $apiClient,
        private readonly AuditLogger $audit,
    ) {}
```

And add the import alongside the others at the top:

```php
use App\Services\AuditLogger;
```

`AuditLogger` is a leaf service, so this can't cycle with `AdminProductController`'s existing injection of this controller.

- [ ] **Step 5: Implement the endpoint**

Insert after `publish()` (ends `AdminCatalogueController.php:189`):

```php
    /**
     * Staff fill in the facts the scraper couldn't read (dimensions + weight,
     * print method, price) from the gate itself, then re-gate and publish if the
     * row came fully clear. Deliberately narrow: it accepts ONLY the fields the
     * three self-fixable CompletenessGate reasons name, so it needs no
     * superadmin-field stripping the way the general product PATCH does.
     *
     * A 422 always means the INPUT was bad - never that the product merely
     * stayed blocked. Staying blocked is a 200 with published=false, so typed
     * work is never thrown away.
     */
    public function resolveBlockers(Request $request, Product $product): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);

        if ($product->class !== ProductClass::ScrapedUv) {
            return response()->json([
                'message' => 'Only SCRAPED_UV products resolve blockers here; 3D items have their own tools.',
            ], 422);
        }

        if (! in_array($product->publish_state, [PublishState::CannotPublish, PublishState::Pending], true)) {
            return response()->json(['message' => 'Product has no blockers to resolve.'], 422);
        }

        // Sanity ceilings (2 m, 100 kg, SGD 1M) catch a slipped decimal; they are
        // absurdity bounds, not business limits.
        $validated = $request->validate([
            'base_cost' => ['sometimes', 'numeric', 'gt:0', 'max:1000000'],
            'weight' => ['sometimes', 'numeric', 'gt:0', 'max:100000'],
            'dimensions' => ['sometimes', 'array'],
            'dimensions.l' => ['required_with:dimensions', 'numeric', 'gt:0', 'max:2000'],
            'dimensions.w' => ['required_with:dimensions', 'numeric', 'gt:0', 'max:2000'],
            'dimensions.h' => ['required_with:dimensions', 'numeric', 'gt:0', 'max:2000'],
            'print_method' => ['sometimes', 'string', 'in:UV,FDM,RESIN'],
            'is_printable' => ['sometimes', 'boolean'],
        ]);

        if (isset($validated['dimensions'])) {
            $validated['dimensions'] = $validated['dimensions'] + ['unit' => 'mm'];
        }

        $before = [
            'base_cost' => $product->base_cost,
            'weight' => $product->weight,
            'dimensions' => $product->dimensions,
            'print_method' => $product->print_method?->value,
            'is_printable' => $product->is_printable,
            'publish_state' => $product->publish_state->value,
        ];

        $product = DB::transaction(function () use ($product, $validated): Product {
            $product->fill($validated);
            $product->save();

            $product = $this->scraped->regate($product);

            // regate() never publishes on its own - a clean re-gate lands on
            // ReadyToApprove and we make the publish an explicit call, so the
            // gate is re-run (publish() re-checks completeness itself).
            if ($product->publish_state === PublishState::ReadyToApprove) {
                $product = $this->scraped->publish($product);
            }

            return $product;
        });

        $this->audit->log($product, 'product.blockers_resolved', $before, [
            'base_cost' => $product->base_cost,
            'weight' => $product->weight,
            'dimensions' => $product->dimensions,
            'print_method' => $product->print_method?->value,
            'is_printable' => $product->is_printable,
            'publish_state' => $product->publish_state->value,
        ]);

        return response()->json([
            'published' => $product->publish_state === PublishState::Published,
            'publish_state' => $product->publish_state->value,
            'cannot_publish_reasons' => $product->cannot_publish_reasons,
        ]);
    }
```

`DB` and `ProductClass` and `PublishState` are already imported in this file (`:7-18`).

- [ ] **Step 6: Run the tests**

```bash
vendor/bin/pest tests/Feature/AdminCatalogueTest.php
```

Expected: PASS — all new tests **and** every pre-existing one. In particular `refuses to publish a CANNOT_PUBLISH item` (`:110`) must still pass: this endpoint re-gates before publishing, it doesn't bypass `publish`.

- [ ] **Step 7: Run the full backend suite**

```bash
vendor/bin/pest
```

Expected: PASS. If `AdminProductManagementTest` or `ScrapedCatalogueTest` broke, the constructor change or the `evaluateAndSetState` edit is at fault — fix rather than adjust the test.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/AdminCatalogueController.php routes/api.php tests/Feature/AdminCatalogueTest.php
git commit -m "feat(catalogue-gate): resolve-blockers endpoint (save, re-gate, publish)

Narrow staff endpoint accepting only the six fields the three self-fixable
SCRAPED_UV blockers name. A 422 means bad input; staying blocked is a 200 with
published=false so typed work survives."
```

---

## Task 3: Return the prefill fields from the gate list

**Files:**
- Modify: `app/Http/Controllers/AdminCatalogueController.php:130-147`
- Modify: `frontend/src/types.ts:391-408`
- Test: `tests/Feature/AdminCatalogueTest.php` (append)

- [ ] **Step 1: Write the failing test**

```php
it('returns the blocker-prefill fields on each gate row', function (): void {
    Product::factory()->scrapedUv()->create([
        'publish_state' => 'CANNOT_PUBLISH',
        'weight' => 250,
        'dimensions' => ['l' => 100, 'w' => 80, 'h' => 60, 'unit' => 'mm'],
        'print_method' => 'UV',
        'is_printable' => true,
    ]);

    Sanctum::actingAs($this->staff);
    $res = $this->getJson('/api/admin/catalogue')->assertOk();

    $res->assertJsonPath('data.0.weight', '250.000')
        ->assertJsonPath('data.0.dimensions.l', 100)
        ->assertJsonPath('data.0.print_method', 'UV')
        ->assertJsonPath('data.0.is_printable', true);
});
```

- [ ] **Step 2: Run it to make sure it fails**

```bash
vendor/bin/pest tests/Feature/AdminCatalogueTest.php --filter="prefill"
```

Expected: FAIL — "Property [data.0.weight] does not exist".

- [ ] **Step 3: Add the fields to the row transform**

In `AdminCatalogueController.php`, inside the `transform` closure (`:130-147`), after the `'cannot_publish_reasons'` line:

```php
            // Prefill for the inline blocker-resolution popup (the fields the
            // scraped gate's reasons actually name).
            'weight' => $p->weight,
            'dimensions' => $p->dimensions,
            'print_method' => $p->print_method?->value,
            'is_printable' => (bool) $p->is_printable,
```

`base_cost` is already returned at `:136`.

- [ ] **Step 4: Add the fields to the TypeScript type**

In `frontend/src/types.ts`, inside `AdminCatalogueItem` (`:391-408`), after `cannot_publish_reasons`:

```ts
  /** Prefill for the blocker-resolution popup. decimal casts arrive as strings. */
  weight: string | null;
  dimensions: { l?: number; w?: number; h?: number; unit?: string } | null;
  print_method: 'UV' | 'FDM' | 'RESIN' | null;
  is_printable: boolean;
```

- [ ] **Step 5: Run the tests + typecheck**

```bash
vendor/bin/pest tests/Feature/AdminCatalogueTest.php
cd frontend && npm run build
```

Expected: Pest PASS. The frontend build (which runs `tsc`) PASSES — adding required fields to `AdminCatalogueItem` may break test fixtures that construct one; if so, add the fields there too.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/AdminCatalogueController.php frontend/src/types.ts tests/Feature/AdminCatalogueTest.php
git commit -m "feat(catalogue-gate): return blocker-prefill fields on gate rows

The row payload named the blockers but not the fields they refer to, so a popup
would have needed a second fetch to show current values."
```

---

## Task 4: `apiFieldErrors` helper

**Files:**
- Modify: `frontend/src/lib/api.ts` (add after `apiError`, `:63`)
- Test: `frontend/src/lib/api.test.ts` (**create**)

- [ ] **Step 1: Write the failing test**

Create `frontend/src/lib/api.test.ts`:

```ts
import { expect, it } from 'vitest';
import { AxiosError, AxiosHeaders } from 'axios';
import { apiFieldErrors } from './api';

function validationError(errors: Record<string, string[]>): AxiosError {
  const err = new AxiosError('Request failed with status code 422');
  err.response = {
    data: { message: 'The given data was invalid.', errors },
    status: 422,
    statusText: 'Unprocessable Content',
    headers: new AxiosHeaders(),
    config: { headers: new AxiosHeaders() },
  };
  return err;
}

it('maps a Laravel validation bag to one message per field', () => {
  const result = apiFieldErrors(
    validationError({
      weight: ['The weight must be greater than 0.', 'Another message.'],
      'dimensions.l': ['The dimensions.l must not be greater than 2000.'],
    }),
  );

  expect(result).toEqual({
    weight: 'The weight must be greater than 0.',
    'dimensions.l': 'The dimensions.l must not be greater than 2000.',
  });
});

it('returns an empty object for a non-validation error', () => {
  expect(apiFieldErrors(new Error('boom'))).toEqual({});
  expect(apiFieldErrors(validationError({}))).toEqual({});
});
```

- [ ] **Step 2: Run it to make sure it fails**

```bash
cd frontend && npx vitest run src/lib/api.test.ts
```

Expected: FAIL — `apiFieldErrors is not a function` / no export.

- [ ] **Step 3: Implement**

Append to `frontend/src/lib/api.ts` after `apiError` (`:63`):

```ts
/**
 * Laravel's per-field validation bag, flattened to one message per field and
 * keyed exactly as sent (`dimensions.l`), so a form can map it onto its inputs.
 * Separate from apiError(), which joins the whole bag into a single string -
 * many call sites depend on that and must not change.
 */
export function apiFieldErrors(err: unknown): Record<string, string> {
  if (!(err instanceof AxiosError)) return {};
  const data = err.response?.data as { errors?: Record<string, string[]> } | undefined;
  if (!data?.errors) return {};

  return Object.fromEntries(
    Object.entries(data.errors)
      .map(([field, messages]) => [field, messages[0]])
      .filter((entry): entry is [string, string] => typeof entry[1] === 'string'),
  );
}
```

`AxiosError` is already imported at `:1`.

- [ ] **Step 4: Run the test**

```bash
cd frontend && npx vitest run src/lib/api.test.ts
```

Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/lib/api.ts frontend/src/lib/api.test.ts
git commit -m "feat(api): add apiFieldErrors for per-field 422 mapping

apiError flattens Laravel's bag into one string; forms need the field keys."
```

---

## Task 5: `resolveBlockers` store action

**Files:**
- Modify: `frontend/src/stores/catalogueAdminStore.ts`

No test — this store has none today, and the action is a thin wrapper covered end-to-end by Task 6's component tests. Follow the file's existing conventions exactly.

- [ ] **Step 1: Add the payload + result types**

At the top of `catalogueAdminStore.ts`, after the `CatalogueCounts` interface (`:18`):

```ts
/** Only the fields the three self-fixable SCRAPED_UV blockers name. */
export interface ResolveBlockersPayload {
  base_cost?: number;
  weight?: number;
  dimensions?: { l: number; w: number; h: number };
  print_method?: 'UV' | 'FDM' | 'RESIN';
  is_printable?: boolean;
}

export interface ResolveBlockersResult {
  published: boolean;
  publish_state: string;
  cannot_publish_reasons: string[] | null;
}
```

- [ ] **Step 2: Declare the action on the state interface**

In `CatalogueAdminState`, after `uploadModelFile` (`:68`):

```ts
  resolveBlockers: (id: number, payload: ResolveBlockersPayload) => Promise<ResolveBlockersResult>;
```

- [ ] **Step 3: Implement the action**

After the `uploadModelFile` implementation (`:164-179`):

```ts
  // Throws on failure (unlike the boolean-returning siblings) so the modal can
  // read the per-field 422 bag off the error - a bare `false` would lose it.
  resolveBlockers: async (id, payload) => {
    set({ error: null });
    try {
      await ensureCsrf();
      const { data } = await api.post<ResolveBlockersResult>(
        `/admin/products/${id}/resolve-blockers`,
        payload,
      );
      await get().fetch(undefined, { silent: true });
      return data;
    } catch (err) {
      set({ error: apiError(err) });
      throw err;
    }
  },
```

This deviates from the file's `return false` convention on purpose, and the comment says why: the modal needs the field-level error bag, which a boolean discards. The silent refetch still runs on success, keeping the row current.

- [ ] **Step 4: Typecheck**

```bash
cd frontend && npx tsc --noEmit
```

Expected: no errors.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/stores/catalogueAdminStore.ts
git commit -m "feat(catalogue-gate): add resolveBlockers store action"
```

---

## Task 6: `ResolveBlockersModal`

**Files:**
- Create: `frontend/src/components/admin/ResolveBlockersModal.tsx`
- Create: `frontend/src/components/admin/ResolveBlockersModal.test.tsx`

- [ ] **Step 1: Write the failing tests**

Create `frontend/src/components/admin/ResolveBlockersModal.test.tsx`:

```tsx
import { expect, it, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { AxiosError, AxiosHeaders } from 'axios';
import { ThemeProvider, ToastProvider } from '../../ui';
import ResolveBlockersModal from './ResolveBlockersModal';
import { useCatalogueAdminStore } from '../../stores/catalogueAdminStore';
import type { AdminCatalogueItem } from '../../types';

beforeEach(() => vi.restoreAllMocks());

function item(overrides: Partial<AdminCatalogueItem> = {}): AdminCatalogueItem {
  return {
    id: 7,
    name: 'Ceramic Mug',
    class: 'SCRAPED_UV',
    publish_state: 'CANNOT_PUBLISH',
    cannot_publish_reasons: ['missing_dimensions'],
    base_cost: '12.00',
    currency: 'SGD',
    creator_credit: null,
    image_url: null,
    source_url: null,
    source_kind: null,
    filament_material: null,
    filament_color: null,
    est_grams: null,
    estimates_verified: false,
    model_file_ref: null,
    weight: null,
    dimensions: null,
    print_method: null,
    is_printable: false,
    ...overrides,
  };
}

function renderModal(product: AdminCatalogueItem, onResolved = vi.fn()) {
  render(
    <ThemeProvider>
      <ToastProvider>
        <ResolveBlockersModal product={product} open onClose={vi.fn()} onResolved={onResolved} />
      </ToastProvider>
    </ThemeProvider>,
  );
}

function mockResolve(result: { published: boolean; cannot_publish_reasons: string[] | null }) {
  const fn = vi.fn().mockResolvedValue({ publish_state: 'PUBLISHED', ...result });
  useCatalogueAdminStore.setState({ resolveBlockers: fn });
  return fn;
}

it('shows only the fields the row is actually blocked on', () => {
  renderModal(item({ cannot_publish_reasons: ['missing_dimensions'] }));

  expect(screen.getByLabelText(/length/i)).toBeInTheDocument();
  expect(screen.getByLabelText(/weight/i)).toBeInTheDocument();
  // Not blocked on price or print method → those fields stay out of the popup.
  expect(screen.queryByLabelText(/base cost/i)).not.toBeInTheDocument();
  expect(screen.queryByLabelText(/print method/i)).not.toBeInTheDocument();
});

it('shows every group when the row is blocked on all three', () => {
  renderModal(item({ cannot_publish_reasons: ['missing_dimensions', 'not_printable', 'missing_price'] }));

  expect(screen.getByLabelText(/length/i)).toBeInTheDocument();
  expect(screen.getByLabelText(/base cost/i)).toBeInTheDocument();
  expect(screen.getByLabelText(/print method/i)).toBeInTheDocument();
});

it('blocks submit and flags the field when a value is not positive', async () => {
  const fn = mockResolve({ published: true, cannot_publish_reasons: null });
  renderModal(item({ cannot_publish_reasons: ['missing_dimensions'] }));

  await userEvent.type(screen.getByLabelText(/length/i), '0');
  await userEvent.click(screen.getByRole('button', { name: /save and publish/i }));

  expect(await screen.findByRole('alert')).toBeInTheDocument();
  expect(fn).not.toHaveBeenCalled(); // never left the browser
});

it('sends the typed values and reports a publish', async () => {
  const fn = mockResolve({ published: true, cannot_publish_reasons: null });
  const onResolved = vi.fn();
  renderModal(item({ cannot_publish_reasons: ['missing_dimensions'] }), onResolved);

  await userEvent.type(screen.getByLabelText(/length/i), '100');
  await userEvent.type(screen.getByLabelText(/width/i), '80');
  await userEvent.type(screen.getByLabelText(/height/i), '60');
  await userEvent.type(screen.getByLabelText(/weight/i), '250');
  await userEvent.click(screen.getByRole('button', { name: /save and publish/i }));

  await waitFor(() =>
    expect(fn).toHaveBeenCalledWith(7, {
      dimensions: { l: 100, w: 80, h: 60 },
      weight: 250,
    }),
  );
  await waitFor(() => expect(onResolved).toHaveBeenCalledWith(true));
});

it('stays open and names what is left when the row is still blocked', async () => {
  mockResolve({ published: false, cannot_publish_reasons: ['stock_unreadable'] });
  renderModal(item({ cannot_publish_reasons: ['missing_dimensions'] }));

  await userEvent.type(screen.getByLabelText(/length/i), '100');
  await userEvent.type(screen.getByLabelText(/width/i), '80');
  await userEvent.type(screen.getByLabelText(/height/i), '60');
  await userEvent.type(screen.getByLabelText(/weight/i), '250');
  await userEvent.click(screen.getByRole('button', { name: /save and publish/i }));

  expect(await screen.findByText(/saved, but still blocked/i)).toBeInTheDocument();
  expect(screen.getByText(/stock level unreadable/i)).toBeInTheDocument();
});

it('maps a 422 onto the field it names', async () => {
  const err = new AxiosError('422');
  err.response = {
    data: { errors: { weight: ['The weight must not be greater than 100000.'] } },
    status: 422,
    statusText: 'Unprocessable Content',
    headers: new AxiosHeaders(),
    config: { headers: new AxiosHeaders() },
  };
  useCatalogueAdminStore.setState({ resolveBlockers: vi.fn().mockRejectedValue(err) });
  renderModal(item({ cannot_publish_reasons: ['missing_dimensions'] }));

  await userEvent.type(screen.getByLabelText(/length/i), '100');
  await userEvent.type(screen.getByLabelText(/width/i), '80');
  await userEvent.type(screen.getByLabelText(/height/i), '60');
  await userEvent.type(screen.getByLabelText(/weight/i), '250');
  await userEvent.click(screen.getByRole('button', { name: /save and publish/i }));

  expect(await screen.findByText(/must not be greater than 100000/i)).toBeInTheDocument();
  // Modal stays open - the typed work is still there.
  expect(screen.getByLabelText(/length/i)).toHaveValue('100');
});
```

- [ ] **Step 2: Run them to make sure they fail**

```bash
cd frontend && npx vitest run src/components/admin/ResolveBlockersModal.test.tsx
```

Expected: FAIL — cannot resolve `./ResolveBlockersModal`.

- [ ] **Step 3: Implement the component**

Create `frontend/src/components/admin/ResolveBlockersModal.tsx`:

```tsx
import { useState } from 'react';
import { Badge, Button, Input, Modal, Select } from '../../ui';
import { apiFieldErrors } from '../../lib/api';
import { useCatalogueAdminStore, type ResolveBlockersPayload } from '../../stores/catalogueAdminStore';
import type { AdminCatalogueItem } from '../../types';

/**
 * The scraped-gate blockers a staffer can clear by typing a fact off the source
 * listing. Everything else (stock_unreadable, source_dead, needs_re-review) is
 * source-truth and resolves on the next sync - see the design spec.
 */
export const FIXABLE_BLOCKERS = ['missing_dimensions', 'not_printable', 'missing_price'] as const;

export function isFixableBlocker(token: string): boolean {
  return (FIXABLE_BLOCKERS as readonly string[]).includes(token);
}

/** Mirrors the server's sanity ceilings so a typo fails before a round-trip. */
const LIMITS = {
  dimension: { min: 0, max: 2000, unit: 'mm' },
  weight: { min: 0, max: 100000, unit: 'g' },
  price: { min: 0, max: 1000000, unit: 'SGD' },
} as const;

const BLOCKER_EXPLANATIONS: Record<string, string> = {
  stock_unreadable: 'Stock comes from the source listing - it resolves on the next sync.',
  source_dead: 'The source listing is gone. Re-capture the product or archive it.',
  'needs_re-review': 'The source price moved past the drift threshold. It re-checks on the next sync.',
};

interface Props {
  product: AdminCatalogueItem;
  open: boolean;
  onClose: () => void;
  /** Called after a successful save; `published` reflects the server's verdict. */
  onResolved: (published: boolean) => void;
}

function parsePositive(raw: string, max: number): number | 'empty' | 'invalid' {
  const trimmed = raw.trim();
  if (trimmed === '') return 'empty';
  const value = Number(trimmed);
  if (!Number.isFinite(value) || value <= 0 || value > max) return 'invalid';
  return value;
}

export default function ResolveBlockersModal({ product, open, onClose, onResolved }: Props) {
  const resolveBlockers = useCatalogueAdminStore((s) => s.resolveBlockers);

  const reasons = product.cannot_publish_reasons ?? [];
  const needsDims = reasons.includes('missing_dimensions');
  const needsPrintable = reasons.includes('not_printable');
  const needsPrice = reasons.includes('missing_price');

  const [length, setLength] = useState(String(product.dimensions?.l ?? ''));
  const [width, setWidth] = useState(String(product.dimensions?.w ?? ''));
  const [height, setHeight] = useState(String(product.dimensions?.h ?? ''));
  const [weight, setWeight] = useState(product.weight ?? '');
  const [printMethod, setPrintMethod] = useState(product.print_method ?? 'UV');
  const [baseCost, setBaseCost] = useState(needsPrice ? '' : (product.base_cost ?? ''));

  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  /** Set when the save persisted but the row is still blocked by source-truth. */
  const [remaining, setRemaining] = useState<string[] | null>(null);

  const submit = async () => {
    const nextErrors: Record<string, string> = {};
    const payload: ResolveBlockersPayload = {};

    if (needsDims) {
      const dims = { l: length, w: width, h: height };
      const parsed: Record<string, number> = {};
      (Object.keys(dims) as Array<keyof typeof dims>).forEach((key) => {
        const value = parsePositive(dims[key], LIMITS.dimension.max);
        if (value === 'empty') nextErrors[`dimensions.${key}`] = 'Required.';
        else if (value === 'invalid')
          nextErrors[`dimensions.${key}`] = `Enter a number between 1 and ${LIMITS.dimension.max} mm.`;
        else parsed[key] = value;
      });

      const w = parsePositive(weight, LIMITS.weight.max);
      if (w === 'empty') nextErrors.weight = 'Required.';
      else if (w === 'invalid') nextErrors.weight = `Enter a number between 1 and ${LIMITS.weight.max} g.`;
      else payload.weight = w;

      if (Object.keys(parsed).length === 3) {
        payload.dimensions = { l: parsed.l, w: parsed.w, h: parsed.h };
      }
    }

    if (needsPrintable) {
      payload.is_printable = true;
      payload.print_method = printMethod;
    }

    if (needsPrice) {
      const price = parsePositive(baseCost, LIMITS.price.max);
      if (price === 'empty') nextErrors.base_cost = 'Required.';
      else if (price === 'invalid') nextErrors.base_cost = 'Enter a price greater than 0.';
      else payload.base_cost = price;
    }

    setErrors(nextErrors);
    if (Object.keys(nextErrors).length > 0) return;

    setBusy(true);
    try {
      const result = await resolveBlockers(product.id, payload);
      if (result.published) {
        onResolved(true);
        onClose();
        return;
      }
      // Saved, but a source-truth blocker survives. Keep the popup open and say so.
      setRemaining(result.cannot_publish_reasons ?? []);
      onResolved(false);
    } catch (err) {
      setErrors(apiFieldErrors(err));
    } finally {
      setBusy(false);
    }
  };

  if (remaining !== null) {
    return (
      <Modal
        open={open}
        onClose={onClose}
        title="Saved, but still blocked"
        description={product.name}
        footer={<Button onClick={onClose}>Close</Button>}
      >
        <div className="flex flex-col gap-3">
          <p className="text-sm text-fg-muted">
            Your changes were saved. These blockers can&apos;t be fixed here:
          </p>
          <ul className="flex flex-col gap-2">
            {remaining.map((token) => (
              <li key={token} className="flex flex-col gap-1">
                <Badge tone="warning" size="sm">
                  {token === 'stock_unreadable'
                    ? 'Stock level unreadable'
                    : token === 'source_dead'
                      ? 'Source listing gone'
                      : token === 'needs_re-review'
                        ? 'Needs re-review'
                        : token}
                </Badge>
                {BLOCKER_EXPLANATIONS[token] && (
                  <span className="text-sm text-fg-subtle">{BLOCKER_EXPLANATIONS[token]}</span>
                )}
              </li>
            ))}
          </ul>
        </div>
      </Modal>
    );
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Resolve blockers"
      description={product.name}
      footer={
        <>
          <Button variant="ghost" onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button onClick={() => void submit()} loading={busy} disabled={busy}>
            Save and publish
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        {needsDims && (
          <div className="flex flex-col gap-3">
            <div className="grid grid-cols-3 gap-3">
              <Input
                label="Length (mm)"
                inputMode="decimal"
                value={length}
                error={errors['dimensions.l']}
                onChange={(e) => setLength(e.target.value)}
              />
              <Input
                label="Width (mm)"
                inputMode="decimal"
                value={width}
                error={errors['dimensions.w']}
                onChange={(e) => setWidth(e.target.value)}
              />
              <Input
                label="Height (mm)"
                inputMode="decimal"
                value={height}
                error={errors['dimensions.h']}
                onChange={(e) => setHeight(e.target.value)}
              />
            </div>
            <Input
              label="Weight (g)"
              inputMode="decimal"
              value={weight}
              error={errors.weight}
              hint="Per unit, in grams."
              onChange={(e) => setWeight(e.target.value)}
            />
          </div>
        )}

        {needsPrintable && (
          <Select
            label="Print method"
            value={printMethod}
            error={errors.print_method}
            hint="Marks the blank printable."
            options={[
              { value: 'UV', label: 'UV (decorate a sourced blank)' },
              { value: 'FDM', label: 'FDM (filament)' },
              { value: 'RESIN', label: 'Resin' },
            ]}
            onChange={(e) => setPrintMethod(e.target.value as 'UV' | 'FDM' | 'RESIN')}
          />
        )}

        {needsPrice && (
          <Input
            label="Base cost (SGD)"
            inputMode="decimal"
            value={baseCost}
            error={errors.base_cost}
            hint="What we pay the supplier per unit."
            onChange={(e) => setBaseCost(e.target.value)}
          />
        )}
      </div>
    </Modal>
  );
}
```

- [ ] **Step 4: Run the tests**

```bash
cd frontend && npx vitest run src/components/admin/ResolveBlockersModal.test.tsx
```

Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/components/admin/ResolveBlockersModal.tsx frontend/src/components/admin/ResolveBlockersModal.test.tsx
git commit -m "feat(catalogue-gate): add ResolveBlockersModal

Renders only the field groups the row's blockers name; mirrors the server's
sanity bounds client-side and maps 422s back onto the fields they name."
```

---

## Task 7: Wire the modal into the gate

**Files:**
- Modify: `frontend/src/pages/CatalogueAdminPage.tsx` (`:59-74`, `:694-704`, `:735-741`)

- [ ] **Step 1: Import and add state**

Add to the imports:

```tsx
import ResolveBlockersModal, { isFixableBlocker } from '../components/admin/ResolveBlockersModal';
import { Tooltip } from '../ui';
```

`Tooltip` may already be importable from the `'../ui'` barrel line at `:7` — add it there instead of a second import if so.

Inside `CatalogueAdminPage`, alongside the existing `quickViewId` state:

```tsx
  const [blockersFor, setBlockersFor] = useState<AdminCatalogueItem | null>(null);
```

- [ ] **Step 2: Add the fixable-row helper**

Next to `hasInlineTools` (`:68-74`):

```tsx
/** A SCRAPED_UV row with at least one blocker staff can clear in the popup. */
function hasFixableBlockers(item: AdminCatalogueItem): boolean {
  return (
    item.class === 'SCRAPED_UV' && (item.cannot_publish_reasons?.some(isFixableBlocker) ?? false)
  );
}
```

- [ ] **Step 3: Make the fixable badges buttons**

Replace the blockers cell (`:694-704`):

```tsx
                <div className="flex min-w-0 flex-wrap gap-1.5">
                  {it.cannot_publish_reasons?.length ? (
                    it.cannot_publish_reasons.map((r) =>
                      it.class === 'SCRAPED_UV' && isFixableBlocker(r) ? (
                        <button
                          key={r}
                          type="button"
                          onClick={() => setBlockersFor(it)}
                          aria-label={`Fix: ${blockerLabel(r)} on ${it.name}`}
                          className="rounded-full focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        >
                          <Badge tone="warning" size="sm" className="cursor-pointer hover:opacity-80">
                            {blockerLabel(r)}
                          </Badge>
                        </button>
                      ) : (
                        <Tooltip key={r} content={blockerHelp(r)}>
                          <span tabIndex={0} className="rounded-full focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
                            <Badge tone="warning" size="sm">
                              {blockerLabel(r)}
                            </Badge>
                          </span>
                        </Tooltip>
                      ),
                    )
                  ) : (
                    <span className="text-sm text-fg-subtle">-</span>
                  )}
                </div>
```

Check `Badge` accepts a `className` prop before relying on it (`frontend/src/ui/Badge.tsx`) — if it doesn't, put the hover style on the wrapping `<button>` instead.

Add `blockerHelp` next to `blockerLabel` (`:59-66`):

```tsx
/** Why an un-fixable blocker can't be cleared from the gate. */
const BLOCKER_HELP: Record<string, string> = {
  stock_unreadable: 'Stock comes from the source listing - resolves on the next sync.',
  source_dead: 'The source listing is gone. Re-capture the product or archive it.',
  'needs_re-review': 'The source price moved past the drift threshold - re-checks on the next sync.',
};

function blockerHelp(token: string): string {
  return BLOCKER_HELP[token] ?? 'Resolved at the source on the next catalogue sync.';
}
```

- [ ] **Step 4: Update the dead-end copy**

Replace the `CANNOT_PUBLISH` branch (`:735-741`):

```tsx
                  {it.publish_state === 'CANNOT_PUBLISH' && (
                    <span className="text-xs text-fg-subtle lg:text-right">
                      {hasFixableBlockers(it)
                        ? 'Click a blocker to fix it here.'
                        : hasInlineTools(it)
                          ? 'Use the tools below to clear the blockers.'
                          : 'Fix the blockers at the source - re-checked on next sync.'}
                    </span>
                  )}
```

- [ ] **Step 5: Render the modal**

Next to `<ProductQuickView …>` (`:778-783`):

```tsx
      {blockersFor && (
        <ResolveBlockersModal
          product={blockersFor}
          open
          onClose={() => setBlockersFor(null)}
          onResolved={(published) =>
            toast(
              published
                ? { title: 'Published', description: blockersFor.name, tone: 'success' }
                : { title: 'Saved - still blocked', description: blockersFor.name, tone: 'warning' },
            )
          }
        />
      )}
```

`toast` is already destructured from `useToast()` on this page. Confirm the tone name — check `frontend/src/ui/Toast.tsx`'s `ToastTone` union and use a valid member (`'warning'` may be `'neutral'` there).

- [ ] **Step 6: Typecheck and run the full frontend suite**

```bash
cd frontend && npx tsc --noEmit && npm run test
```

Expected: PASS, no type errors.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/pages/CatalogueAdminPage.tsx
git commit -m "feat(catalogue-gate): open the resolve-blockers popup from a badge

Fixable SCRAPED_UV blockers are now buttons; source-truth ones stay inert with
a tooltip saying why. Replaces the 'fix it at the source' dead end."
```

---

## Task 8: Verify end-to-end in the running app

Tests passing is not the same as the feature working. Drive it.

- [ ] **Step 1: Seed a blocked product**

```bash
php artisan tinker --execute="\$p = App\Models\Product::factory()->scrapedUv()->create(['name' => 'Blocked Test Mug', 'publish_state' => 'CANNOT_PUBLISH', 'cannot_publish_reasons' => ['missing_dimensions', 'not_printable'], 'base_cost' => 12.00, 'dimensions' => null, 'weight' => null, 'is_printable' => false, 'print_method' => null]); echo \$p->id;"
```

- [ ] **Step 2: Start both servers and sign in as staff**

Backend: `php artisan serve`. Frontend: use the **preview tool** (`preview_start`), not a raw Bash dev server. Sign in with a `staff_admin` from `AdminUserSeeder`, then go to `/catalogue-admin`.

- [ ] **Step 3: Drive the flow**

1. Find "Blocked Test Mug". Confirm its two blocker badges are **buttons** and that a `stock_unreadable`-style badge (if present) is **not**.
2. Click "Missing dimensions or weight". Confirm the popup shows length/width/height/weight **and** print method (the row has `not_printable` too) — and **not** base cost.
3. Submit empty → each field flags inline, no request fires (check the network panel).
4. Enter `5000` for length → the client-side bound rejects it before the round trip.
5. Enter `100 / 80 / 60 / 250`, method `UV`, submit → toast "Published", row flips to **Published**, badges gone.

- [ ] **Step 4: Verify the unfixable path**

```bash
php artisan tinker --execute="\$p = App\Models\Product::factory()->scrapedUv()->create(['name' => 'Stock Unknown Mug', 'publish_state' => 'CANNOT_PUBLISH', 'cannot_publish_reasons' => ['missing_dimensions', 'stock_unreadable'], 'base_cost' => 12.00, 'dimensions' => null, 'weight' => null, 'stock_estimate' => null, 'is_printable' => true, 'print_method' => 'UV']); echo \$p->id;"
```

Fix its dimensions in the popup. Expected: **stays open**, says "Saved, but still blocked", names "Stock level unreadable" with the explanation. Reopen the row — the weight you typed **persisted**.

- [ ] **Step 5: Check the audit trail**

```bash
php artisan tinker --execute="echo App\Models\AuditLog::where('event', 'product.blockers_resolved')->latest()->first()?->toJson(JSON_PRETTY_PRINT);"
```

Expected: one row, correct `user_id`, `old_values` with the nulls, `new_values` with the typed facts.

- [ ] **Step 6: Full suite, then commit any fixes**

```bash
vendor/bin/pest && cd frontend && npm run test && npm run build
```

Expected: all green. Report the actual counts — do not claim success without the output.

---

## Self-Review Notes

Checked against the spec:

- **Spec §1 `regate()`** → Task 1. **§2 endpoint** → Task 2. **§3 row payload** → Task 3. **§4 badges→buttons** → Task 7. **§5 modal** → Task 6. **§6 `apiFieldErrors`** → Task 4. **§7 store** → Task 5. **Testing** → distributed across Tasks 1–6 plus manual verification in Task 8.
- **Two spec corrections made while reading the code, and folded back into the spec file:**
  1. The endpoint path is `/admin/products/{product}/resolve-blockers`, not `/admin/catalogue/…` — every gate endpoint uses the `/admin/products/` prefix despite living on `AdminCatalogueController` (`routes/api.php:125-141`).
  2. `AdminCatalogueController` does not inject `AuditLogger` today; Task 2 Step 4 adds it.
- **Deviation from the spec, deliberate:** `resolveBlockers` in the store **throws** rather than returning `null`, unlike its `verifyEstimates`/`uploadModelFile` siblings. The modal needs the per-field 422 bag, which a boolean discards. Commented in place.
- **Naming is consistent** across tasks: `resolveBlockers` (store + controller), `ResolveBlockersPayload` / `ResolveBlockersResult` (Task 5 → used in Task 6), `isFixableBlocker` / `FIXABLE_BLOCKERS` (Task 6 → used in Task 7), `hasFixableBlockers` (Task 7 only).
- **Three "verify before relying on it" points** are flagged inline rather than guessed: `Badge`'s `className` prop (Task 7 Step 3), `ToastTone`'s valid members (Task 7 Step 5), and whether `Tooltip` is already on the `'../ui'` barrel import (Task 7 Step 1).
