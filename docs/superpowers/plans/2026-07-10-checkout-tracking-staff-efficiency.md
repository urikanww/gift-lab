# Checkout/Tracking UX + Staff-Efficiency Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship 7 features — richer public order tracking (one-click link + QR, carrier links, promised date, partial-shipment progress) and lower production-staff click-cost on the shared queue (auto-advance on download, batch advance, scan-to-advance).

**Architecture:** Backend is Laravel 11 (PHP 8, `declare(strict_types=1)`, Pest tests). The public tracker's PII-free payload is centralized in a new `App\Services\OrderTracker` service that both the existing `POST /track` and a new signed `GET /track/view` delegate to. Production-queue efficiency adds two endpoints (`advance-batch`, `advance-next`) reusing `QueueService::advance`. Frontend is React 18 + Vite + Zustand + Vitest; two new deps (`qrcode`, `html5-qrcode`).

**Tech Stack:** Laravel 11, Pest, Eloquent enums, Laravel signed URLs; React 18, Zustand, axios, laravel-echo, Vitest, Testing Library.

**Reference spec:** `docs/superpowers/specs/2026-07-10-checkout-tracking-staff-efficiency-design.md`

**Conventions to match (read before starting):**
- Every PHP file starts with `<?php\n\ndeclare(strict_types=1);`.
- Enums are backed string enums (see `app/Enums/PrintMethod.php`).
- Tests are Pest `it('...', function () {...})` (see `tests/Feature/TrackingTest.php`, `tests/Feature/ProductionQueueTest.php`).
- Run backend tests: `php artisan test --filter=<name>` (from repo root).
- Run frontend tests: `cd frontend && npx vitest run <path>`.

---

## File Structure

**Backend — create:**
- `app/Services/OrderTracker.php` — builds the PII-free tracking payload + signed link.
- `app/Enums/Carrier.php` — courier identity + tracking-URL template.
- `database/migrations/2026_07_10_000001_add_carrier_to_production_jobs.php` — `production_jobs.carrier`.
- `app/Http/Requests/AdvanceBatchRequest.php` — validates batch advance.
- `tests/Feature/OrderTrackerTest.php`, `tests/Feature/SignedTrackLinkTest.php`, `tests/Feature/BatchAdvanceTest.php`, `tests/Feature/AdvanceNextTest.php`, `tests/Feature/AutoAdvanceOnDownloadTest.php`.

**Backend — modify:**
- `app/Http/Controllers/TrackingController.php` — delegate to `OrderTracker`; add `view()`.
- `app/Models/ProductionJob.php` — cast `carrier`, add to `$fillable`.
- `app/Services/QueueService.php` — `advance(..., ?Carrier $carrier = null)`; add `advanceNext`, `advanceBatch`.
- `app/Http/Requests/AdvanceJobRequest.php` — optional `carrier`.
- `app/Http/Controllers/ProductionQueueController.php` — pass carrier; auto-advance in `printFile`; add `advanceBatch`, `advanceNext`.
- `app/Http/Resources/QuoteResource.php` — expose `tracking_link`.
- `routes/api.php` — `GET /track/view`, `POST /production-jobs/advance-batch`, `POST /production-jobs/{job}/advance-next`.

**Frontend — create:**
- `frontend/src/pages/TrackViewPage.tsx` — signed deep-link view.
- `frontend/src/components/JobLabel.tsx` — printable label with QR.
- `frontend/src/lib/scan.ts` — camera-scan helper (html5-qrcode wrapper).

**Frontend — modify:**
- `frontend/src/types.ts` — extend `TrackResult`, `ProductionJob`, add `Carrier`.
- `frontend/src/pages/TrackPage.tsx` — localStorage prefill, needed_by, partial, carrier links.
- `frontend/src/stores/queueStore.ts` — `advance(carrier?)`, `advanceBatch`, `advanceNext`.
- `frontend/src/pages/ProductionQueuePage.tsx` — batch select bar, scan input + camera mode, carrier select in ship dialog, label print.
- `frontend/src/pages/CheckoutPage.tsx` + `frontend/src/pages/QuoteDetailPage.tsx` — Track button + QR.
- `frontend/src/App.tsx` (or router file) — route `/track/view`.
- `frontend/package.json` — add `qrcode`, `html5-qrcode`.

---

## Task 1: `OrderTracker` service (foundation + refactor)

Extracts the tracking payload from `TrackingController` so both `/track` and the new `/track/view` share one PII boundary. This task is a pure refactor — the payload shape stays identical to today; later tasks add fields.

**Files:**
- Create: `app/Services/OrderTracker.php`
- Create: `tests/Feature/OrderTrackerTest.php`
- Modify: `app/Http/Controllers/TrackingController.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/OrderTrackerTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Quote;
use App\Services\OrderTracker;

it('builds a PII-free payload for a quote', function (): void {
    $company = Company::factory()->create(['billing_email' => 'buyer@acme.com']);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'state' => 'SENT']);

    $payload = app(OrderTracker::class)->payload($quote->fresh());

    expect($payload['reference'])->toBe($quote->tracking_code)
        ->and($payload['stage'])->toBe('REVIEW')
        ->and($payload['stage_label'])->toBe('In review')
        ->and($payload)->not->toHaveKeys(['total', 'subtotal', 'notes']);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=OrderTrackerTest`
Expected: FAIL — `Class "App\Services\OrderTracker" not found`.

- [ ] **Step 3: Create the service**

Create `app/Services/OrderTracker.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Quote;

/**
 * Single source of truth for the public, login-free tracking payload. Both the
 * POST /track lookup and the signed GET /track/view deep-link delegate here, so
 * the PII-free contract (status/dates/counts only - no pricing, line detail, or
 * addresses) lives in exactly one place.
 */
final class OrderTracker
{
    /**
     * @return array<string, mixed>
     */
    public function payload(Quote $quote): array
    {
        $labels = Quote::TRACKING_STAGE_LABELS;
        $stage = $quote->trackingStage();

        return [
            'reference' => $quote->tracking_code,
            'stage' => $stage,
            'stage_label' => $quote->trackingStageLabel(),
            'cancelled' => $stage === 'CANCELLED',
            'stages' => array_map(
                static fn (string $c, string $l): array => ['code' => $c, 'label' => $l],
                array_keys($labels),
                array_values($labels),
            ),
            'placed_at' => $quote->created_at?->toIso8601String(),
            'updated_at' => $quote->updated_at?->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=OrderTrackerTest`
Expected: PASS.

- [ ] **Step 5: Point the controller at the service**

Replace the response block in `app/Http/Controllers/TrackingController.php`. The `__invoke` method keeps its validation + code/email checks; only the final `response()->json([...])` changes. Replace lines 53-68 (the `$stage = ...` through the `return response()->json([...]);`) with:

```php
        return response()->json(app(\App\Services\OrderTracker::class)->payload($quote));
```

- [ ] **Step 6: Run the existing tracking tests to confirm no regression**

Run: `php artisan test --filter=TrackingTest`
Expected: PASS (all pre-existing tracking assertions still hold).

- [ ] **Step 7: Commit**

```bash
git add app/Services/OrderTracker.php tests/Feature/OrderTrackerTest.php app/Http/Controllers/TrackingController.php
git commit -m "refactor(tracking): extract payload into OrderTracker service"
```

---

## Task 2: Promised date (#4) + partial-shipment counts (#5) in payload

**Files:**
- Modify: `app/Services/OrderTracker.php`
- Modify: `tests/Feature/OrderTrackerTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/OrderTrackerTest.php`:

```php
use App\Enums\JobState;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Proof;
use App\Services\QueueService;

it('exposes needed_by and item counts', function (): void {
    $company = Company::factory()->create(['billing_email' => 'buyer@acme.com']);
    $product = Product::factory()->create(['class' => 'CORE', 'print_method' => 'UV']);
    $quote = Quote::factory()->create([
        'company_id' => $company->id,
        'state' => 'PROCURING',
        'needed_by' => '2026-08-15',
    ]);
    Proof::factory()->approved()->create(['quote_id' => $quote->id]);
    LineItem::factory()->ready()->create(['quote_id' => $quote->id, 'product_id' => $product->id, 'qty' => 5]);

    $queue = app(QueueService::class);
    $job = $queue->buildJobsForQuote($quote->load('lineItems.product'))->first();

    // Before shipping: 1 line, 0 completed.
    $before = app(OrderTracker::class)->payload($quote->fresh());
    expect($before['needed_by'])->toBe('2026-08-15')
        ->and($before['items_total'])->toBe(1)
        ->and($before['items_completed'])->toBe(0);

    // Ship then close the job: line is completed.
    $queue->advance($job, JobState::InProduction);
    $queue->advance($job, JobState::Shipped, 'SP123456789SG');
    $after = app(OrderTracker::class)->payload($quote->fresh());
    expect($after['items_completed'])->toBe(1);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=OrderTrackerTest`
Expected: FAIL — `Undefined array key "needed_by"`.

- [ ] **Step 3: Add the fields to the payload**

In `app/Services/OrderTracker.php`, add these keys to the returned array (after `'updated_at'`):

```php
            'needed_by' => $quote->needed_by?->toDateString(),
            'items_total' => $this->itemsTotal($quote),
            'items_completed' => $this->itemsCompleted($quote),
```

And add these private methods to the class:

```php
    private function itemsTotal(Quote $quote): int
    {
        return $quote->lineItems()->count();
    }

    /**
     * A line item counts as completed once its production job is SHIPPED or
     * CLOSED. Counts only - never line detail - so the tracker stays PII-free.
     */
    private function itemsCompleted(Quote $quote): int
    {
        return $quote->lineItems()
            ->whereHas('job', fn ($q) => $q->whereIn('state', [
                \App\Enums\JobState::Shipped->value,
                \App\Enums\JobState::Closed->value,
            ]))
            ->count();
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=OrderTrackerTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/OrderTracker.php tests/Feature/OrderTrackerTest.php
git commit -m "feat(tracking): expose needed_by and partial item counts"
```

---

## Task 3: Carrier passthrough (#3)

**Files:**
- Create: `app/Enums/Carrier.php`
- Create: `database/migrations/2026_07_10_000001_add_carrier_to_production_jobs.php`
- Modify: `app/Models/ProductionJob.php`
- Modify: `app/Http/Requests/AdvanceJobRequest.php`
- Modify: `app/Services/QueueService.php`
- Modify: `app/Http/Controllers/ProductionQueueController.php`
- Modify: `app/Services/OrderTracker.php`
- Create: `tests/Feature/CarrierTrackingTest.php`

- [ ] **Step 1: Write the failing enum test**

Create `tests/Feature/CarrierTrackingTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\Carrier;

it('builds a tracking url from the ref, url-encoding it', function (): void {
    expect(Carrier::NinjaVan->trackingUrl('NV 12/34'))
        ->toContain('NV%2012%2F34')
        ->and(Carrier::Other->trackingUrl('X'))->toBeNull()
        ->and(Carrier::SingPost->label())->toBe('SingPost');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CarrierTrackingTest`
Expected: FAIL — `Class "App\Enums\Carrier" not found`.

- [ ] **Step 3: Create the enum**

Create `app/Enums/Carrier.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Courier a shipped job was handed to. Drives the buyer-facing "track with
 * {carrier}" link on the public tracker. `Other` carries no template (the raw
 * consignment ref is shown as copyable text).
 */
enum Carrier: string
{
    case SingPost = 'SINGPOST';
    case NinjaVan = 'NINJAVAN';
    case JnT = 'JNT';
    case Qxpress = 'QXPRESS';
    case Dhl = 'DHL';
    case FedEx = 'FEDEX';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::SingPost => 'SingPost',
            self::NinjaVan => 'Ninja Van',
            self::JnT => 'J&T Express',
            self::Qxpress => 'Qxpress',
            self::Dhl => 'DHL',
            self::FedEx => 'FedEx',
            self::Other => 'Other',
        };
    }

    /** URL template per carrier; null when no self-serve tracking page applies. */
    public function trackingUrl(string $ref): ?string
    {
        $enc = rawurlencode($ref);

        return match ($this) {
            self::SingPost => "https://www.singpost.com/track-items?trackingNumber={$enc}",
            self::NinjaVan => "https://www.ninjavan.co/en-sg/tracking?id={$enc}",
            self::JnT => "https://www.jtexpress.sg/index/query/gzquery.html?bills={$enc}",
            self::Qxpress => "https://www.qxpress.net/Tracking/Tracking.aspx?bill_no={$enc}",
            self::Dhl => "https://www.dhl.com/sg-en/home/tracking.html?tracking-id={$enc}",
            self::FedEx => "https://www.fedex.com/fedextrack/?trknbr={$enc}",
            self::Other => null,
        };
    }
}
```

- [ ] **Step 4: Run enum test to verify it passes**

Run: `php artisan test --filter=CarrierTrackingTest`
Expected: PASS.

- [ ] **Step 5: Create the migration**

Create `database/migrations/2026_07_10_000001_add_carrier_to_production_jobs.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Courier a job was shipped with, captured alongside consignment_ref at the
 * SHIPPED transition. Powers the buyer-facing carrier tracking link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_jobs', function (Blueprint $table): void {
            $table->string('carrier', 32)->nullable()->after('consignment_ref');
        });
    }

    public function down(): void
    {
        Schema::table('production_jobs', function (Blueprint $table): void {
            $table->dropColumn('carrier');
        });
    }
};
```

- [ ] **Step 6: Run the migration**

Run: `php artisan migrate`
Expected: migration `2026_07_10_000001_add_carrier_to_production_jobs` runs successfully.

- [ ] **Step 7: Wire the model**

In `app/Models/ProductionJob.php`: add `'carrier'` to `$fillable` (after `'consignment_ref'`), and add to the `casts()` array:

```php
            'carrier' => \App\Enums\Carrier::class,
```

- [ ] **Step 8: Accept carrier on advance request**

In `app/Http/Requests/AdvanceJobRequest.php`, add to `rules()`:

```php
            'carrier' => ['nullable', new \Illuminate\Validation\Rules\Enum(\App\Enums\Carrier::class)],
```

- [ ] **Step 9: Persist carrier in QueueService::advance**

In `app/Services/QueueService.php`, change the `advance` signature and the shipped block:

```php
    public function advance(
        ProductionJob $job,
        JobState $target,
        ?string $consignmentRef = null,
        ?\App\Enums\Carrier $carrier = null,
    ): ProductionJob {
        $from = $job->state->value;

        if ($target === JobState::Shipped && $consignmentRef !== null) {
            $job->consignment_ref = $consignmentRef;
            if ($carrier !== null) {
                $job->carrier = $carrier;
            }
        }
```

(Leave the rest of the method unchanged.)

- [ ] **Step 10: Pass carrier from the controller**

In `app/Http/Controllers/ProductionQueueController.php`, `advance()` method, replace the `$job = $this->queue->advance(...)` call with:

```php
        $carrierInput = $request->input('carrier');
        $carrier = $carrierInput !== null ? \App\Enums\Carrier::from((string) $carrierInput) : null;
        $job = $this->queue->advance(
            $job,
            $target,
            $consignmentRef !== null ? (string) $consignmentRef : null,
            $carrier,
        );
```

- [ ] **Step 11: Add shipments to the tracker payload**

In `app/Services/OrderTracker.php`, add to the payload array:

```php
            'shipments' => $this->shipments($quote),
```

And add the method:

```php
    /**
     * Carrier + consignment ref for each shipped/closed job, with a tracking URL
     * where the carrier offers one. PII-free (carrier + parcel ref only).
     *
     * @return array<int, array<string, mixed>>
     */
    private function shipments(Quote $quote): array
    {
        return $quote->jobs()
            ->whereIn('state', [
                \App\Enums\JobState::Shipped->value,
                \App\Enums\JobState::Closed->value,
            ])
            ->whereNotNull('consignment_ref')
            ->get()
            ->map(function (\App\Models\ProductionJob $job): array {
                $carrier = $job->carrier;
                $ref = (string) $job->consignment_ref;

                return [
                    'carrier_label' => $carrier?->label(),
                    'tracking_url' => $carrier?->trackingUrl($ref),
                    'ref' => $ref,
                ];
            })
            ->values()
            ->all();
    }
```

- [ ] **Step 12: Write the integration test for carrier on the tracker**

Append to `tests/Feature/CarrierTrackingTest.php`:

```php
use App\Enums\JobState;
use App\Models\Company;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Proof;
use App\Models\Quote;
use App\Services\OrderTracker;
use App\Services\QueueService;

it('surfaces a carrier tracking link on a shipped order', function (): void {
    $company = Company::factory()->create(['billing_email' => 'buyer@acme.com']);
    $product = Product::factory()->create(['class' => 'CORE', 'print_method' => 'UV']);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'state' => 'PROCURING']);
    Proof::factory()->approved()->create(['quote_id' => $quote->id]);
    LineItem::factory()->ready()->create(['quote_id' => $quote->id, 'product_id' => $product->id, 'qty' => 3]);

    $queue = app(QueueService::class);
    $job = $queue->buildJobsForQuote($quote->load('lineItems.product'))->first();
    $queue->advance($job, JobState::InProduction);
    $queue->advance($job, JobState::Shipped, 'NV11223344', Carrier::NinjaVan);

    $shipments = app(OrderTracker::class)->payload($quote->fresh())['shipments'];

    expect($shipments)->toHaveCount(1)
        ->and($shipments[0]['carrier_label'])->toBe('Ninja Van')
        ->and($shipments[0]['ref'])->toBe('NV11223344')
        ->and($shipments[0]['tracking_url'])->toContain('NV11223344');
});
```

- [ ] **Step 13: Run the carrier tests**

Run: `php artisan test --filter=CarrierTrackingTest`
Expected: PASS.

- [ ] **Step 14: Confirm no regression in the queue tests**

Run: `php artisan test --filter=ProductionQueueTest`
Expected: PASS (advance still works with the new optional arg).

- [ ] **Step 15: Commit**

```bash
git add app/Enums/Carrier.php database/migrations/2026_07_10_000001_add_carrier_to_production_jobs.php app/Models/ProductionJob.php app/Http/Requests/AdvanceJobRequest.php app/Services/QueueService.php app/Http/Controllers/ProductionQueueController.php app/Services/OrderTracker.php tests/Feature/CarrierTrackingTest.php
git commit -m "feat(tracking): carrier passthrough + tracking links on shipped jobs"
```

---

## Task 4: Signed one-click tracking link (#2)

**Files:**
- Modify: `app/Services/OrderTracker.php`
- Modify: `app/Http/Controllers/TrackingController.php`
- Modify: `routes/api.php`
- Modify: `app/Http/Resources/QuoteResource.php`
- Create: `tests/Feature/SignedTrackLinkTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/SignedTrackLinkTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Quote;
use App\Services\OrderTracker;
use Illuminate\Support\Str;

function frontendLinkFor(Quote $quote): string
{
    return app(OrderTracker::class)->signedFrontendLink($quote);
}

it('serves the tracking payload for a validly signed link', function (): void {
    $company = Company::factory()->create(['billing_email' => 'buyer@acme.com']);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'state' => 'SENT']);

    // The frontend link is /track/view?code=..&signature=..; the same query is
    // forwarded to the API route, which is what the signature was minted for.
    $query = Str::after(frontendLinkFor($quote), '?');

    $this->getJson("/api/track/view?{$query}")
        ->assertOk()
        ->assertJson(['reference' => $quote->tracking_code, 'stage' => 'REVIEW']);
});

it('rejects a tampered signature', function (): void {
    $company = Company::factory()->create(['billing_email' => 'buyer@acme.com']);
    $quote = Quote::factory()->create(['company_id' => $company->id]);
    $query = Str::after(frontendLinkFor($quote), '?');

    $this->getJson("/api/track/view?{$query}0")->assertForbidden();
});

it('returns a generic 404 for a signed link to an unknown code', function (): void {
    // Mint a valid signature over a code that does not exist by signing a real
    // quote then swapping the code is not possible (signature covers code), so
    // assert the not-found path via a deleted quote instead.
    $company = Company::factory()->create(['billing_email' => 'buyer@acme.com']);
    $quote = Quote::factory()->create(['company_id' => $company->id]);
    $query = Str::after(frontendLinkFor($quote), '?');
    $quote->forceDelete();

    $this->getJson("/api/track/view?{$query}")->assertNotFound();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SignedTrackLinkTest`
Expected: FAIL — `Call to undefined method App\Services\OrderTracker::signedFrontendLink()`.

- [ ] **Step 3: Add signed-link builder to the service**

In `app/Services/OrderTracker.php`, add the imports at the top:

```php
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
```

And add the method:

```php
    /**
     * A permanent, tamper-proof deep link the buyer can bookmark. The signature
     * (keyed by the app secret) IS the second factor, so no email rides in the
     * URL - the payload stays PII-free. Returns a FRONTEND path carrying the same
     * code+signature query the API route validates.
     */
    public function signedFrontendLink(Quote $quote): string
    {
        // absolute:false + signed:relative on the route keeps the signature valid
        // regardless of host, and yields "/api/track/view?code=..&signature=..".
        $apiPath = URL::signedRoute('track.view', ['code' => $quote->tracking_code], null, false);

        return '/track/view?'.Str::after($apiPath, '?');
    }
```

- [ ] **Step 4: Add the controller action**

In `app/Http/Controllers/TrackingController.php`, add a `view` method (the signature is validated by route middleware, so no email check here):

```php
    /**
     * Signed deep-link view. Route middleware (signed:relative) has already
     * proven authenticity, so we look the quote up by code and return the same
     * OrderTracker payload. Unknown code -> the same generic 404 as /track.
     */
    public function view(Request $request): JsonResponse
    {
        $code = strtoupper(trim((string) $request->query('code', '')));

        $quote = Quote::query()->where('tracking_code', $code)->first();

        if ($quote === null) {
            return response()->json(['message' => 'No order matches those details.'], 404);
        }

        return response()->json(app(\App\Services\OrderTracker::class)->payload($quote));
    }
```

- [ ] **Step 5: Register the route**

In `routes/api.php`, directly after the existing `POST /track` route (line 74), add:

```php
// Signed one-click tracker (bookmark/QR from the confirmation). The signature is
// the second factor, so no email is needed; throttled like /track.
Route::get('/track/view', [TrackingController::class, 'view'])
    ->middleware(['signed:relative', 'throttle:10,1'])
    ->name('track.view');
```

- [ ] **Step 6: Run the signed-link tests**

Run: `php artisan test --filter=SignedTrackLinkTest`
Expected: PASS.

- [ ] **Step 7: Expose the link on QuoteResource**

In `app/Http/Resources/QuoteResource.php`, add after the `'tracking_code'` line:

```php
            // Permanent signed deep link for the buyer's confirmation/QR.
            'tracking_link' => app(\App\Services\OrderTracker::class)->signedFrontendLink($this->resource),
```

- [ ] **Step 8: Write the resource test**

Add to `tests/Feature/SignedTrackLinkTest.php`:

```php
it('includes a signed tracking_link on the quote resource', function (): void {
    $company = Company::factory()->create(['billing_email' => 'buyer@acme.com']);
    $quote = Quote::factory()->create(['company_id' => $company->id]);

    $link = (new \App\Http\Resources\QuoteResource($quote))
        ->toArray(request());

    expect($link['tracking_link'])->toStartWith('/track/view?code=')
        ->and($link['tracking_link'])->toContain('signature=');
});
```

- [ ] **Step 9: Run the resource test**

Run: `php artisan test --filter=SignedTrackLinkTest`
Expected: PASS.

- [ ] **Step 10: Commit**

```bash
git add app/Services/OrderTracker.php app/Http/Controllers/TrackingController.php routes/api.php app/Http/Resources/QuoteResource.php tests/Feature/SignedTrackLinkTest.php
git commit -m "feat(tracking): signed one-click tracking deep link"
```

---

## Task 5: Auto-advance READY → IN_PRODUCTION on print-file download (#7)

**Files:**
- Modify: `app/Http/Controllers/ProductionQueueController.php`
- Create: `tests/Feature/AutoAdvanceOnDownloadTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/AutoAdvanceOnDownloadTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Proof;
use App\Models\Quote;
use App\Models\User;
use App\Services\QueueService;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

function ready3dJob(string $ref): App\Models\ProductionJob
{
    $company = Company::factory()->create();
    $model3d = Product::factory()->create(['class' => 'MODEL_3D', 'print_method' => 'FDM']);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'state' => 'PROCURING']);
    Proof::factory()->approved()->create(['quote_id' => $quote->id]);
    LineItem::factory()->ready()->create([
        'quote_id' => $quote->id,
        'product_id' => $model3d->id,
        'customization' => ['print_file_ref' => $ref],
    ]);

    return app(QueueService::class)->buildJobsForQuote($quote->load('lineItems.product'))->first();
}

it('advances a READY job to IN_PRODUCTION when its print file is downloaded', function (): void {
    $disk = (string) config('filesystems.artwork_disk');
    Storage::fake($disk);
    Storage::disk($disk)->put('artwork/decal.png', 'PNGBYTES');
    $job = ready3dJob('artwork/decal.png');
    expect($job->state->value)->toBe('READY');

    Sanctum::actingAs(User::factory()->staffAdmin()->create());
    $this->get("/api/production-jobs/{$job->id}/print-file")->assertOk();

    expect($job->fresh()->state->value)->toBe('IN_PRODUCTION');
});

it('does not change state when re-downloading a job already past READY', function (): void {
    $disk = (string) config('filesystems.artwork_disk');
    Storage::fake($disk);
    Storage::disk($disk)->put('artwork/decal.png', 'PNGBYTES');
    $job = ready3dJob('artwork/decal.png');
    app(QueueService::class)->advance($job, App\Enums\JobState::InProduction);
    app(QueueService::class)->advance($job->fresh(), App\Enums\JobState::Shipped, 'REF1');

    Sanctum::actingAs(User::factory()->staffAdmin()->create());
    $this->get("/api/production-jobs/{$job->id}/print-file")->assertOk();

    expect($job->fresh()->state->value)->toBe('SHIPPED');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AutoAdvanceOnDownloadTest`
Expected: FAIL — first test asserts `IN_PRODUCTION` but state is still `READY`.

- [ ] **Step 3: Auto-advance in printFile**

In `app/Http/Controllers/ProductionQueueController.php`, inside `printFile`, after the `$disk->exists($ref)` check and before `return $disk->download(...)`, add:

```php
        // Downloading the print-ready file IS the "started" signal - collapse the
        // separate advance click into the download. Idempotent: only fires from
        // READY, so a re-download at a later state is a no-op.
        if ($job->state === JobState::Ready) {
            $this->queue->advance($job, JobState::InProduction);
        }
```

(`JobState` is already imported at the top of this controller.)

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=AutoAdvanceOnDownloadTest`
Expected: PASS.

- [ ] **Step 5: Confirm the existing print-file tests still pass**

Run: `php artisan test --filter=ProductionQueueTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/ProductionQueueController.php tests/Feature/AutoAdvanceOnDownloadTest.php
git commit -m "feat(queue): auto-advance ready job to in-production on print-file download"
```

---

## Task 6: Batch advance (#9)

**Files:**
- Create: `app/Http/Requests/AdvanceBatchRequest.php`
- Modify: `app/Services/QueueService.php`
- Modify: `app/Http/Controllers/ProductionQueueController.php`
- Modify: `routes/api.php`
- Create: `tests/Feature/BatchAdvanceTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/BatchAdvanceTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\JobState;
use App\Models\Company;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Proof;
use App\Models\Quote;
use App\Models\User;
use App\Services\QueueService;
use Laravel\Sanctum\Sanctum;

function readyUvJob(): App\Models\ProductionJob
{
    $company = Company::factory()->create();
    $product = Product::factory()->create(['class' => 'CORE', 'print_method' => 'UV']);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'state' => 'PROCURING']);
    Proof::factory()->approved()->create(['quote_id' => $quote->id]);
    LineItem::factory()->ready()->create(['quote_id' => $quote->id, 'product_id' => $product->id, 'qty' => 2]);

    return app(QueueService::class)->buildJobsForQuote($quote->load('lineItems.product'))->first();
}

it('starts several ready jobs in one batch and reports skips', function (): void {
    $a = readyUvJob();
    $b = readyUvJob();
    // c is already shipped -> cannot go to IN_PRODUCTION -> skipped.
    $c = readyUvJob();
    app(QueueService::class)->advance($c, JobState::InProduction);
    app(QueueService::class)->advance($c->fresh(), JobState::Shipped, 'REF');

    Sanctum::actingAs(User::factory()->staffAdmin()->create());
    $res = $this->postJson('/api/production-jobs/advance-batch', [
        'job_ids' => [$a->id, $b->id, $c->id],
        'state' => 'IN_PRODUCTION',
    ])->assertOk();

    expect($res->json('advanced'))->toEqualCanonicalizing([$a->id, $b->id])
        ->and($res->json('skipped'))->toBe([$c->id])
        ->and($a->fresh()->state->value)->toBe('IN_PRODUCTION');
});

it('rejects SHIPPED as a batch target (needs a per-parcel ref)', function (): void {
    $a = readyUvJob();

    Sanctum::actingAs(User::factory()->staffAdmin()->create());
    $this->postJson('/api/production-jobs/advance-batch', [
        'job_ids' => [$a->id],
        'state' => 'SHIPPED',
    ])->assertStatus(422)->assertJsonValidationErrors(['state']);
});

it('forbids a buyer from batch-advancing', function (): void {
    $a = readyUvJob();
    $buyer = User::factory()->create(['role' => 'buyer']);

    Sanctum::actingAs($buyer);
    $this->postJson('/api/production-jobs/advance-batch', [
        'job_ids' => [$a->id],
        'state' => 'IN_PRODUCTION',
    ])->assertForbidden();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=BatchAdvanceTest`
Expected: FAIL — 404/route-not-found (endpoint absent).

- [ ] **Step 3: Create the request**

Create `app/Http/Requests/AdvanceBatchRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Bulk-advance selected production jobs. SHIPPED is intentionally excluded - it
 * needs a per-parcel consignment_ref + carrier, so it stays on the single-job
 * dialog. Only the ref-free bulk transitions are allowed here.
 */
class AdvanceBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isStaff() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'job_ids' => ['required', 'array', 'min:1', 'max:200'],
            'job_ids.*' => ['integer', 'exists:production_jobs,id'],
            'state' => ['required', 'string', 'in:IN_PRODUCTION,CLOSED'],
        ];
    }
}
```

- [ ] **Step 4: Add advanceBatch to QueueService**

In `app/Services/QueueService.php`, add:

```php
    /**
     * Advance many jobs to the same target in one call. Each job is guarded by
     * canTransitionTo; jobs in the wrong current state are collected as skipped
     * rather than failing the whole batch. Returns [advanced ids, skipped ids].
     *
     * @param  array<int, int>  $jobIds
     * @return array{advanced: array<int, int>, skipped: array<int, int>}
     */
    public function advanceBatch(array $jobIds, JobState $target): array
    {
        $advanced = [];
        $skipped = [];

        foreach (ProductionJob::query()->whereIn('id', $jobIds)->get() as $job) {
            if ($job->state->canTransitionTo($target)) {
                $this->advance($job, $target);
                $advanced[] = $job->id;
            } else {
                $skipped[] = $job->id;
            }
        }

        return ['advanced' => $advanced, 'skipped' => $skipped];
    }
```

- [ ] **Step 5: Add the controller action**

In `app/Http/Controllers/ProductionQueueController.php`, add:

```php
    public function advanceBatch(\App\Http\Requests\AdvanceBatchRequest $request): \Illuminate\Http\JsonResponse
    {
        $target = JobState::from($request->string('state')->toString());
        /** @var array<int, int> $ids */
        $ids = $request->input('job_ids');

        return response()->json($this->queue->advanceBatch($ids, $target));
    }
```

- [ ] **Step 6: Register the route**

In `routes/api.php`, inside the `auth:sanctum` group, directly after the `advance` route (line 108), add:

```php
    Route::post('/production-jobs/advance-batch', [ProductionQueueController::class, 'advanceBatch']);
```

**Note:** this must be registered before any `/production-jobs/{job}/...` wildcard would capture `advance-batch`. Since `advance-batch` is a distinct static segment before `{job}`, placing it in the group is safe, but keep it above the `{job}` routes for clarity.

- [ ] **Step 7: Run the batch tests**

Run: `php artisan test --filter=BatchAdvanceTest`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Requests/AdvanceBatchRequest.php app/Services/QueueService.php app/Http/Controllers/ProductionQueueController.php routes/api.php tests/Feature/BatchAdvanceTest.php
git commit -m "feat(queue): batch-advance selected jobs (start/close)"
```

---

## Task 7: Scan-to-advance endpoint (#10 backend)

**Files:**
- Modify: `app/Services/QueueService.php`
- Modify: `app/Http/Controllers/ProductionQueueController.php`
- Modify: `routes/api.php`
- Create: `tests/Feature/AdvanceNextTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/AdvanceNextTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\JobState;
use App\Models\Company;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Proof;
use App\Models\Quote;
use App\Models\User;
use App\Services\QueueService;
use Laravel\Sanctum\Sanctum;

function scanReadyJob(): App\Models\ProductionJob
{
    $company = Company::factory()->create();
    $product = Product::factory()->create(['class' => 'CORE', 'print_method' => 'UV']);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'state' => 'PROCURING']);
    Proof::factory()->approved()->create(['quote_id' => $quote->id]);
    LineItem::factory()->ready()->create(['quote_id' => $quote->id, 'product_id' => $product->id, 'qty' => 2]);

    return app(QueueService::class)->buildJobsForQuote($quote->load('lineItems.product'))->first();
}

it('advances a READY job to its next state on scan', function (): void {
    $job = scanReadyJob();

    Sanctum::actingAs(User::factory()->staffAdmin()->create());
    $this->postJson("/api/production-jobs/{$job->id}/advance-next")
        ->assertOk()
        ->assertJsonPath('data.state', 'IN_PRODUCTION');
});

it('refuses to scan-advance into SHIPPED (needs the ref dialog)', function (): void {
    $job = scanReadyJob();
    app(QueueService::class)->advance($job, JobState::InProduction);

    Sanctum::actingAs(User::factory()->staffAdmin()->create());
    $this->postJson("/api/production-jobs/{$job->id}/advance-next")
        ->assertStatus(422)
        ->assertJson(['message' => 'Marking a job shipped needs a consignment reference. Use the ship action.']);

    expect($job->fresh()->state->value)->toBe('IN_PRODUCTION');
});

it('closes a shipped job on scan', function (): void {
    $job = scanReadyJob();
    app(QueueService::class)->advance($job, JobState::InProduction);
    app(QueueService::class)->advance($job->fresh(), JobState::Shipped, 'REF9');

    Sanctum::actingAs(User::factory()->staffAdmin()->create());
    $this->postJson("/api/production-jobs/{$job->id}/advance-next")
        ->assertOk()
        ->assertJsonPath('data.state', 'CLOSED');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AdvanceNextTest`
Expected: FAIL — route not found.

- [ ] **Step 3: Add advanceNext to QueueService**

In `app/Services/QueueService.php`, add:

```php
    /**
     * Advance a job to its single next lifecycle state (scan/one-tap). SHIPPED is
     * refused here - it needs a consignment ref/carrier - so a scan can never
     * silently fire the buyer's "on the way" signal without a real handover.
     *
     * @throws \App\Exceptions\DomainRuleException when the next state is SHIPPED
     */
    public function advanceNext(ProductionJob $job): ProductionJob
    {
        $next = $job->state->nextStates()[0] ?? null;

        if ($next === null) {
            throw new \App\Exceptions\DomainRuleException('This job has no further state to advance to.');
        }

        if ($next === JobState::Shipped) {
            throw new \App\Exceptions\DomainRuleException(
                'Marking a job shipped needs a consignment reference. Use the ship action.'
            );
        }

        return $this->advance($job, $next);
    }
```

**Note:** check `app/Exceptions/DomainRuleException.php` renders as HTTP 422. Open it and confirm; if it has no HTTP status hook, add a `render()` method returning a 422 JSON `{'message' => $this->getMessage()}`. If it already renders 422 (it is used elsewhere for domain rejections), no change needed.

- [ ] **Step 4: Verify DomainRuleException renders as 422**

Run: `php artisan test --filter=AdvanceNextTest`
If the SHIPPED test returns 500 instead of 422, add to `app/Exceptions/DomainRuleException.php`:

```php
    public function render(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
```

(Skip if the test already gets 422.)

- [ ] **Step 5: Add the controller action**

In `app/Http/Controllers/ProductionQueueController.php`, add:

```php
    public function advanceNext(Request $request, ProductionJob $job): ProductionJobResource
    {
        $this->authorize('manageProduction', Quote::class);

        return new ProductionJobResource($this->queue->advanceNext($job));
    }
```

- [ ] **Step 6: Register the route**

In `routes/api.php`, inside the `auth:sanctum` group, after the `advance` route, add:

```php
    Route::post('/production-jobs/{job}/advance-next', [ProductionQueueController::class, 'advanceNext']);
```

- [ ] **Step 7: Run the scan tests**

Run: `php artisan test --filter=AdvanceNextTest`
Expected: PASS.

- [ ] **Step 8: Run the full backend suite**

Run: `php artisan test`
Expected: PASS (no regressions across the board).

- [ ] **Step 9: Commit**

```bash
git add app/Services/QueueService.php app/Http/Controllers/ProductionQueueController.php routes/api.php tests/Feature/AdvanceNextTest.php app/Exceptions/DomainRuleException.php
git commit -m "feat(queue): scan-to-advance endpoint (advance-next), shipped guarded"
```

---

## Task 8: Frontend types + queue store methods

**Files:**
- Modify: `frontend/src/types.ts`
- Modify: `frontend/src/stores/queueStore.ts`
- Modify: `frontend/src/stores/queueStore.test.ts`

- [ ] **Step 1: Inspect current types**

Run: `sed -n '1,80p' frontend/src/types.ts` (or open it). Locate the `TrackResult` and `ProductionJob` interfaces. Note the exact existing field names so additions match.

- [ ] **Step 2: Extend types**

In `frontend/src/types.ts`:

Add a `Carrier` union and a `Shipment` interface:

```ts
export type Carrier = 'SINGPOST' | 'NINJAVAN' | 'JNT' | 'QXPRESS' | 'DHL' | 'FEDEX' | 'OTHER';

export interface Shipment {
  carrier_label: string | null;
  tracking_url: string | null;
  ref: string;
}
```

Extend the `TrackResult` interface (the one in `TrackPage.tsx` today is local; move it here or extend the shared one) with:

```ts
  needed_by: string | null;
  items_total: number;
  items_completed: number;
  shipments: Shipment[];
```

If `ProductionJob` lives here, add `consignment_ref?: string | null;` and `carrier?: Carrier | null;`.

- [ ] **Step 3: Write the failing store test**

In `frontend/src/stores/queueStore.test.ts`, add a test asserting `advanceBatch` posts the right body. Match the existing mocking style in that file (inspect it first). Example shape:

```ts
it('advanceBatch posts job_ids + state and refetches', async () => {
  const post = vi.mocked(api.post).mockResolvedValue({ data: { advanced: [1, 2], skipped: [] } });
  vi.mocked(api.get).mockResolvedValue({ data: { data: [] } });

  await useQueueStore.getState().advanceBatch([1, 2], 'IN_PRODUCTION');

  expect(post).toHaveBeenCalledWith('/production-jobs/advance-batch', {
    job_ids: [1, 2],
    state: 'IN_PRODUCTION',
  });
});
```

(Adjust imports/mocks to whatever `queueStore.test.ts` already uses — check the top of that file.)

- [ ] **Step 4: Run test to verify it fails**

Run: `cd frontend && npx vitest run src/stores/queueStore.test.ts`
Expected: FAIL — `advanceBatch is not a function`.

- [ ] **Step 5: Add store methods**

In `frontend/src/stores/queueStore.ts`:

Extend the `advance` signature to accept a carrier, and add two methods to the `QueueStoreState` interface:

```ts
  advance: (jobId: number, state: JobState, consignmentRef?: string, carrier?: string) => Promise<void>;
  advanceBatch: (jobIds: number[], state: 'IN_PRODUCTION' | 'CLOSED') => Promise<{ advanced: number[]; skipped: number[] }>;
  advanceNext: (jobId: number) => Promise<void>;
```

Update the `advance` implementation body to include carrier:

```ts
  advance: async (jobId, state, consignmentRef, carrier) => {
    set({ error: null });
    try {
      await ensureCsrf();
      await api.post(`/production-jobs/${jobId}/advance`, {
        state,
        ...(consignmentRef ? { consignment_ref: consignmentRef } : {}),
        ...(carrier ? { carrier } : {}),
      });
      await get().fetchQueue({ silent: true });
    } catch (err) {
      set({ error: apiError(err) });
      await get().fetchQueue({ silent: true });
    }
  },
```

Add the new methods (in the store object):

```ts
  advanceBatch: async (jobIds, state) => {
    set({ error: null });
    try {
      await ensureCsrf();
      const { data } = await api.post<{ advanced: number[]; skipped: number[] }>(
        '/production-jobs/advance-batch',
        { job_ids: jobIds, state },
      );
      await get().fetchQueue({ silent: true });
      return data;
    } catch (err) {
      set({ error: apiError(err) });
      await get().fetchQueue({ silent: true });
      return { advanced: [], skipped: jobIds };
    }
  },

  advanceNext: async (jobId) => {
    set({ error: null });
    try {
      await ensureCsrf();
      await api.post(`/production-jobs/${jobId}/advance-next`);
      await get().fetchQueue({ silent: true });
    } catch (err) {
      set({ error: apiError(err) });
      await get().fetchQueue({ silent: true });
    }
  },
```

- [ ] **Step 6: Run test to verify it passes**

Run: `cd frontend && npx vitest run src/stores/queueStore.test.ts`
Expected: PASS.

- [ ] **Step 7: Typecheck**

Run: `cd frontend && npx tsc --noEmit`
Expected: no errors.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/types.ts frontend/src/stores/queueStore.ts frontend/src/stores/queueStore.test.ts
git commit -m "feat(frontend): queue store batch/next advance + carrier arg + tracking types"
```

---

## Task 9: TrackPage enrichments + signed deep-link view (#2/#3/#4/#5 frontend)

**Files:**
- Modify: `frontend/src/pages/TrackPage.tsx`
- Create: `frontend/src/pages/TrackViewPage.tsx`
- Modify: router (find with `grep -rn "TrackPage" frontend/src` — likely `frontend/src/App.tsx`)
- Modify: `frontend/src/pages/TrackPage.test.tsx` (if present; else create)

- [ ] **Step 1: Add needed_by, partial progress, and carrier links to the result view**

In `frontend/src/pages/TrackPage.tsx`, update the local `TrackResult` interface to include `needed_by`, `items_total`, `items_completed`, and `shipments` (matching Task 8's shared types — import from `../types` if you moved it there).

In `TrackResultView`, after the stage `<ol>`, add:

```tsx
        {result.needed_by && (
          <p className="text-sm text-fg-muted">
            Needed by {new Date(result.needed_by).toLocaleDateString()}
          </p>
        )}

        {result.items_total > 1 &&
          result.items_completed > 0 &&
          result.items_completed < result.items_total && (
            <p className="text-sm text-fg-muted">
              {result.items_completed} of {result.items_total} items shipped
            </p>
          )}

        {result.shipments?.length > 0 && (
          <div className="flex flex-col gap-1">
            <p className="text-2xs uppercase tracking-wide text-fg-subtle">Shipments</p>
            {result.shipments.map((s, i) => (
              <p key={i} className="text-sm text-fg">
                {s.tracking_url ? (
                  <a href={s.tracking_url} target="_blank" rel="noopener noreferrer" className="text-primary underline">
                    Track with {s.carrier_label ?? 'carrier'} ({s.ref})
                  </a>
                ) : (
                  <span>
                    {s.carrier_label ? `${s.carrier_label}: ` : ''}
                    {s.ref}
                  </span>
                )}
              </p>
            ))}
          </div>
        )}
```

- [ ] **Step 2: Add localStorage prefill**

In `TrackPage`, initialize `code`/`email` from localStorage and persist on successful lookup. Replace the `useState('')` initializers:

```tsx
  const [code, setCode] = useState(() => localStorage.getItem('gl.track.code') ?? '');
  const [email, setEmail] = useState(() => localStorage.getItem('gl.track.email') ?? '');
```

In `onSubmit`, after `setResult(data);`, add:

```tsx
      localStorage.setItem('gl.track.code', code.trim());
      localStorage.setItem('gl.track.email', email.trim());
```

- [ ] **Step 3: Create the signed deep-link view page**

Create `frontend/src/pages/TrackViewPage.tsx`:

```tsx
import { useEffect, useState } from 'react';
import { useLocation } from 'react-router-dom';
import api, { apiError } from '../lib/api';
import { Card } from '../ui';
import { Motion, fadeInUp } from '../motion';
import type { TrackResult } from '../types';

/**
 * Signed one-click tracker. The buyer arrives from a bookmark/QR carrying
 * ?code=..&signature=..; we forward that exact query to the signed API route,
 * which validates the signature (no email needed) and returns the same payload
 * TrackPage renders. On any failure we point them back to the manual tracker.
 */
export default function TrackViewPage() {
  const { search } = useLocation();
  const [result, setResult] = useState<TrackResult | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let active = true;
    api
      .get<TrackResult>(`/track/view${search}`)
      .then(({ data }) => {
        if (active) setResult(data);
      })
      .catch((err) => {
        if (active) setError(apiError(err) || 'This tracking link is invalid or has expired.');
      });
    return () => {
      active = false;
    };
  }, [search]);

  return (
    <Motion variants={fadeInUp} initial="hidden" animate="visible" className="mx-auto flex w-full max-w-xl flex-col gap-6">
      <h1 className="font-display text-3xl text-fg sm:text-4xl">Order status</h1>
      {error && (
        <Card padding="lg">
          <p className="text-sm text-danger" role="alert">
            {error}
          </p>
          <a href="/track" className="mt-2 inline-block text-sm text-primary underline">
            Track manually instead
          </a>
        </Card>
      )}
      {result && <TrackResultView result={result} />}
    </Motion>
  );
}
```

**Note:** extract `TrackResultView` (currently defined inside `TrackPage.tsx`) into a shared component `frontend/src/components/TrackResultView.tsx` and import it in both `TrackPage.tsx` and `TrackViewPage.tsx` (DRY — do not copy the JSX). Move the function verbatim, export it, and update both imports.

- [ ] **Step 4: Register the route**

Find the router: `grep -rn "path=\"/track\"" frontend/src` → open that file and add, next to the `/track` route:

```tsx
        <Route path="/track/view" element={<TrackViewPage />} />
```

(Import `TrackViewPage` at the top of that file.)

- [ ] **Step 5: Typecheck + test**

Run: `cd frontend && npx tsc --noEmit && npx vitest run src/pages/TrackPage.test.tsx`
Expected: no type errors; existing TrackPage tests pass. If `TrackResult` moved to `types.ts`, update the test's imports.

- [ ] **Step 6: Add a TrackViewPage test**

Create `frontend/src/pages/TrackViewPage.test.tsx` mirroring the mocking style of `TrackPage.test.tsx` (inspect it first). Assert that with a mocked `api.get` resolving a payload, the reference + stage render; and that a rejected `api.get` shows the invalid-link message. Use `MemoryRouter` with `initialEntries={['/track/view?code=GL-XXXXXX&signature=abc']}`.

- [ ] **Step 7: Run the new test**

Run: `cd frontend && npx vitest run src/pages/TrackViewPage.test.tsx`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/pages/TrackPage.tsx frontend/src/pages/TrackViewPage.tsx frontend/src/components/TrackResultView.tsx frontend/src/pages/TrackViewPage.test.tsx frontend/src/App.tsx
git commit -m "feat(frontend): enriched tracker + signed deep-link view"
```

---

## Task 10: Track button + QR on checkout and quote detail (#2 frontend)

**Files:**
- Modify: `frontend/package.json` (add `qrcode`)
- Create: `frontend/src/components/TrackingQr.tsx`
- Modify: `frontend/src/pages/CheckoutPage.tsx`
- Modify: `frontend/src/pages/QuoteDetailPage.tsx`

- [ ] **Step 1: Add the qrcode dependency**

Run: `cd frontend && npm install qrcode && npm install -D @types/qrcode`
Expected: `qrcode` in dependencies, `@types/qrcode` in devDependencies.

- [ ] **Step 2: Create the QR component**

Create `frontend/src/components/TrackingQr.tsx`:

```tsx
import { useEffect, useRef } from 'react';
import QRCode from 'qrcode';

/**
 * Renders a QR for the buyer's permanent signed tracking link. `link` is the
 * relative path from the API (tracking_link); we resolve it against the current
 * origin so the encoded URL opens the app anywhere it is scanned.
 */
export default function TrackingQr({ link, size = 160 }: { link: string; size?: number }) {
  const canvasRef = useRef<HTMLCanvasElement>(null);

  useEffect(() => {
    const canvas = canvasRef.current;
    if (!canvas) return;
    const absolute = new URL(link, window.location.origin).toString();
    void QRCode.toCanvas(canvas, absolute, { width: size, margin: 1 });
  }, [link, size]);

  return <canvas ref={canvasRef} aria-label="Order tracking QR code" />;
}
```

- [ ] **Step 3: Show the track button + QR after checkout**

In `frontend/src/pages/CheckoutPage.tsx`, locate the success/confirmation state (inspect the file: `grep -n "tracking_code\|success\|confirmed" frontend/src/pages/CheckoutPage.tsx`). Where the order confirmation renders, add (using the quote's `tracking_link` from the quote payload — confirm the field is present in the loaded quote object; QuoteResource now returns it):

```tsx
{quote?.tracking_link && (
  <div className="flex flex-col items-center gap-3">
    <a href={quote.tracking_link} className="text-primary underline">
      Track your order
    </a>
    <TrackingQr link={quote.tracking_link} />
    <p className="text-xs text-fg-subtle">Scan or bookmark to follow this order — no login needed.</p>
  </div>
)}
```

(Import `TrackingQr` at the top. If the checkout page's quote type doesn't yet include `tracking_link`, add it to that type/interface.)

- [ ] **Step 4: Show the track button + QR on quote detail**

In `frontend/src/pages/QuoteDetailPage.tsx`, add the same block where order/quote meta is shown. Reuse the `TrackingQr` component. Keep it collapsed behind a "Share tracking link" toggle if the page is dense — otherwise inline is fine.

- [ ] **Step 5: Typecheck**

Run: `cd frontend && npx tsc --noEmit`
Expected: no errors. (If `qrcode` types are missing, confirm `@types/qrcode` installed.)

- [ ] **Step 6: Build check**

Run: `cd frontend && npm run build`
Expected: build succeeds.

- [ ] **Step 7: Commit**

```bash
git add frontend/package.json frontend/package-lock.json frontend/src/components/TrackingQr.tsx frontend/src/pages/CheckoutPage.tsx frontend/src/pages/QuoteDetailPage.tsx
git commit -m "feat(frontend): tracking QR + link on checkout and quote detail"
```

---

## Task 11: Production queue batch select + carrier in ship dialog (#9 frontend)

**Files:**
- Modify: `frontend/src/pages/ProductionQueuePage.tsx`

- [ ] **Step 1: Add multi-select state + bulk-action bar**

In `ProductionQueuePage.tsx`, add selection state near the other `useState`s:

```tsx
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const toggleSelected = (id: number) =>
    setSelected((prev) => {
      const next = new Set(prev);
      next.has(id) ? next.delete(id) : next.add(id);
      return next;
    });
```

Pull `advanceBatch` from the store:

```tsx
  const { jobs, loading, error, fetchQueue, advance, advanceBatch, advanceNext, subscribe, unsubscribe } = useQueueStore();
```

Add a bulk bar above the board (render only when `selected.size > 0`):

```tsx
      {selected.size > 0 && (
        <div className="flex flex-wrap items-center gap-2 rounded-lg border border-border bg-surface-2 p-3">
          <span className="text-sm text-fg">{selected.size} selected</span>
          <Button
            size="sm"
            variant="secondary"
            onClick={async () => {
              const r = await advanceBatch([...selected], 'IN_PRODUCTION');
              if (r.skipped.length) toast({ title: `${r.skipped.length} skipped (not ready)`, tone: 'warning' });
              setSelected(new Set());
            }}
          >
            Start selected
          </Button>
          <Button
            size="sm"
            variant="secondary"
            onClick={async () => {
              const r = await advanceBatch([...selected], 'CLOSED');
              if (r.skipped.length) toast({ title: `${r.skipped.length} skipped (not shipped)`, tone: 'warning' });
              setSelected(new Set());
            }}
          >
            Close selected
          </Button>
          <Button size="sm" variant="ghost" onClick={() => setSelected(new Set())}>
            Clear
          </Button>
        </div>
      )}
```

- [ ] **Step 2: Add a checkbox to each job card**

In the card header (inside the `<div className="flex items-start justify-between gap-2">`), add a checkbox before the job title:

```tsx
                      <input
                        type="checkbox"
                        className="mt-1 h-4 w-4"
                        checked={selected.has(j.id)}
                        onChange={() => toggleSelected(j.id)}
                        aria-label={`Select job ${j.id}`}
                      />
```

- [ ] **Step 3: Add carrier select to the ship dialog**

Add carrier state:

```tsx
  const [carrier, setCarrier] = useState('');
```

In the SHIPPED confirmation block (where the consignment `<Input>` is), add a native select above the consignment input:

```tsx
                        <label className="text-sm text-fg-muted">
                          Carrier
                          <select
                            className="mt-1 w-full rounded-md border border-border bg-bg p-2 text-sm text-fg"
                            value={carrier}
                            onChange={(e) => setCarrier(e.target.value)}
                          >
                            <option value="">Select carrier…</option>
                            <option value="SINGPOST">SingPost</option>
                            <option value="NINJAVAN">Ninja Van</option>
                            <option value="JNT">J&amp;T Express</option>
                            <option value="QXPRESS">Qxpress</option>
                            <option value="DHL">DHL</option>
                            <option value="FEDEX">FedEx</option>
                            <option value="OTHER">Other</option>
                          </select>
                        </label>
```

Change the confirm-shipped `onClick` to pass carrier, and reset it in `onAdvance`:

```tsx
                            onClick={() => void onAdvance(j.id, 'SHIPPED', consignment.trim(), carrier || undefined)}
```

Update `onAdvance` signature + call:

```tsx
  const onAdvance = async (jobId: number, to: JobState, consignmentRef?: string, carrierVal?: string) => {
    if (pendingId !== null) return;
    setPendingId(jobId);
    try {
      await advance(jobId, to, consignmentRef, carrierVal);
      setShippingId(null);
      setConsignment('');
      setCarrier('');
    } finally {
      setPendingId(null);
    }
  };
```

- [ ] **Step 4: Typecheck**

Run: `cd frontend && npx tsc --noEmit`
Expected: no errors.

- [ ] **Step 5: Build check**

Run: `cd frontend && npm run build`
Expected: build succeeds.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/pages/ProductionQueuePage.tsx
git commit -m "feat(queue-ui): batch select bar + carrier in ship dialog"
```

---

## Task 12: Scan-to-advance UI — hardware + camera + job label (#10 frontend)

**Files:**
- Modify: `frontend/package.json` (add `html5-qrcode`)
- Create: `frontend/src/lib/scan.ts`
- Create: `frontend/src/components/JobLabel.tsx`
- Modify: `frontend/src/pages/ProductionQueuePage.tsx`

- [ ] **Step 1: Add the camera-scan dependency**

Run: `cd frontend && npm install html5-qrcode`
Expected: `html5-qrcode` in dependencies.

- [ ] **Step 2: Create the camera-scan helper**

Create `frontend/src/lib/scan.ts`:

```ts
import { Html5Qrcode } from 'html5-qrcode';

/**
 * Start decoding QR codes from the rear camera into `elementId`. Calls `onScan`
 * with each decoded value (the job id). Returns a stop() that releases the
 * camera. getUserMedia requires HTTPS (or localhost).
 */
export async function startCameraScan(
  elementId: string,
  onScan: (value: string) => void,
): Promise<() => Promise<void>> {
  const scanner = new Html5Qrcode(elementId);
  await scanner.start(
    { facingMode: 'environment' },
    { fps: 10, qrbox: 200 },
    (decoded) => onScan(decoded),
    () => {},
  );
  return async () => {
    await scanner.stop();
    scanner.clear();
  };
}
```

- [ ] **Step 3: Create the printable job label**

Create `frontend/src/components/JobLabel.tsx`:

```tsx
import { useEffect, useRef } from 'react';
import QRCode from 'qrcode';

/**
 * Printable traveler label: job id as a QR the floor scans to advance the job.
 * The QR encodes the raw job id - the advance endpoints are staff-auth gated, so
 * the id alone is not a secret. Opens the browser print dialog on mount.
 */
export default function JobLabel({ jobId, onClose }: { jobId: number; onClose: () => void }) {
  const canvasRef = useRef<HTMLCanvasElement>(null);

  useEffect(() => {
    const canvas = canvasRef.current;
    if (!canvas) return;
    void QRCode.toCanvas(canvas, String(jobId), { width: 220, margin: 2 }).then(() => {
      window.print();
    });
  }, [jobId]);

  return (
    <div className="fixed inset-0 z-50 flex flex-col items-center justify-center gap-4 bg-white p-8 text-black print:static">
      <p className="text-2xl font-bold">Job #{jobId}</p>
      <canvas ref={canvasRef} />
      <button className="text-sm underline print:hidden" onClick={onClose}>
        Close
      </button>
    </div>
  );
}
```

- [ ] **Step 4: Add the scan input + camera toggle + label trigger to the queue page**

In `ProductionQueuePage.tsx`:

Add state + a ref for the scan input:

```tsx
  const [scanValue, setScanValue] = useState('');
  const [cameraOn, setCameraOn] = useState(false);
  const [labelJobId, setLabelJobId] = useState<number | null>(null);
  const stopCameraRef = useRef<null | (() => Promise<void>)>(null);
```

Add a scan handler that resolves an id → `advanceNext`, surfacing the SHIPPED-guard error as a toast:

```tsx
  const onScan = async (raw: string) => {
    const id = Number(String(raw).trim());
    if (!Number.isInteger(id) || id <= 0) return;
    if (!jobs.some((j) => j.id === id)) {
      toast({ title: `Job #${id} not on the queue`, tone: 'warning' });
      return;
    }
    await advanceNext(id);
    // advanceNext sets store.error on the 422 ship guard; surface it.
    const err = useQueueStore.getState().error;
    if (err) toast({ title: err, tone: 'warning' });
    setScanValue('');
  };
```

Add a scan bar above the board:

```tsx
      <div className="flex flex-wrap items-center gap-2">
        <Input
          label="Scan to advance"
          placeholder="Scan or type job #, then Enter"
          value={scanValue}
          onChange={(e) => setScanValue(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === 'Enter') void onScan(scanValue);
          }}
        />
        <Button
          variant="ghost"
          size="sm"
          onClick={async () => {
            if (cameraOn) {
              await stopCameraRef.current?.();
              stopCameraRef.current = null;
              setCameraOn(false);
            } else {
              setCameraOn(true);
              const { startCameraScan } = await import('../lib/scan');
              stopCameraRef.current = await startCameraScan('qr-reader', (v) => void onScan(v));
            }
          }}
        >
          {cameraOn ? 'Stop camera' : 'Scan with camera'}
        </Button>
      </div>
      {cameraOn && <div id="qr-reader" className="w-full max-w-xs" />}
```

Add cleanup on unmount (extend the existing effect's return, or add a new effect):

```tsx
  useEffect(() => () => void stopCameraRef.current?.(), []);
```

Add a "Print label" button on each card (next to Download print file):

```tsx
                    <Button variant="ghost" size="sm" fullWidth onClick={() => setLabelJobId(j.id)}>
                      Print label
                    </Button>
```

Render the label overlay at the end of the component's returned JSX:

```tsx
      {labelJobId !== null && <JobLabel jobId={labelJobId} onClose={() => setLabelJobId(null)} />}
```

Import `JobLabel` and `useRef` at the top.

- [ ] **Step 5: Typecheck**

Run: `cd frontend && npx tsc --noEmit`
Expected: no errors.

- [ ] **Step 6: Build check**

Run: `cd frontend && npm run build`
Expected: build succeeds.

- [ ] **Step 7: Manual verification (preview)**

Start the dev server and verify: scan input accepts a job id + Enter → advances; "Scan with camera" prompts for camera; "Print label" opens the print dialog with a QR. (Use the preview tooling; a hardware scanner acts as keyboard input into the focused scan field.)

- [ ] **Step 8: Commit**

```bash
git add frontend/package.json frontend/package-lock.json frontend/src/lib/scan.ts frontend/src/components/JobLabel.tsx frontend/src/pages/ProductionQueuePage.tsx
git commit -m "feat(queue-ui): scan-to-advance (hardware + camera) + printable job label"
```

---

## Final verification

- [ ] **Step 1: Full backend suite**

Run: `php artisan test`
Expected: all green.

- [ ] **Step 2: Full frontend suite + typecheck + build**

Run: `cd frontend && npx vitest run && npx tsc --noEmit && npm run build`
Expected: all green, build succeeds.

- [ ] **Step 3: Update pending_features.md cross-reference**

Confirm `pending_features.md` note under #8 still reads correctly now that the `carrier` field (feature #3) exists — it references that field as groundwork. No change needed unless the field name diverged.

---

## Spec coverage check

| Spec item | Task(s) |
| --- | --- |
| `OrderTracker` foundation | 1 |
| #2 signed one-click link + QR | 4, 9, 10 |
| #3 carrier passthrough + links | 3, 9, 11 |
| #4 promised date on tracker | 2, 9 |
| #5 per-line partial progress | 2, 9 |
| #7 auto-advance on download | 5 |
| #9 batch advance | 6, 8, 11 |
| #10 scan-to-advance (both) + label | 7, 8, 12 |
| Testing (backend + frontend) | every task (TDD) |
