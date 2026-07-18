# Delivery & Courier (NinjaVan) — Implementation Plan (Workstream B)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give each order a staff-editable delivery address, and a "Create shipment" action that pushes the order to NinjaVan, stores the returned tracking number, marks the order SHIPPED, and shows the buyer a tracking link.

**Architecture:** A per-quote `ShippingAddress` (one-to-one, defaulted from the company address). A NinjaVan API client following the repo's house external-client pattern (contract + HTTP impl + fixture fake + config + credential-gated container binding, fail-closed like Payment). A staff "create shipment" endpoint that calls the client, writes the tracking ref + carrier onto the existing `production_jobs` row, and advances it to SHIPPED — reusing the SHIPPED machinery (`consignment_ref`, `carrier`, `OrderTrackingUpdated`, `Carrier::trackingUrl`) that already exists.

**Tech Stack:** Laravel 11 / PHP 8.3, Pest. React 18 + TypeScript, Vite, Zustand, Tailwind, Vitest. Laravel `Http` facade for the outbound API.

**No spec file** — this plan is self-contained (design + plan). It was scoped in the brainstorming for Workstream A; the design section below is the agreed shape.

---

## Decisions already made (do not re-litigate)

- **Address model:** per-quote shipping address, staff-editable, defaulted from the company's stored address. (Not a company address book.)
- **Scope:** create the shipment, store the tracking/AWB + carrier, mark SHIPPED, show the buyer the tracking link. **Stops there.**
- **Inbound status webhook (auto in-transit/delivered) is PARKED** — explicitly out of scope for this workstream. Do not build it. (It is `pending_features.md` #8; mirror `StripeWebhookController` when it's picked up later.)
- **Courier:** NinjaVan first. The `Carrier` enum already has a `NinjaVan` case with a `trackingUrl()` deep-link — reuse it.

## NinjaVan API — confirmed against the live spec

The Order API details below were verified against NinjaVan's published OpenAPI spec (`api-docs.ninjavan.co/static/media/orderapi.*.yaml`). Three things differ from a naive first guess and are baked into Tasks 3–5:

1. **Endpoint is `v4.1`, country code in the path:** `POST {base}/4.1/orders`, where `base` = `https://api-sandbox.ninjavan.co/sg` (sandbox) or `https://api.ninjavan.co/sg` (prod). Token: `POST {base}/2.0/oauth/access_token`, `grant_type=client_credentials`.
2. **The merchant supplies the tracking number — NinjaVan does not generate it.** `requested_tracking_number` is **required**; NinjaVan echoes it. So we generate a short, deterministic, unique tracking number (`"GL"` + base36 of the quote id), send it, and store it as the job's `consignment_ref`. It doubles as the idempotency key (no idempotency header is documented). ⚠️ Confirm the exact allowed length/charset against the live spec (it reads short — "1–9 alphanumeric + dash"); if `"GL"+base36(id)` exceeds it, hash/truncate to fit while staying unique.
3. **`parcel_job.delivery_start_date` is required** — map from `quote->needed_by`, or `today + lead-days default` when null.

Required address fields per the spec: `name`, `address.address1`, `address.country`, `address.postcode`. Cache the OAuth token via its `expires_in`. `service_type='Parcel'`, `service_level` ∈ {`Standard`,`Express`}.

**Credentials + fixed config the owner must provide:** NinjaVan `client_id`, `client_secret`, sandbox vs production base URL, the **pickup/"from" address** (your warehouse), and the default `service_level` + lead-days. The build works fully stubbed (fixture) until these land — same as the repo's Stripe/scraper pattern. **Rate limits are not published in the spec — confirm with NinjaVan before high volume.**

## ⚠️ Parallel-worktree coordination (read first)

Workstream A (quote-spine reshape) edits `QuoteDetailPage.tsx`, `QuoteService.php`, the quotes migrations set, `quoteStore.ts`, and `QuoteResource`. **Land Workstream A first, then rebase this onto it.** This plan also touches `QuoteDetailPage.tsx` (address editor) and adds a quotes-related migration — the two will conflict if run simultaneously. If forced to parallelize, keep this workstream's UI on the **production-queue** surface (`ProductionQueuePage.tsx`) rather than `QuoteDetailPage.tsx` to reduce overlap, and resolve the migration ordering at merge.

## Orientation — what already exists (reuse it)

- **`production_jobs` already carries shipping fields:** `consignment_ref` (string 128, nullable — `2026_07_03_000025_add_consignment_ref_to_production_jobs.php`) and `carrier` (string 32, nullable — `2026_07_10_000001_add_carrier_to_production_jobs.php`, backed by `app/Enums/Carrier.php`).
- **SHIPPED is already a job transition** (`app/Enums/JobState.php`: READY→IN_PRODUCTION→SHIPPED→CLOSED). `QueueService::advance` (`app/Services/QueueService.php:213-277`) persists `consignment_ref` + `carrier` in the same save as the SHIPPED transition, audit-logs, and broadcasts `ProductionQueueUpdated('shipped')` + `OrderTrackingUpdated`. Today a staffer types the tracking number by hand (`AdvanceJobRequest:23-31`, `required_if:state,SHIPPED`). **This workstream replaces the manual typing with an API call that fills those same fields.**
- **`Carrier::trackingUrl($ref)`** (`app/Enums/Carrier.php`, NinjaVan template) already builds the buyer-facing tracking deep-link. The public tracker (`track.{code}` channel, `OrderTrackingUpdated`) already surfaces carrier + consignment ref. **No buyer-side work is needed for the tracking link** beyond passing the values through — verify the tracker view renders them.
- **The company address** is a single `companies.address` text column (`2026_07_01_000001_create_companies_table.php:25`). It's the default source for the per-quote ship-to.
- **House external-client pattern** (copy Payment — the fail-closed exemplar):
  - Contract: `app/Services/Payment/Contracts/PaymentGateway.php`
  - HTTP impl: `app/Services/Payment/StripePaymentGateway.php` (reads `config('services.stripe.*')`, `Http` facade, timeouts + retry, catches `Throwable`)
  - Fixture: `app/Services/Payment/FixturePaymentGateway.php`
  - Config: `config/services.php` stripe block (`:111-114`)
  - Binding: `app/Providers/AppServiceProvider.php:99-114` — `singleton(Contract, fn) => creds ? Http : (local/testing ? Fixture : throw)`.
- **Tests** run from repo root: `vendor/bin/pest`. Full frontend `vitest run` stalls on Windows — run targeted files. `Http::fake()` for outbound API tests.

## File Structure

| File | Change |
|---|---|
| `database/migrations/<new>_create_shipping_addresses_table.php` | **Create** — one-to-one with quotes |
| `app/Models/ShippingAddress.php` | **Create** |
| `app/Models/Quote.php` | add `shippingAddress()` relation + `shippingAddressOrDefault()` |
| `app/Http/Controllers/ShippingAddressController.php` | **Create** — staff GET/PUT |
| `app/Http/Requests/UpdateShippingAddressRequest.php` | **Create** |
| `routes/api.php` | staff `GET/PUT /quotes/{quote}/shipping-address` |
| `app/Services/Courier/Contracts/CourierClient.php` | **Create** — the contract |
| `app/Services/Courier/CourierShipment.php`, `CourierShipmentResult.php` | **Create** — DTOs |
| `app/Services/Courier/HttpNinjaVanClient.php` | **Create** — OAuth2 + create-order |
| `app/Services/Courier/FixtureNinjaVanClient.php` | **Create** — deterministic fake |
| `config/services.php` | **Create** `ninjavan` block + pickup address |
| `app/Providers/AppServiceProvider.php` | bind `CourierClient` (fail-closed) |
| `app/Services/ShipmentService.php` | **Create** — orchestrates create-shipment → job SHIPPED |
| `app/Http/Controllers/ProductionQueueController.php` | add `createShipment` |
| `routes/api.php` | staff `POST /admin/production-jobs/{job}/create-shipment` |
| `frontend/src/pages/ProductionQueuePage.tsx` (+ maybe `QuoteDetailPage.tsx`) | address editor + "Create shipment" button |
| `frontend/src/stores/*` | address + create-shipment actions |
| Tests | per task below |

---

## Task 1: Per-quote shipping address — schema + model

**Files:**
- Create: `database/migrations/2026_07_18_000001_create_shipping_addresses_table.php`, `app/Models/ShippingAddress.php`
- Modify: `app/Models/Quote.php`
- Test: `tests/Feature/ShippingAddressTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ShippingAddressTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Quote;
use App\Models\ShippingAddress;

it('has a one-to-one shipping address relation', function (): void {
    $quote = Quote::factory()->create();
    $addr = ShippingAddress::create([
        'quote_id' => $quote->id,
        'recipient_name' => 'Rachel Tan',
        'phone' => '+6591234567',
        'line1' => '1 Marina Blvd',
        'city' => 'Singapore',
        'postal_code' => '018989',
        'country' => 'SG',
    ]);

    expect($quote->fresh()->shippingAddress->id)->toBe($addr->id);
});

it('falls back to the company address when none is set', function (): void {
    $quote = Quote::factory()->create();
    $quote->company->update(['address' => '10 Anson Rd, Singapore 079903']);

    $default = $quote->shippingAddressOrDefault();
    expect($default['line1'])->toContain('Anson');
});
```

- [ ] **Step 2: Run to fail**

```bash
vendor/bin/pest tests/Feature/ShippingAddressTest.php
```
Expected: FAIL — model + relation missing.

- [ ] **Step 3: Migration**

Create `database/migrations/2026_07_18_000001_create_shipping_addresses_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_addresses', function (Blueprint $table): void {
            $table->id();
            // One ship-to per quote. Defaults are seeded from companies.address
            // but staff edit them per order (recipient, phone, structured lines
            // — the fields a courier API needs).
            $table->foreignId('quote_id')->unique()->constrained('quotes')->cascadeOnDelete();
            $table->string('recipient_name');
            $table->string('phone', 32);
            $table->string('email')->nullable();
            $table->string('line1');
            $table->string('line2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postal_code', 16);
            $table->char('country', 2)->default('SG');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_addresses');
    }
};
```

- [ ] **Step 4: Model + relation**

Create `app/Models/ShippingAddress.php` with `$fillable` for all columns. In `app/Models/Quote.php` add:

```php
public function shippingAddress(): HasOne
{
    return $this->hasOne(ShippingAddress::class);
}

/**
 * The ship-to for courier calls: the saved per-quote address, or a best-effort
 * default parsed from the company's single address line when none is saved yet.
 * @return array<string, string|null>
 */
public function shippingAddressOrDefault(): array
{
    if ($this->shippingAddress !== null) {
        return $this->shippingAddress->only([
            'recipient_name','phone','email','line1','line2','city','state','postal_code','country','notes',
        ]);
    }

    return [
        'recipient_name' => $this->company->name,
        'phone' => null,
        'email' => null,
        'line1' => (string) $this->company->address, // single free-text line
        'line2' => null, 'city' => null, 'state' => null,
        'postal_code' => null, 'country' => 'SG', 'notes' => null,
    ];
}
```

Add `use Illuminate\Database\Eloquent\Relations\HasOne;` to `Quote`.

- [ ] **Step 5: Migrate + run + commit**

```bash
php artisan migrate && vendor/bin/pest tests/Feature/ShippingAddressTest.php
```
Expected: PASS.

```bash
git add database/migrations/2026_07_18_000001_create_shipping_addresses_table.php app/Models/ShippingAddress.php app/Models/Quote.php tests/Feature/ShippingAddressTest.php
git commit -m "feat(shipping): per-quote shipping address model + company-address default"
```

---

## Task 2: Address edit endpoint (staff)

**Files:**
- Create: `app/Http/Controllers/ShippingAddressController.php`, `app/Http/Requests/UpdateShippingAddressRequest.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/ShippingAddressTest.php` (append)

- [ ] **Step 1: Write the failing tests**

Append:

```php
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('lets staff read a quote shipping address (defaulted)', function (): void {
    $quote = Quote::factory()->create();
    $quote->company->update(['address' => '10 Anson Rd']);
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->getJson("/api/quotes/{$quote->id}/shipping-address")
        ->assertOk()->assertJsonPath('data.line1', '10 Anson Rd');
});

it('lets staff upsert a quote shipping address', function (): void {
    $quote = Quote::factory()->create();
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->putJson("/api/quotes/{$quote->id}/shipping-address", [
        'recipient_name' => 'Rachel Tan', 'phone' => '+6591234567',
        'line1' => '1 Marina Blvd', 'postal_code' => '018989', 'country' => 'SG',
    ])->assertOk();

    expect($quote->fresh()->shippingAddress->recipient_name)->toBe('Rachel Tan');
});

it('forbids a buyer from editing the shipping address', function (): void {
    $buyer = User::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $buyer->company_id]);
    Sanctum::actingAs($buyer);

    $this->putJson("/api/quotes/{$quote->id}/shipping-address", [
        'recipient_name' => 'X', 'phone' => '1', 'line1' => 'Y', 'postal_code' => '1',
    ])->assertStatus(403);
});
```

- [ ] **Step 2: Run to fail**

```bash
vendor/bin/pest tests/Feature/ShippingAddressTest.php --filter="staff|buyer"
```
Expected: FAIL — routes missing.

- [ ] **Step 3: FormRequest**

Create `app/Http/Requests/UpdateShippingAddressRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateShippingAddressRequest extends FormRequest
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
            'recipient_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'line1' => ['required', 'string', 'max:255'],
            'line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['required', 'string', 'max:16'],
            // NinjaVan requires country + postcode on the to-address. Default SG
            // if omitted (see prepareForValidation) but store a real value.
            'country' => ['required', 'string', 'size:2'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
```

- [ ] **Step 4: Controller + routes**

Create `app/Http/Controllers/ShippingAddressController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateShippingAddressRequest;
use App\Models\Quote;
use App\Models\ShippingAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShippingAddressController extends Controller
{
    public function show(Request $request, Quote $quote): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);

        return response()->json(['data' => $quote->shippingAddressOrDefault()]);
    }

    public function update(UpdateShippingAddressRequest $request, Quote $quote): JsonResponse
    {
        $address = ShippingAddress::updateOrCreate(
            ['quote_id' => $quote->id],
            $request->validated(),
        );

        return response()->json(['data' => $address->only([
            'recipient_name','phone','email','line1','line2','city','state','postal_code','country','notes',
        ])]);
    }
}
```

In `routes/api.php`, inside the `auth:sanctum` group near the other `/quotes/{quote}` routes:

```php
Route::get('/quotes/{quote}/shipping-address', [ShippingAddressController::class, 'show']);
Route::put('/quotes/{quote}/shipping-address', [ShippingAddressController::class, 'update']);
```

- [ ] **Step 5: Run + commit**

```bash
vendor/bin/pest tests/Feature/ShippingAddressTest.php
```
Expected: PASS.

```bash
git add app/Http/Controllers/ShippingAddressController.php app/Http/Requests/UpdateShippingAddressRequest.php routes/api.php tests/Feature/ShippingAddressTest.php
git commit -m "feat(shipping): staff GET/PUT per-quote shipping address"
```

---

## Task 3: The courier contract + DTOs + fixture

**Files:**
- Create: `app/Services/Courier/Contracts/CourierClient.php`, `CourierShipment.php`, `CourierShipmentResult.php`, `FixtureNinjaVanClient.php`
- Test: `tests/Feature/CourierFixtureTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/CourierFixtureTest.php`:

```php
<?php

declare(strict_types=1);

use App\Services\Courier\Contracts\CourierClient;
use App\Services\Courier\CourierShipment;

it('fixture echoes the merchant-supplied tracking number', function (): void {
    $client = app(CourierClient::class); // fixture in the testing env
    $shipment = new CourierShipment(
        reference: 'GL-2041',
        trackingNumber: 'GL1AB',           // we generate + supply this
        deliveryStartDate: '2026-08-05',
        recipientName: 'Rachel Tan', phone: '+6591234567', email: null,
        line1: '1 Marina Blvd', line2: null, city: 'Singapore', state: null,
        postalCode: '018989', country: 'SG', notes: null,
        parcelCount: 1,
    );

    $result = $client->createShipment($shipment);

    // NinjaVan echoes what we send — the result IS our tracking number.
    expect($result->trackingRef)->toBe('GL1AB')
        ->and($result->carrier)->toBe('NINJAVAN');
});
```

- [ ] **Step 2: Run to fail**

```bash
vendor/bin/pest tests/Feature/CourierFixtureTest.php
```
Expected: FAIL — classes + binding missing.

- [ ] **Step 3: DTOs**

Create `app/Services/Courier/CourierShipment.php` (readonly input) and `CourierShipmentResult.php` (readonly output):

```php
<?php
declare(strict_types=1);
namespace App\Services\Courier;

final readonly class CourierShipment
{
    public function __construct(
        public string $reference,        // merchant_order_number (quote tracking_code/id)
        public string $trackingNumber,   // requested_tracking_number — we generate + supply it
        public string $deliveryStartDate, // Y-m-d; parcel_job.delivery_start_date (required)
        public string $recipientName,
        public string $phone,
        public ?string $email,
        public string $line1,
        public ?string $line2,
        public ?string $city,
        public ?string $state,
        public string $postalCode,
        public string $country,
        public ?string $notes,
        public int $parcelCount,
    ) {}
}
```

```php
<?php
declare(strict_types=1);
namespace App\Services\Courier;

final readonly class CourierShipmentResult
{
    public function __construct(
        public string $trackingRef,   // AWB / tracking number from the carrier
        public string $carrier,       // matches App\Enums\Carrier value, e.g. 'NINJAVAN'
        public ?string $labelUrl,     // printable waybill, if the carrier returns one
    ) {}
}
```

- [ ] **Step 4: Contract**

Create `app/Services/Courier/Contracts/CourierClient.php`:

```php
<?php
declare(strict_types=1);
namespace App\Services\Courier\Contracts;

use App\Services\Courier\CourierShipment;
use App\Services\Courier\CourierShipmentResult;

interface CourierClient
{
    /**
     * Create a delivery order with the carrier and return its tracking ref.
     * Throws App\Exceptions\CourierException on an unrecoverable API failure.
     */
    public function createShipment(CourierShipment $shipment): CourierShipmentResult;
}
```

Create `app/Exceptions/CourierException.php` extending `\RuntimeException`.

- [ ] **Step 5: Fixture**

Create `app/Services/Courier/FixtureNinjaVanClient.php`:

```php
<?php
declare(strict_types=1);
namespace App\Services\Courier;

use App\Services\Courier\Contracts\CourierClient;

/**
 * Deterministic fake for local/testing: no network. Echoes the merchant-supplied
 * tracking number, exactly as the real NinjaVan API does, so tests assert on the
 * value we sent.
 */
final class FixtureNinjaVanClient implements CourierClient
{
    public function createShipment(CourierShipment $shipment): CourierShipmentResult
    {
        return new CourierShipmentResult(
            trackingRef: $shipment->trackingNumber,
            carrier: 'NINJAVAN',
            labelUrl: null,
        );
    }
}
```

- [ ] **Step 6: Bind it (fixture for now)**

In `app/Providers/AppServiceProvider.php`, add a binding mirroring the Payment fail-closed shape (Task 5 adds the live branch; for now bind the fixture):

```php
$this->app->singleton(\App\Services\Courier\Contracts\CourierClient::class, function ($app) {
    // Live branch added in Task 5. Until credentials exist, the fixture serves
    // local/testing; production without creds must fail closed.
    if (app()->environment('local', 'testing')) {
        return new \App\Services\Courier\FixtureNinjaVanClient();
    }
    throw new \RuntimeException('NinjaVan credentials are not configured.');
});
```

- [ ] **Step 7: Run + commit**

```bash
vendor/bin/pest tests/Feature/CourierFixtureTest.php
```
Expected: PASS.

```bash
git add app/Services/Courier app/Exceptions/CourierException.php app/Providers/AppServiceProvider.php tests/Feature/CourierFixtureTest.php
git commit -m "feat(courier): CourierClient contract, DTOs, NinjaVan fixture + binding"
```

---

## Task 4: ShipmentService — create shipment → job SHIPPED

**Files:**
- Create: `app/Services/ShipmentService.php`
- Modify: `app/Http/Controllers/ProductionQueueController.php`, `routes/api.php`
- Test: `tests/Feature/CreateShipmentTest.php`

**Before writing:** open `app/Services/QueueService.php:213-277` (`advance`) and `app/Models/ProductionJob.php` to confirm the job→quote relation name and how `consignment_ref`/`carrier` are set alongside the SHIPPED transition. The ShipmentService reuses that path rather than duplicating it.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/CreateShipmentTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\ProductionJob;
use App\Models\Quote;
use App\Models\ShippingAddress;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('creates a NinjaVan shipment and marks the job shipped', function (): void {
    $quote = Quote::factory()->create();
    ShippingAddress::create([
        'quote_id' => $quote->id, 'recipient_name' => 'Rachel Tan',
        'phone' => '+6591234567', 'line1' => '1 Marina Blvd',
        'postal_code' => '018989', 'country' => 'SG',
    ]);
    // A job in a shippable state (READY / IN_PRODUCTION — confirm which your
    // ProductionJob factory/state uses).
    $job = ProductionJob::factory()->create(['quote_id' => $quote->id, 'state' => 'IN_PRODUCTION']);

    Sanctum::actingAs(User::factory()->staffAdmin()->create());
    $this->postJson("/api/admin/production-jobs/{$job->id}/create-shipment")
        ->assertOk();

    $job->refresh();
    expect($job->state->value)->toBe('SHIPPED')
        ->and($job->carrier)->toBe('NINJAVAN')
        ->and($job->consignment_ref)->not->toBeNull();
});

it('refuses to ship without a shipping address', function (): void {
    $quote = Quote::factory()->create();
    $job = ProductionJob::factory()->create(['quote_id' => $quote->id, 'state' => 'IN_PRODUCTION']);
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->postJson("/api/admin/production-jobs/{$job->id}/create-shipment")
        ->assertStatus(422);
});

it('forbids a buyer from creating a shipment', function (): void {
    $quote = Quote::factory()->create();
    $job = ProductionJob::factory()->create(['quote_id' => $quote->id, 'state' => 'IN_PRODUCTION']);
    Sanctum::actingAs(User::factory()->create());

    $this->postJson("/api/admin/production-jobs/{$job->id}/create-shipment")
        ->assertStatus(403);
});

it('refuses to double-ship a job that already has a consignment ref', function (): void {
    // The merchant-supplied tracking number is the idempotency key; guard our side
    // so a retry never creates a second NinjaVan order for the same job.
    $quote = Quote::factory()->create();
    ShippingAddress::create([
        'quote_id' => $quote->id, 'recipient_name' => 'Rachel Tan',
        'phone' => '+6591234567', 'line1' => '1 Marina Blvd',
        'postal_code' => '018989', 'country' => 'SG',
    ]);
    $job = ProductionJob::factory()->create([
        'quote_id' => $quote->id, 'state' => 'SHIPPED',
        'consignment_ref' => 'GLABC', 'carrier' => 'NINJAVAN',
    ]);
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->postJson("/api/admin/production-jobs/{$job->id}/create-shipment")
        ->assertStatus(422);
});
```

Adjust `ProductionJob::factory()` fields/state names to reality (check the factory).

- [ ] **Step 2: Run to fail**

```bash
vendor/bin/pest tests/Feature/CreateShipmentTest.php
```
Expected: FAIL — route + service missing.

- [ ] **Step 3: ShipmentService**

Create `app/Services/ShipmentService.php`:

```php
<?php
declare(strict_types=1);
namespace App\Services;

use App\Exceptions\CourierException;
use App\Exceptions\DomainRuleException;
use App\Models\ProductionJob;
use App\Services\Courier\Contracts\CourierClient;
use App\Services\Courier\CourierShipment;
use Illuminate\Support\Facades\DB;

/**
 * Turns a produced order into a real carrier shipment: build the shipment from
 * the quote's ship-to, call the courier, and write the returned tracking ref +
 * carrier onto the job as it transitions to SHIPPED (reusing QueueService's
 * SHIPPED path so broadcasts/audit stay consistent).
 */
final class ShipmentService
{
    public function __construct(
        private readonly CourierClient $courier,
        private readonly QueueService $queue,
    ) {}

    public function createForJob(ProductionJob $job): ProductionJob
    {
        // Idempotency guard: the tracking number we supply to NinjaVan is unique,
        // so a job that already carries one has already been shipped — never send
        // a second order.
        if ($job->consignment_ref !== null && $job->consignment_ref !== '') {
            throw new DomainRuleException('This job has already been shipped.');
        }

        $quote = $job->quote;
        $addr = $quote->shippingAddress;
        if ($addr === null) {
            throw new DomainRuleException('A shipping address is required before creating a shipment.');
        }

        // Merchant-supplied tracking number: short, deterministic, unique per quote.
        // "GL" + base36(quote id). Confirm the allowed length/charset against the
        // NinjaVan spec; hash/truncate if the id ever pushes it past the limit.
        $trackingNumber = 'GL'.strtoupper(base_convert((string) $quote->id, 10, 36));

        // delivery_start_date is required by NinjaVan — use the buyer's needed-by,
        // else today + a configurable lead time.
        $deliveryStart = ($quote->needed_by ?? now()->addDays((int) config('services.ninjavan.lead_days', 2)))
            ->format('Y-m-d');

        $shipment = new CourierShipment(
            reference: (string) ($quote->tracking_code ?? $quote->id),
            trackingNumber: $trackingNumber,
            deliveryStartDate: $deliveryStart,
            recipientName: $addr->recipient_name, phone: $addr->phone, email: $addr->email,
            line1: $addr->line1, line2: $addr->line2, city: $addr->city, state: $addr->state,
            postalCode: $addr->postal_code, country: $addr->country, notes: $addr->notes,
            parcelCount: 1,
        );

        $result = $this->courier->createShipment($shipment); // throws CourierException on failure

        // Reuse the existing SHIPPED advance so consignment_ref + carrier land the
        // same way a manual advance would, with the same broadcasts/audit. The
        // stored consignment_ref is the tracking number we generated (echoed back).
        return DB::transaction(fn () => $this->queue->advance(
            $job,
            'SHIPPED',
            consignmentRef: $result->trackingRef,
            carrier: $result->carrier,
        ));
    }
}
```

**Confirm `QueueService::advance`'s signature** (`app/Services/QueueService.php:213`) — the arg names/order for state + consignment ref + carrier may differ; adapt this call to match. If `advance` is not cleanly callable with those args, extract the SHIPPED-write portion into a small method both the controller and this service use. Also confirm `$quote->needed_by` is cast to a date/Carbon on the `Quote` model (it is a `date` column) so `->format()` works; if it's a plain string, wrap in `\Illuminate\Support\Carbon::parse()`.

- [ ] **Step 4: Controller + route**

In `app/Http/Controllers/ProductionQueueController.php`, add:

```php
public function createShipment(Request $request, ProductionJob $job): JsonResponse
{
    abort_unless($request->user()->isStaff(), 403);

    try {
        $job = app(\App\Services\ShipmentService::class)->createForJob($job);
    } catch (\App\Exceptions\DomainRuleException $e) {
        return response()->json(['message' => $e->getMessage()], 422);
    } catch (\App\Exceptions\CourierException $e) {
        return response()->json(['message' => 'Courier error: '.$e->getMessage()], 502);
    }

    return response()->json([
        'data' => [
            'state' => $job->state->value,
            'carrier' => $job->carrier,
            'consignment_ref' => $job->consignment_ref,
            'tracking_url' => \App\Enums\Carrier::tryFrom((string) $job->carrier)?->trackingUrl((string) $job->consignment_ref),
        ],
    ]);
}
```

In `routes/api.php`, near the other production-job routes:

```php
Route::post('/admin/production-jobs/{job}/create-shipment', [ProductionQueueController::class, 'createShipment']);
```

- [ ] **Step 5: Run + commit**

```bash
vendor/bin/pest tests/Feature/CreateShipmentTest.php && vendor/bin/pest tests/Feature/ProductionQueueTest.php
```
Expected: PASS (new shipment tests + existing queue tests green).

```bash
git add app/Services/ShipmentService.php app/Http/Controllers/ProductionQueueController.php routes/api.php tests/Feature/CreateShipmentTest.php
git commit -m "feat(courier): create-shipment endpoint -> job SHIPPED with tracking ref"
```

---

## Task 5: The live NinjaVan HTTP client

**Files:**
- Create: `app/Services/Courier/HttpNinjaVanClient.php`
- Modify: `config/services.php`, `.env.example`, `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/NinjaVanClientTest.php`

> **API contract confirmed against the live spec** (see "NinjaVan API" section up top): endpoint `POST {base}/4.1/orders`, merchant-supplied `requested_tracking_number` (required), `parcel_job.delivery_start_date` (required). One residual to verify in a sandbox call: the exact allowed length/charset of `requested_tracking_number`, and whether the token endpoint wants a JSON or form body (this uses JSON — NinjaVan's `Http::post` default).

- [ ] **Step 1: Write the failing test (Http::fake)**

Create `tests/Feature/NinjaVanClientTest.php`:

```php
<?php

declare(strict_types=1);

use App\Services\Courier\CourierShipment;
use App\Services\Courier\HttpNinjaVanClient;
use Illuminate\Support\Facades\Http;

it('creates an order and returns the tracking number we supplied', function (): void {
    Http::fake([
        '*/2.0/oauth/access_token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        // NinjaVan echoes requested_tracking_number; assert on what we sent.
        '*/4.1/orders' => Http::response(['requested_tracking_number' => 'GL1AB', 'status' => 'Pending Pickup']),
    ]);

    config()->set('services.ninjavan.client_id', 'id');
    config()->set('services.ninjavan.client_secret', 'secret');
    config()->set('services.ninjavan.base_url', 'https://api-sandbox.ninjavan.co/sg');
    config()->set('services.ninjavan.pickup', ['name' => 'Gift Lab', 'phone' => '+6560000000', 'address1' => '1 Depot Rd', 'postcode' => '109679', 'country' => 'SG']);

    $client = app(HttpNinjaVanClient::class);
    $result = $client->createShipment(new CourierShipment(
        reference: 'GL-2041', trackingNumber: 'GL1AB', deliveryStartDate: '2026-08-05',
        recipientName: 'Rachel Tan', phone: '+6591234567', email: null,
        line1: '1 Marina Blvd', line2: null, city: 'Singapore', state: null,
        postalCode: '018989', country: 'SG', notes: null, parcelCount: 1,
    ));

    expect($result->trackingRef)->toBe('GL1AB')->and($result->carrier)->toBe('NINJAVAN');

    // Assert we sent the required fields the spec demands.
    Http::assertSent(fn ($req) => str_contains($req->url(), '/4.1/orders')
        && $req['requested_tracking_number'] === 'GL1AB'
        && $req['parcel_job']['delivery_start_date'] === '2026-08-05');
});
```

- [ ] **Step 2: Run to fail**

```bash
vendor/bin/pest tests/Feature/NinjaVanClientTest.php
```
Expected: FAIL — class missing.

- [ ] **Step 3: The client**

Create `app/Services/Courier/HttpNinjaVanClient.php` (structure mirrors `StripePaymentGateway` — config-driven, timeouts, retry, `Throwable`→`CourierException`). Endpoint/fields are the spec-confirmed shapes:

```php
<?php
declare(strict_types=1);
namespace App\Services\Courier;

use App\Exceptions\CourierException;
use App\Services\Courier\Contracts\CourierClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

final class HttpNinjaVanClient implements CourierClient
{
    public function createShipment(CourierShipment $shipment): CourierShipmentResult
    {
        try {
            $base = rtrim((string) config('services.ninjavan.base_url'), '/');
            $token = $this->accessToken($base);
            $pickup = (array) config('services.ninjavan.pickup');
            $level = (string) config('services.ninjavan.service_level', 'Standard');

            $resp = Http::withToken($token)
                ->connectTimeout(5)->timeout(20)->retry(2, 500, throw: false)
                ->post($base.'/4.1/orders', [
                    'service_type' => 'Parcel',
                    'service_level' => $level,
                    // We supply the tracking number; NinjaVan echoes it. Required.
                    'requested_tracking_number' => $shipment->trackingNumber,
                    'reference' => ['merchant_order_number' => $shipment->reference],
                    'from' => [
                        'name' => $pickup['name'] ?? '', 'phone_number' => $pickup['phone'] ?? '',
                        'address' => ['address1' => $pickup['address1'] ?? '', 'postcode' => $pickup['postcode'] ?? '', 'country' => $pickup['country'] ?? 'SG'],
                    ],
                    'to' => [
                        'name' => $shipment->recipientName, 'phone_number' => $shipment->phone, 'email' => $shipment->email,
                        'address' => [
                            'address1' => $shipment->line1, 'address2' => $shipment->line2,
                            'city' => $shipment->city, 'state' => $shipment->state,
                            'postcode' => $shipment->postalCode, 'country' => $shipment->country,
                        ],
                    ],
                    'parcel_job' => [
                        'delivery_start_date' => $shipment->deliveryStartDate, // required
                        'delivery_instructions' => $shipment->notes,
                    ],
                ]);

            if ($resp->failed()) {
                throw new CourierException('NinjaVan order failed: HTTP '.$resp->status().' '.$resp->body());
            }

            // NinjaVan echoes requested_tracking_number; fall back to what we sent
            // (a 2xx means the order was accepted with our number).
            $tracking = (string) ($resp->json('requested_tracking_number') ?? $shipment->trackingNumber);

            return new CourierShipmentResult($tracking, 'NINJAVAN', $resp->json('label_url'));
        } catch (CourierException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new CourierException('NinjaVan request error: '.$e->getMessage(), previous: $e);
        }
    }

    /**
     * Cache the client-credentials token for its lifetime (minus a safety margin)
     * so we authenticate once, not per shipment.
     */
    private function accessToken(string $base): string
    {
        return Cache::remember('ninjavan.token', now()->addMinutes(50), function () use ($base): string {
            $resp = Http::connectTimeout(5)->timeout(20)->post($base.'/2.0/oauth/access_token', [
                'client_id' => config('services.ninjavan.client_id'),
                'client_secret' => config('services.ninjavan.client_secret'),
                'grant_type' => 'client_credentials',
            ]);
            $token = (string) $resp->json('access_token');
            if ($token === '') {
                throw new CourierException('NinjaVan auth failed.');
            }

            return $token;
        });
    }
}
```

Note: the 50-minute cache TTL is a safe floor; if you want it exact, read `expires_in` from the token response and cache for `expires_in - 60`s. The `Http::fake` in the test satisfies both the token and order calls.

- [ ] **Step 4: Config + env + live binding**

In `config/services.php` add:

```php
'ninjavan' => [
    'client_id' => env('NINJAVAN_CLIENT_ID'),
    'client_secret' => env('NINJAVAN_CLIENT_SECRET'),
    // Base URL carries the country code. Sandbox: https://api-sandbox.ninjavan.co/sg
    // Production: https://api.ninjavan.co/sg
    'base_url' => env('NINJAVAN_BASE_URL', 'https://api-sandbox.ninjavan.co/sg'),
    'service_level' => env('NINJAVAN_SERVICE_LEVEL', 'Standard'), // Standard | Express
    'lead_days' => env('NINJAVAN_LEAD_DAYS', 2), // fallback delivery_start_date offset
    'pickup' => [
        'name' => env('NINJAVAN_PICKUP_NAME', 'Gift Lab'),
        'phone' => env('NINJAVAN_PICKUP_PHONE'),
        'address1' => env('NINJAVAN_PICKUP_ADDRESS1'),
        'postcode' => env('NINJAVAN_PICKUP_POSTCODE'),
        'country' => env('NINJAVAN_PICKUP_COUNTRY', 'SG'),
    ],
],
```

Add the `NINJAVAN_*` keys (blank) to `.env.example` with a comment: owner fills client id/secret from the NinjaVan Dashboard → Developer, sets `NINJAVAN_BASE_URL` to the production URL (`https://api.ninjavan.co/{country}`) when going live, and the pickup fields to the real warehouse. Rate limits are not published — confirm with NinjaVan before high volume.

Update the `AppServiceProvider` binding (from Task 3) to the fail-closed live branch:

```php
$this->app->singleton(\App\Services\Courier\Contracts\CourierClient::class, function ($app) {
    if (config('services.ninjavan.client_id') && config('services.ninjavan.client_secret')) {
        return new \App\Services\Courier\HttpNinjaVanClient();
    }
    if (app()->environment('local', 'testing')) {
        return new \App\Services\Courier\FixtureNinjaVanClient();
    }
    throw new \RuntimeException('NinjaVan credentials are not configured.');
});
```

- [ ] **Step 5: Run + commit**

```bash
vendor/bin/pest tests/Feature/NinjaVanClientTest.php && vendor/bin/pest tests/Feature/CourierFixtureTest.php
```
Expected: PASS (live client via Http::fake; fixture still used when no creds).

```bash
git add app/Services/Courier/HttpNinjaVanClient.php config/services.php .env.example app/Providers/AppServiceProvider.php tests/Feature/NinjaVanClientTest.php
git commit -m "feat(courier): live NinjaVan HTTP client (OAuth2 + create-order), credential-gated"
```

---

## Task 6: Frontend — address editor + create-shipment

**Files:**
- Modify: `frontend/src/pages/ProductionQueuePage.tsx` (primary surface; or `QuoteDetailPage.tsx` if landed after Workstream A), relevant store(s)

- [ ] **Step 1: Store actions**

Add to the appropriate store: `fetchShippingAddress(quoteId)` (GET), `saveShippingAddress(quoteId, payload)` (PUT), and `createShipment(jobId)` (POST create-shipment). Follow the store's existing `ensureCsrf()` + `apiError` shape; `createShipment` returns the tracking payload or throws.

- [ ] **Step 2: Address editor UI**

On the production-queue row (or job detail), a staff-only "Delivery address" panel: loads via `fetchShippingAddress`, edits the fields (recipient, phone, line1/2, city, postal, country, notes), saves via `saveShippingAddress`. Prefills from the defaulted response.

- [ ] **Step 3: Create-shipment button**

A staff-only "Create NinjaVan shipment" button on a shippable job. Disabled until a shipping address exists. On click → `createShipment(jobId)`; on success show a toast with the tracking number and the `tracking_url`, and let the row reflect SHIPPED (refetch the queue). On 422 (no address) or 502 (courier error), show the message.

- [ ] **Step 4: Typecheck + test + commit**

```bash
cd frontend && npx tsc --noEmit && npx vitest run src/pages/ProductionQueuePage.test.tsx
```

```bash
git add frontend/src
git commit -m "feat(courier): staff delivery-address editor + create-shipment UI"
```

---

## Task 7: Verification

- [ ] **Step 1: Backend suite**

```bash
vendor/bin/pest
```
Expected: green. Report counts.

- [ ] **Step 2: Drive it (fixture courier, no real creds)**

Log in as staff, open a produced order, edit the delivery address, click "Create NinjaVan shipment". With no `NINJAVAN_*` creds set, the **fixture** client returns a deterministic tracking ref; confirm the job flips to SHIPPED, the tracking ref + carrier are stored, and the buyer's public tracker (`/track`) shows the NinjaVan tracking link (`Carrier::trackingUrl`). Confirm a buyer cannot see the address editor or the shipment button.

- [ ] **Step 3: Live smoke (only once creds provided)**

With sandbox `NINJAVAN_*` creds in `.env`, repeat and confirm a real sandbox tracking number comes back. Correct the HTTP client's field mapping if NinjaVan's response differs from the assumed shape.

---

## Self-Review Notes

- **Coverage:** per-quote address (Tasks 1–2), courier client contract/fixture/live (Tasks 3, 5), create-shipment orchestration → SHIPPED (Task 4), frontend (Task 6), verification (Task 7).
- **Reuses existing machinery:** `production_jobs.consignment_ref`/`carrier`, the SHIPPED transition, `OrderTrackingUpdated`, and `Carrier::trackingUrl` — no new buyer-tracking code.
- **Fail-closed binding** matches the repo's Payment exemplar; fixture until creds land.
- **Parked:** inbound status webhook (auto in-transit/delivered) — explicitly out of scope; revisit as `pending_features.md` #8.
- **NinjaVan API confirmed against the live spec** (v4.1 endpoint, merchant-supplied `requested_tracking_number`, required `delivery_start_date`, cached token). One residual to check in a sandbox call: the exact allowed length/charset of the tracking number (Task 4's `"GL"+base36(id)` generator) and the token body format.
- **Verify-before-relying flags:** `QueueService::advance` signature (Task 4), `ProductionJob` factory states + job→quote relation (Task 4), `Quote::needed_by` date cast (Task 4), and which frontend surface hosts the UI given Workstream A's overlap (Task 6).
- **Owner inputs needed to go live:** NinjaVan `client_id`/`client_secret`, base URL (sandbox→prod), pickup/warehouse address, service defaults.
```
