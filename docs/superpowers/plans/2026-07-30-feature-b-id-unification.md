# Feature B — Unify order id (single GL- reference) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Collapse the two order ids (`reference` + `tracking_code`) into ONE id in the `GL-` format, used everywhere.

**Architecture:** Keep the `reference` column (already the route key + primary buyer id); retro-fit its generator to emit `GL-` + 10 chars; drop the `tracking_code` column and re-point the public-tracker surface (OrderTracker, broadcast, TrackingController, QR/track frontend) to `reference`. Courier side (`consignment_ref`) is untouched and test-guarded.

**Tech Stack:** Laravel 11 / PHP 8.3, Pest v3 (SQLite test DB, MySQL `giftlab` dev DB, RefreshDatabase); React + TS + Zustand, Vitest/RTL.

**Spec:** `docs/superpowers/specs/2026-07-30-giftlab-feature-b-id-unification-design.md`

**Sequencing rule:** every task ends with the full suite green + a commit. Tasks are ordered so no task leaves a live read of a removed field: tracker repoint (T2) → resource/service (T3) → mail (T4) → model stops generating (T5) → drop column (T6). Frontend/tests fold in as noted.

---

### Task 1: `reference` generator → `GL-` format

**Files:**
- Modify: `app/Models/Quote.php` (`generateReference()`, ~line 417)
- Test: `tests/Feature/QuoteReferenceTest.php:14-15`

- [ ] **Step 1: Update the failing test**

In `tests/Feature/QuoteReferenceTest.php`, replace the length/format assertion (currently length 10, no prefix):

```php
it('assigns a unique GL- reference to every new quote', function (): void {
    $a = Quote::factory()->create();
    $b = Quote::factory()->create();

    expect($a->reference)->toStartWith('GL-')
        ->and(strlen((string) $a->reference))->toBe(13)
        ->and($a->reference)->not->toBe($b->reference);
});
```

(Keep the rest of the file — the `/api/quotes/{reference}` lookup + tenancy tests — unchanged.)

- [ ] **Step 2: Run it, verify it fails**

Run: `php artisan test --filter=QuoteReferenceTest`
Expected: FAIL — reference has no `GL-` prefix, length 10.

- [ ] **Step 3: Retro-fit the generator**

In `app/Models/Quote.php`, `generateReference()`:

```php
public static function generateReference(): string
{
    $out = 'GL-';
    for ($i = 0; $i < 10; $i++) {
        $out .= self::TRACKING_ALPHABET[random_int(0, 31)];
    }

    return $out;
}
```

Update the method's docblock to say `'GL-' + 10 chars (13 total), ~2^50 space`.

- [ ] **Step 4: Run it, verify pass**

Run: `php artisan test --filter=QuoteReferenceTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Models/Quote.php tests/Feature/QuoteReferenceTest.php
git commit -m "feat(feature-b): emit GL- format reference (GL-+10, 13 chars)"
```

---

### Task 2: Re-point the public-tracker surface to `reference`

**Files:**
- Modify: `app/Services/OrderTracker.php:30` (`payload`), `:73` (`signedFrontendLink`)
- Modify: `app/Events/OrderTrackingUpdated.php:38,42,64` (`broadcastOn`, `broadcastWith`)
- Modify: `app/Http/Controllers/TrackingController.php` (`__invoke`, `view`)
- Test: `tests/Feature/OrderTrackerTest.php:21`, `tests/Feature/SignedTrackLinkTest.php:25`, `tests/Feature/TrackingTest.php` (all)

- [ ] **Step 1: Update the failing tests**

`tests/Feature/OrderTrackerTest.php:21` — payload `reference` now sources `reference`:

```php
    expect($payload['reference'])->toBe($quote->reference)
```

`tests/Feature/SignedTrackLinkTest.php:25` — signed view resolves by `reference`:

```php
        ->assertJson(['reference' => $quote->reference, 'stage' => 'ACTION_REQUIRED']);
```
Also change the code that builds the signed link in that test to sign `$quote->reference` (search the file for `tracking_code` and swap to `reference`).

`tests/Feature/TrackingTest.php` — throughout: rename the POST param `'tracking_code'` → `'reference'`, and the id source `$quote->tracking_code` → `$quote->reference`. Specifically:
- Lines 13-18 test → assert on `reference` (GL- prefix, length 13) or delete (covered by QuoteReferenceTest); prefer converting:
```php
it('assigns an opaque GL- reference to every new quote', function (): void {
    $quote = Quote::factory()->create();
    expect($quote->reference)->toStartWith('GL-')
        ->and(strlen((string) $quote->reference))->toBe(13);
});
```
- Line 25, 44, 70, 93, 117: `'reference' => $quote->reference` (was `'tracking_code' => $quote->tracking_code`).
- Line 30: `'reference' => $quote->reference` (payload assertion — value now the reference).
- Line 44: `strtolower((string) $quote->reference)` (case-insensitive lookup).
- Line 98: unknown code `'reference' => 'GL-000000'`.

- [ ] **Step 2: Run them, verify they fail**

Run: `php artisan test --filter="OrderTracker|SignedTrackLink|TrackingTest"`
Expected: FAIL — controller still validates/looks up `tracking_code`; payload still sources `tracking_code`.

- [ ] **Step 3: Re-point `OrderTracker`**

`app/Services/OrderTracker.php`:
- Line 30: `'reference' => $quote->reference,`
- Line 73: `URL::signedRoute('track.view', ['code' => $quote->reference], null, false);`

- [ ] **Step 4: Re-point `OrderTrackingUpdated`**

`app/Events/OrderTrackingUpdated.php`:
- `broadcastOn()`: guard `if (empty($this->quote->reference)) { return []; }` and `new Channel("track.{$this->quote->reference}")`.
- `broadcastWith()`: `'reference' => $this->quote->reference,`.

- [ ] **Step 5: Re-point `TrackingController`**

`app/Http/Controllers/TrackingController.php`:
- `__invoke()`: validate `'reference' => ['required', 'string', 'max:16']` (drop `tracking_code`); `$code = strtoupper(trim($data['reference']));`; `->where('reference', $code)`.
- `view()`: `->where('reference', $code)` (the signed `code` query already carries the reference after Task 2 Step 3).
- Keep the email-prefix second factor + generic-404 anti-enumeration UNCHANGED.

- [ ] **Step 6: Run, verify pass**

Run: `php artisan test --filter="OrderTracker|SignedTrackLink|TrackingTest"`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Services/OrderTracker.php app/Events/OrderTrackingUpdated.php app/Http/Controllers/TrackingController.php tests/Feature/OrderTrackerTest.php tests/Feature/SignedTrackLinkTest.php tests/Feature/TrackingTest.php
git commit -m "feat(feature-b): public tracker resolves by unified reference"
```

---

### Task 3: `QuoteResource` drop key + `ShipmentService` ref

**Files:**
- Modify: `app/Http/Resources/QuoteResource.php:32`
- Modify: `app/Services/ShipmentService.php:79`

- [ ] **Step 1: Drop the resource key**

`app/Http/Resources/QuoteResource.php` — remove the `'tracking_code' => $this->tracking_code,` line (32) and its comment (31). Keep `reference` (30) and `tracking_link` (34).

- [ ] **Step 2: Re-point the courier payload ref**

`app/Services/ShipmentService.php:79`:

```php
            reference: (string) $quote->reference,
```

(`reference` is always assigned on create — the `?? $quote->id` fallback is dropped.)

- [ ] **Step 3: Run the affected suites, verify green**

Run: `php artisan test --filter="QuoteReferenceExposure|CreateShipment|OrderShipmentVisibility"`
Expected: PASS (no test asserts `data.tracking_code`; the courier tests assert `consignment_ref`, not this ref label).

- [ ] **Step 4: Commit**

```bash
git add app/Http/Resources/QuoteResource.php app/Services/ShipmentService.php
git commit -m "feat(feature-b): drop tracking_code from resource; courier ref uses reference"
```

---

### Task 4: Mail — collapse the two id rows

**Files:**
- Modify: `resources/views/mail/quote-ready.blade.php:83-99`
- Test: `tests/Feature/QuoteReadyMailTest.php:65,74-76`

- [ ] **Step 1: Update the failing test**

`tests/Feature/QuoteReadyMailTest.php` — keep the `reference` assertion (line 65) and the `/orders/{reference}` link assertion (53). DELETE the tracking_code assertions:

```php
    // (removed) expect($quote->tracking_code)->not->toBeNull();
    // (removed) $mail->assertSeeInHtml($quote->tracking_code, false);
```
Add an assertion that the retired id row is gone:
```php
    $mail->assertDontSeeInHtml('Tracking code', false);
```

- [ ] **Step 2: Run it, verify it fails**

Run: `php artisan test --filter=QuoteReadyMailTest`
Expected: FAIL — blade still renders the "Tracking code" row.

- [ ] **Step 3: Collapse the blade rows**

In `resources/views/mail/quote-ready.blade.php`, remove the entire "Tracking code" table row (the `<tr>` around lines 90-99 that renders `{{ $quote->tracking_code }}`) and its lead-in comment. Keep the "Order reference" row (`{{ $quote->reference }}`, ~line 88). Update the kept row's comment to note it is now the single order id.

- [ ] **Step 4: Run it, verify pass**

Run: `php artisan test --filter=QuoteReadyMailTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/views/mail/quote-ready.blade.php tests/Feature/QuoteReadyMailTest.php
git commit -m "feat(feature-b): quote-ready email shows single reference id"
```

---

### Task 5: Model stops generating `tracking_code`

**Files:**
- Modify: `app/Models/Quote.php` (`$fillable` ~59, `static::creating` ~129-136, remove `generateTrackingCode()` ~400-411)

- [ ] **Step 1: Remove `tracking_code` generation**

`app/Models/Quote.php`:
- `$fillable`: delete the `'tracking_code',` entry.
- `static::creating`: delete the `if (empty($quote->tracking_code)) { ... }` block (keep the `reference` block).
- Delete the `generateTrackingCode()` method. Keep `TRACKING_ALPHABET` (used by `generateReference`) and the `GL-` docblock note if any.

- [ ] **Step 2: Run the tracker + reference suites, verify green**

Run: `php artisan test --filter="Tracking|OrderTracker|SignedTrackLink|QuoteReference|QuoteReadyMail"`
Expected: PASS — nothing reads `tracking_code` anymore (column still exists, now null on new rows; no assertion touches it).

- [ ] **Step 3: Commit**

```bash
git add app/Models/Quote.php
git commit -m "feat(feature-b): stop generating tracking_code on Quote create"
```

---

### Task 6: Migration — drop the `tracking_code` column

**Files:**
- Create: `database/migrations/2026_07_30_000002_drop_tracking_code_from_quotes.php`

- [ ] **Step 1: Write the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feature B: the order id is unified onto `reference` (GL- format). The separate
 * public tracking token is retired — the tracker, QR and broadcast now key off
 * `reference`, gated by the same buyer-email check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->dropUnique(['tracking_code']);
            $table->dropColumn('tracking_code');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->string('tracking_code', 16)->nullable()->unique()->after('id');
        });
    }
};
```

- [ ] **Step 2: Run the migration on the dev DB**

Run: `php artisan migrate` (MySQL `giftlab`; the test DB is migrated fresh by RefreshDatabase).
Expected: `DONE`. Walkthrough/seeded rows keep their existing `reference`; only `tracking_code` is dropped.

- [ ] **Step 3: Run the full backend suite, verify green**

Run: `php artisan test`
Expected: PASS — column gone, no code reads it. (If any factory/seeder/test still writes `tracking_code`, it surfaces here; grep and fix.)

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_07_30_000002_drop_tracking_code_from_quotes.php
git commit -m "feat(feature-b): drop retired tracking_code column"
```

---

### Task 7: Courier-untouched guard test

**Files:**
- Test: `tests/Feature/NinjaVanWebhookTest.php` (add one test)

- [ ] **Step 1: Add the guard test**

Append to `tests/Feature/NinjaVanWebhookTest.php`, reusing its existing `ninjaVanShippedJob()` helper + `postNinjaVanWebhook()` builder. Concrete test:

```php
// Feature B guard: the courier side keys on consignment_ref (the NinjaVan
// tracking number), NEVER on the buyer-facing order id. Unifying reference +
// tracking_code into one order id must not touch webhook matching.
it('resolves the webhook parcel by consignment_ref, independent of the order id', function (): void {
    $job = ninjaVanShippedJob('NVSGNEXGE000FEATB1');
    $quote = $job->quote()->first();

    // The unified order id (reference) is unrelated to the courier ref: a
    // webhook carrying the order id must NOT match; only consignment_ref does.
    postNinjaVanWebhook([
        'tracking_number' => (string) $quote->reference,
        'status' => 'Delivered',
    ])->assertOk()->assertJson(['received' => true]);
    expect($job->fresh()->state)->toBe(JobState::Shipped); // untouched — no match on the order id

    // The correct consignment_ref matches and drives the job to delivered.
    postNinjaVanWebhook([
        'tracking_number' => 'NVSGNEXGE000FEATB1',
        'status' => 'Delivered',
    ])->assertOk()->assertJson(['received' => true]);

    $job->refresh();
    expect($job->state)->toBe(JobState::Closed)
        ->and($job->last_courier_status)->toBe('Delivered');
});
```

- [ ] **Step 2: Run it, verify pass**

Run: `php artisan test --filter=NinjaVanWebhook`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/NinjaVanWebhookTest.php
git commit -m "test(feature-b): assert courier webhook keys on consignment_ref, not the order id"
```

---

### Task 8: Frontend — types, TrackPage, QuoteDetailPage

**Files:**
- Modify: `frontend/src/types.ts:360`
- Modify: `frontend/src/pages/TrackPage.tsx`
- Modify: `frontend/src/pages/QuoteDetailPage.tsx:1214-1217`
- Test: `frontend/src/pages/TrackPage.test.tsx` (if present) + `QuoteDetailPage.test.tsx`

- [ ] **Step 1: Drop `tracking_code` from the type**

`frontend/src/types.ts` — remove `tracking_code?: string | null;` (line 360) from the `Quote` interface. Keep `reference` (358) and `TrackResult.reference`.

- [ ] **Step 2: Re-point `TrackPage`**

`frontend/src/pages/TrackPage.tsx`:
- Input label `"Tracking code"` → `"Order reference"`; placeholder `"GL-XXXXXX"` → `"GL-XXXXXXXXXX"`.
- POST body: `api.post('/track', { reference: code.trim(), email: email.trim() })`.
- localStorage: rename `gl.track.code` → `gl.track.reference` (init read + write). Header copy: "Enter the order reference from your confirmation…".
- Leave the `result.reference` channel subscription untouched.

- [ ] **Step 3: Re-point `QuoteDetailPage`**

`frontend/src/pages/QuoteDetailPage.tsx:1214-1217` — replace the `quote.tracking_code` block with the single `quote.reference` (relabel the surrounding caption to "Order reference" if it says "Tracking code").

- [ ] **Step 4: Update / add component tests**

In `TrackPage.test.tsx` (create if absent): assert the form POSTs `{ reference, email }` and renders a result on success. In `QuoteDetailPage.test.tsx`: assert the single reference renders and no `tracking_code` is referenced.

- [ ] **Step 5: Run frontend suite + tsc, verify green**

Run: `cd frontend; npm run test -- --run; npx tsc --noEmit`
Expected: tests PASS, tsc clean (no `tracking_code` on `Quote`).

- [ ] **Step 6: Commit**

```bash
git add frontend/src/types.ts frontend/src/pages/TrackPage.tsx frontend/src/pages/QuoteDetailPage.tsx frontend/src/pages/TrackPage.test.tsx frontend/src/pages/QuoteDetailPage.test.tsx
git commit -m "feat(feature-b): frontend uses single reference id on track + order pages"
```

---

### Task 9: Full regression + live verify

**Files:** none (verification only)

- [ ] **Step 1: No lingering live `tracking_code`**

Run: `grep -rn "tracking_code" app/ database/migrations/2026_07_30_000002_drop_tracking_code_from_quotes.php database/factories/ resources/ routes/ frontend/src/`
Expected: only the new drop-migration's `down()` re-add references `tracking_code`. Nothing else live. (Old migrations `..._000024_...` / `..._062127_...` legitimately retain it — historical.)

- [ ] **Step 2: Full backend suite**

Run: `php artisan test`
Expected: all PASS.

- [ ] **Step 3: Full frontend suite + tsc**

Run: `cd frontend; npm run test -- --run; npx tsc --noEmit`
Expected: all PASS, tsc clean.

- [ ] **Step 4: Live smoke**

`preview_start api` + `preview_start frontend`; open `http://localhost:5173` (NOT 127.0.0.1 — Sanctum cookie host-bound). Verify:
- An order page shows ONE id (`GL-…`); QR renders.
- Public `/track`: entering the order's `reference` + buyer-email prefix resolves; a wrong email prefix → generic "No order matches those details."
- The emailed order (mail log / Mailpit) shows one id.
Capture a screenshot as proof.

- [ ] **Step 5: Final review + finish**

Dispatch a final code-reviewer over the whole branch, then invoke `superpowers:finishing-a-development-branch`. No push/PR unless the user asks.

---

## Notes for the implementer

- Do NOT delete or disturb seeded / walkthrough data. The drop-migration only removes one column.
- Existing rows keep their pre-Feature-B 10-char `reference` values; only new orders get `GL-…`. A mixed set is expected and both resolve. (Re-formatting old rows is a separate, out-of-scope backfill.)
- Courier (`consignment_ref`) is off-limits — Task 7 exists to prove it stays off-limits.
- The public-tracker payload key is still named `reference`; the frontend contract does not change shape, only the value's source.
