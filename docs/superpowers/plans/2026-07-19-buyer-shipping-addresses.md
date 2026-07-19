# Buyer Shipping Addresses Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a storefront buyer enter/confirm a structured shipping address at checkout (saved with the order as an immutable text snapshot), manage up to 3 saved addresses in a new profile area, and reach it from a header account menu.

**Architecture:** Approach A — the checkout address is written into the per-quote `ShippingAddress` in the same DB transaction as the quote (`QuoteService`). A new per-user `saved_addresses` table backs an address book exposed via buyer-owned CRUD routes. The checkout picker prefills from a saved address or the company default but always submits the current form **text**, never a saved-address id, so later edits never mutate placed orders. Staff keep the existing company-default fallback.

**Tech Stack:** Laravel 11 (Eloquent, FormRequests, Policies, Pest), React 18 + TypeScript, Zustand, React Router, Vitest + Testing Library, Tailwind.

**Spec:** `docs/superpowers/specs/2026-07-19-buyer-shipping-addresses-design.md`

---

## File Structure

**Backend — new**
- `database/migrations/XXXX_XX_XX_create_saved_addresses_table.php` — table
- `app/Models/SavedAddress.php` — model
- `app/Policies/SavedAddressPolicy.php` — owner-only authorization
- `app/Http/Controllers/SavedAddressController.php` — buyer CRUD + max-3 guard
- `app/Http/Requests/StoreSavedAddressRequest.php` — create validation
- `app/Http/Requests/UpdateSavedAddressRequest.php` — update validation
- `tests/Feature/SavedAddressTest.php` — CRUD/policy/limit tests
- `tests/Feature/CheckoutShippingTest.php` — quote-create snapshot + immutability

**Backend — modified**
- `app/Models/User.php` — `savedAddresses()` relation
- `app/Providers/AppServiceProvider.php` — register policy
- `routes/api.php` — saved-address routes
- `app/Http/Requests/StoreQuoteRequest.php` — nested `shipping_address` rules
- `app/Http/Controllers/QuoteController.php` — pass address into service
- `app/Services/QuoteService.php` — write snapshot in the transaction

**Frontend — new**
- `frontend/src/stores/savedAddressStore.ts` — address-book store
- `frontend/src/components/checkout/ShippingFields.tsx` — shared address form fields
- `frontend/src/pages/AddressBookPage.tsx` — `/account/addresses`
- `frontend/src/pages/AddressBookPage.test.tsx`
- `frontend/src/components/checkout/ShippingFields.test.tsx`

**Frontend — modified**
- `frontend/src/types.ts` — `SavedAddress`, `ShippingAddressInput`
- `frontend/src/stores/quoteStore.ts` — `createQuote` takes shipping
- `frontend/src/pages/CheckoutPage.tsx` — picker + form + validation
- `frontend/src/pages/CheckoutPage.test.tsx` — validation gate test
- `frontend/src/components/SiteHeader.tsx` — account dropdown + drawer link
- `frontend/src/App.tsx` — address book route

---

## Task 1: `saved_addresses` table + model

**Files:**
- Create: `database/migrations/XXXX_XX_XX_create_saved_addresses_table.php`
- Create: `app/Models/SavedAddress.php`
- Modify: `app/Models/User.php`
- Test: `tests/Feature/SavedAddressTest.php`

- [ ] **Step 1: Create the migration file**

Run: `php artisan make:migration create_saved_addresses_table`

Replace its contents with:

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
        Schema::create('saved_addresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label')->nullable();
            $table->string('recipient_name');
            $table->string('phone');
            $table->string('email')->nullable();
            $table->string('line1');
            $table->string('line2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postal_code');
            $table->string('country', 2)->default('SG');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_addresses');
    }
};
```

- [ ] **Step 2: Create the model**

Create `app/Models/SavedAddress.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A buyer's saved ship-to address (address book, max 3 per user). Structured
 * fields mirror ShippingAddress so checkout prefill and validation share one
 * shape. Orders NEVER reference this row - checkout copies the text into the
 * per-quote ShippingAddress, so editing/deleting here can't alter placed orders.
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $label
 * @property string $recipient_name
 * @property string $phone
 * @property string|null $email
 * @property string $line1
 * @property string|null $line2
 * @property string|null $city
 * @property string|null $state
 * @property string $postal_code
 * @property string $country
 * @property string|null $notes
 */
class SavedAddress extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'label',
        'recipient_name',
        'phone',
        'email',
        'line1',
        'line2',
        'city',
        'state',
        'postal_code',
        'country',
        'notes',
    ];

    /**
     * @return BelongsTo<User, SavedAddress>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 3: Add the User relation**

In `app/Models/User.php`, add the import near the other relation imports:

```php
use Illuminate\Database\Eloquent\Relations\HasMany;
```

(If `HasMany` is already imported, skip.) Then add this method inside the `User` class, next to the other relation methods:

```php
    /**
     * @return HasMany<SavedAddress>
     */
    public function savedAddresses(): HasMany
    {
        return $this->hasMany(SavedAddress::class);
    }
```

- [ ] **Step 4: Write the failing test**

Create `tests/Feature/SavedAddressTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\SavedAddress;
use App\Models\User;

it('belongs to a user and stores structured fields', function (): void {
    $user = User::factory()->create();
    $addr = SavedAddress::create([
        'user_id' => $user->id,
        'label' => 'Office',
        'recipient_name' => 'Rachel Tan',
        'phone' => '+6591234567',
        'line1' => '1 Marina Blvd',
        'city' => 'Singapore',
        'postal_code' => '018989',
        'country' => 'SG',
    ]);

    expect($user->fresh()->savedAddresses)->toHaveCount(1)
        ->and($addr->user->id)->toBe($user->id)
        ->and($addr->label)->toBe('Office');
});
```

- [ ] **Step 5: Run migration + test**

Run: `php artisan migrate && php artisan test --filter=SavedAddressTest`
Expected: migration runs; test PASS.

- [ ] **Step 6: Commit**

```bash
git add database/migrations app/Models/SavedAddress.php app/Models/User.php tests/Feature/SavedAddressTest.php
git commit -m "feat(shipping): saved_addresses table + model"
```

---

## Task 2: Owner-only policy

**Files:**
- Create: `app/Policies/SavedAddressPolicy.php`
- Modify: `app/Providers/AppServiceProvider.php`

- [ ] **Step 1: Create the policy**

Create `app/Policies/SavedAddressPolicy.php`:

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SavedAddress;
use App\Models\User;

/**
 * A saved address is personal: only its owner may read, edit, or delete it.
 * There is no staff override - staff manage the per-quote ShippingAddress, not
 * a buyer's private book.
 */
class SavedAddressPolicy
{
    public function view(User $user, SavedAddress $address): bool
    {
        return $user->id === $address->user_id;
    }

    public function update(User $user, SavedAddress $address): bool
    {
        return $user->id === $address->user_id;
    }

    public function delete(User $user, SavedAddress $address): bool
    {
        return $user->id === $address->user_id;
    }
}
```

- [ ] **Step 2: Register the policy**

In `app/Providers/AppServiceProvider.php`, find the block that registers policies (near `Gate::policy(Quote::class, QuotePolicy::class);`). Add the imports at the top with the other model/policy imports:

```php
use App\Models\SavedAddress;
use App\Policies\SavedAddressPolicy;
```

And register it beside the existing `Gate::policy(...)` calls:

```php
        Gate::policy(SavedAddress::class, SavedAddressPolicy::class);
```

- [ ] **Step 3: Commit**

```bash
git add app/Policies/SavedAddressPolicy.php app/Providers/AppServiceProvider.php
git commit -m "feat(shipping): owner-only policy for saved addresses"
```

---

## Task 3: Saved-address CRUD API (max 3)

**Files:**
- Create: `app/Http/Requests/StoreSavedAddressRequest.php`
- Create: `app/Http/Requests/UpdateSavedAddressRequest.php`
- Create: `app/Http/Controllers/SavedAddressController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/SavedAddressTest.php`

- [ ] **Step 1: Create the store request**

Create `app/Http/Requests/StoreSavedAddressRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSavedAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if (blank($this->input('country'))) {
            $this->merge(['country' => 'SG']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:60'],
            'recipient_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'line1' => ['required', 'string', 'max:255'],
            'line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['required', 'string', 'max:16'],
            'country' => ['required', 'string', 'size:2'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
```

- [ ] **Step 2: Create the update request**

Create `app/Http/Requests/UpdateSavedAddressRequest.php` (identical rules; authorization is handled by the controller policy check, so `authorize` just requires a session):

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSavedAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if (blank($this->input('country'))) {
            $this->merge(['country' => 'SG']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:60'],
            'recipient_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'line1' => ['required', 'string', 'max:255'],
            'line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['required', 'string', 'max:16'],
            'country' => ['required', 'string', 'size:2'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
```

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/SavedAddressController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreSavedAddressRequest;
use App\Http\Requests\UpdateSavedAddressRequest;
use App\Models\SavedAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SavedAddressController extends Controller
{
    private const MAX_PER_USER = 3;

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $request->user()->savedAddresses()->latest()->get(),
        ]);
    }

    public function store(StoreSavedAddressRequest $request): JsonResponse
    {
        $user = $request->user();

        // Hard cap at 3 per user (spec). Independent of any client-side hiding
        // of the Add button.
        abort_if(
            $user->savedAddresses()->count() >= self::MAX_PER_USER,
            422,
            'You can save at most '.self::MAX_PER_USER.' addresses.',
        );

        $address = $user->savedAddresses()->create($request->validated());

        return response()->json(['data' => $address], 201);
    }

    public function update(UpdateSavedAddressRequest $request, SavedAddress $savedAddress): JsonResponse
    {
        $this->authorize('update', $savedAddress);

        $savedAddress->update($request->validated());

        return response()->json(['data' => $savedAddress]);
    }

    public function destroy(Request $request, SavedAddress $savedAddress): JsonResponse
    {
        $this->authorize('delete', $savedAddress);

        $savedAddress->delete();

        return response()->json(['data' => true]);
    }
}
```

- [ ] **Step 4: Register the routes**

In `routes/api.php`, inside the `auth:sanctum` group (after the shipping-address routes, before `// Proofs`), add:

```php
    // Buyer address book (personal, max 3; owner-only).
    Route::get('/saved-addresses', [SavedAddressController::class, 'index']);
    Route::post('/saved-addresses', [SavedAddressController::class, 'store']);
    Route::put('/saved-addresses/{savedAddress}', [SavedAddressController::class, 'update']);
    Route::delete('/saved-addresses/{savedAddress}', [SavedAddressController::class, 'destroy']);
```

Add the import at the top of `routes/api.php` with the other controller imports:

```php
use App\Http\Controllers\SavedAddressController;
```

- [ ] **Step 5: Write the failing tests**

Append to `tests/Feature/SavedAddressTest.php`:

```php
use Laravel\Sanctum\Sanctum;

it('lets a buyer create and list their own addresses', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/saved-addresses', [
        'label' => 'Office',
        'recipient_name' => 'Rachel Tan',
        'phone' => '+6591234567',
        'line1' => '1 Marina Blvd',
        'postal_code' => '018989',
    ])->assertCreated()->assertJsonPath('data.label', 'Office');

    $this->getJson('/api/saved-addresses')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('caps saved addresses at three per user', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    SavedAddress::factory()->count(3)->create(['user_id' => $user->id]);

    $this->postJson('/api/saved-addresses', [
        'recipient_name' => 'Rachel Tan',
        'phone' => '+6591234567',
        'line1' => '1 Marina Blvd',
        'postal_code' => '018989',
    ])->assertStatus(422);
});

it('forbids editing another users address', function (): void {
    $owner = User::factory()->create();
    $addr = SavedAddress::factory()->create(['user_id' => $owner->id]);
    Sanctum::actingAs(User::factory()->create());

    $this->putJson("/api/saved-addresses/{$addr->id}", [
        'recipient_name' => 'Hacker',
        'phone' => '+6500000000',
        'line1' => 'Nowhere',
        'postal_code' => '000000',
    ])->assertForbidden();
});

it('lets the owner delete their address', function (): void {
    $user = User::factory()->create();
    $addr = SavedAddress::factory()->create(['user_id' => $user->id]);
    Sanctum::actingAs($user);

    $this->deleteJson("/api/saved-addresses/{$addr->id}")->assertOk();
    expect(SavedAddress::find($addr->id))->toBeNull();
});
```

- [ ] **Step 6: Create the model factory (needed by the tests)**

Create `database/factories/SavedAddressFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SavedAddress;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavedAddress>
 */
class SavedAddressFactory extends Factory
{
    protected $model = SavedAddress::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'label' => $this->faker->randomElement(['Office', 'Warehouse', 'Home']),
            'recipient_name' => $this->faker->name(),
            'phone' => '+6591234567',
            'line1' => $this->faker->streetAddress(),
            'city' => 'Singapore',
            'postal_code' => (string) $this->faker->numberBetween(100000, 999999),
            'country' => 'SG',
        ];
    }
}
```

- [ ] **Step 7: Run the tests**

Run: `php artisan test --filter=SavedAddressTest`
Expected: all PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Requests/StoreSavedAddressRequest.php app/Http/Requests/UpdateSavedAddressRequest.php app/Http/Controllers/SavedAddressController.php database/factories/SavedAddressFactory.php routes/api.php tests/Feature/SavedAddressTest.php
git commit -m "feat(shipping): buyer saved-address CRUD API with max-3 guard"
```

---

## Task 4: Quote creation writes the shipping snapshot

**Files:**
- Modify: `app/Http/Requests/StoreQuoteRequest.php`
- Modify: `app/Http/Controllers/QuoteController.php:44-58`
- Modify: `app/Services/QuoteService.php:55-100`
- Test: `tests/Feature/CheckoutShippingTest.php`

- [ ] **Step 1: Add nested `shipping_address` rules to StoreQuoteRequest**

In `app/Http/Requests/StoreQuoteRequest.php`, add the import at the top:

```php
use Illuminate\Validation\Rule;
```

(If already imported, skip.) In `rules()`, add these entries to the returned array (buyer must supply it; staff may omit and fall back to the company default):

```php
            // Ship-to captured at storefront checkout. Required for buyers,
            // optional for staff (who fall back to the company default). Copied
            // verbatim into the per-quote ShippingAddress by QuoteService.
            'shipping_address' => [Rule::requiredIf(! ($this->user()?->isStaff() ?? false)), 'array'],
            'shipping_address.recipient_name' => ['required_with:shipping_address', 'string', 'max:255'],
            'shipping_address.phone' => ['required_with:shipping_address', 'string', 'max:32'],
            'shipping_address.email' => ['nullable', 'email', 'max:255'],
            'shipping_address.line1' => ['required_with:shipping_address', 'string', 'max:255'],
            'shipping_address.line2' => ['nullable', 'string', 'max:255'],
            'shipping_address.city' => ['nullable', 'string', 'max:120'],
            'shipping_address.state' => ['nullable', 'string', 'max:120'],
            'shipping_address.postal_code' => ['required_with:shipping_address', 'string', 'max:16'],
            'shipping_address.country' => ['nullable', 'string', 'size:2'],
            'shipping_address.notes' => ['nullable', 'string', 'max:2000'],
```

- [ ] **Step 2: Pass the address through the controller**

In `app/Http/Controllers/QuoteController.php`, update the `store` method's service call to pass the address as the final argument:

```php
        $quote = $this->quotes->create(
            $companyId,
            $request->array('line_items'),
            $request->input('notes'),
            $request->input('needed_by'),
            $request->input('idempotency_key'),
            $request->input('shipping_address'),
        );
```

- [ ] **Step 3: Thread the address through QuoteService**

In `app/Services/QuoteService.php`, change the `create` signature and its internal `createFresh` call. Replace the `create` method signature line:

```php
    public function create(int $companyId, array $lineSpecs, ?string $notes, ?string $neededBy = null, ?string $idempotencyKey = null, ?array $shipping = null): Quote
```

Replace the `createFresh` call inside the `try` block:

```php
            return $this->createFresh($companyId, $lineSpecs, $notes, $neededBy, $idempotencyKey, $shipping);
```

Change the `createFresh` signature:

```php
    private function createFresh(int $companyId, array $lineSpecs, ?string $notes, ?string $neededBy, ?string $idempotencyKey, ?array $shipping): Quote
```

Update the transaction closure `use (...)` list to include `$shipping`:

```php
        return DB::transaction(function () use ($companyId, $lineSpecs, $notes, $neededBy, $idempotencyKey, $shipping): Quote {
```

- [ ] **Step 4: Write the snapshot inside the transaction**

In `app/Services/QuoteService.php`, immediately after the `$quote = Quote::create([...]);` block (around line 153, before the `foreach ($resolved as $index => $r)` line-item loop), add:

```php
            // Snapshot the buyer's ship-to as its own row on the quote. Text is
            // copied here, not referenced - a later edit to a saved address must
            // never mutate this placed order. Staff may omit it, in which case
            // shippingAddressOrDefault() keeps returning the company default.
            if ($shipping !== null) {
                $quote->shippingAddress()->create([
                    'recipient_name' => $shipping['recipient_name'],
                    'phone' => $shipping['phone'],
                    'email' => $shipping['email'] ?? null,
                    'line1' => $shipping['line1'],
                    'line2' => $shipping['line2'] ?? null,
                    'city' => $shipping['city'] ?? null,
                    'state' => $shipping['state'] ?? null,
                    'postal_code' => $shipping['postal_code'],
                    'country' => ($shipping['country'] ?? null) ?: 'SG',
                    'notes' => $shipping['notes'] ?? null,
                ]);
            }
```

- [ ] **Step 5: Write the failing tests**

Create `tests/Feature/CheckoutShippingTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Product;
use App\Models\SavedAddress;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    seedPricing();
});

function checkoutLineItems(): array
{
    $product = Product::factory()->create(['publish_state' => 'PUBLISHED']);

    return [['product_id' => $product->id, 'variant_id' => null, 'qty' => 1]];
}

function shippingPayload(array $overrides = []): array
{
    return array_merge([
        'recipient_name' => 'Rachel Tan',
        'phone' => '+6591234567',
        'line1' => '1 Marina Blvd',
        'postal_code' => '018989',
    ], $overrides);
}

it('rejects a buyer checkout with no shipping address', function (): void {
    $company = Company::factory()->create();
    Sanctum::actingAs(User::factory()->create(['company_id' => $company->id, 'role' => 'buyer']));

    $this->postJson('/api/quotes', [
        'company_id' => $company->id,
        'line_items' => checkoutLineItems(),
    ])->assertStatus(422)->assertJsonValidationErrors('shipping_address');
});

it('snapshots the shipping address onto the quote', function (): void {
    $company = Company::factory()->create();
    Sanctum::actingAs(User::factory()->create(['company_id' => $company->id, 'role' => 'buyer']));

    $response = $this->postJson('/api/quotes', [
        'company_id' => $company->id,
        'line_items' => checkoutLineItems(),
        'shipping_address' => shippingPayload(['recipient_name' => 'Site B Reception']),
    ])->assertCreated();

    $quoteId = $response->json('data.id');
    $this->getJson("/api/quotes/{$quoteId}/shipping-address");

    $addr = \App\Models\Quote::find($quoteId)->shippingAddress;
    expect($addr->recipient_name)->toBe('Site B Reception')
        ->and($addr->postal_code)->toBe('018989')
        ->and($addr->country)->toBe('SG');
});

it('keeps the order address unchanged when a saved address is later edited', function (): void {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id, 'role' => 'buyer']);
    Sanctum::actingAs($user);

    // Buyer had a saved address and checked out with a copy of its text.
    $saved = SavedAddress::factory()->create(['user_id' => $user->id, 'recipient_name' => 'Original']);
    $response = $this->postJson('/api/quotes', [
        'company_id' => $company->id,
        'line_items' => checkoutLineItems(),
        'shipping_address' => shippingPayload(['recipient_name' => 'Original']),
    ])->assertCreated();
    $quoteId = $response->json('data.id');

    // Later they rename the saved address.
    $saved->update(['recipient_name' => 'Renamed']);

    expect(\App\Models\Quote::find($quoteId)->shippingAddress->recipient_name)->toBe('Original');
});

it('lets staff create a quote without a shipping address (company default)', function (): void {
    $company = Company::factory()->create(['address' => '10 Anson Rd']);
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $response = $this->postJson('/api/quotes', [
        'company_id' => $company->id,
        'line_items' => checkoutLineItems(),
    ])->assertCreated();

    $quote = \App\Models\Quote::find($response->json('data.id'));
    expect($quote->shippingAddress)->toBeNull()
        ->and($quote->shippingAddressOrDefault()['line1'])->toBe('10 Anson Rd');
});
```

Note: if `seedPricing()` is not auto-loaded, mirror how existing feature tests pull it in (e.g. `tests/Feature/PricingServiceTest.php` calls `seedPricing()` from `tests/Pest.php` / a helpers file — no extra import needed there).

- [ ] **Step 6: Run the tests**

Run: `php artisan test --filter=CheckoutShippingTest`
Expected: all PASS.

- [ ] **Step 7: Run the full quote suite to confirm no regression**

Run: `php artisan test --filter=Quote`
Expected: all PASS (staff/buyer quote flows unaffected).

- [ ] **Step 8: Commit**

```bash
git add app/Http/Requests/StoreQuoteRequest.php app/Http/Controllers/QuoteController.php app/Services/QuoteService.php tests/Feature/CheckoutShippingTest.php
git commit -m "feat(shipping): snapshot checkout address onto the quote (Approach A)"
```

---

## Task 5: Frontend types + address-book store

**Files:**
- Modify: `frontend/src/types.ts`
- Create: `frontend/src/stores/savedAddressStore.ts`

- [ ] **Step 1: Add the types**

In `frontend/src/types.ts`, add near the other interfaces:

```typescript
/** Structured ship-to captured at checkout / stored on a quote. No id. */
export interface ShippingAddressInput {
  recipient_name: string;
  phone: string;
  email?: string | null;
  line1: string;
  line2?: string | null;
  city?: string | null;
  state?: string | null;
  postal_code: string;
  country: string;
  notes?: string | null;
}

/** A buyer's saved address book entry (max 3 per user). */
export interface SavedAddress extends ShippingAddressInput {
  id: number;
  label: string | null;
}
```

- [ ] **Step 2: Create the store**

Create `frontend/src/stores/savedAddressStore.ts`:

```typescript
import { create } from 'zustand';
import { api, apiError, ensureCsrf } from '../lib/api';
import type { SavedAddress } from '../types';

interface SavedAddressState {
  addresses: SavedAddress[];
  loading: boolean;
  error: string | null;
  fetch: () => Promise<void>;
  create: (payload: Omit<SavedAddress, 'id'>) => Promise<boolean>;
  update: (id: number, payload: Omit<SavedAddress, 'id'>) => Promise<boolean>;
  remove: (id: number) => Promise<boolean>;
}

export const MAX_SAVED_ADDRESSES = 3;

export const useSavedAddressStore = create<SavedAddressState>((set, get) => ({
  addresses: [],
  loading: false,
  error: null,

  fetch: async () => {
    set({ loading: true, error: null });
    try {
      const { data } = await api.get<{ data: SavedAddress[] }>('/saved-addresses');
      set({ addresses: data.data, loading: false });
    } catch (err) {
      set({ loading: false, error: apiError(err) });
    }
  },

  create: async (payload) => {
    set({ error: null });
    try {
      await ensureCsrf();
      const { data } = await api.post<{ data: SavedAddress }>('/saved-addresses', payload);
      set({ addresses: [data.data, ...get().addresses] });
      return true;
    } catch (err) {
      set({ error: apiError(err) });
      return false;
    }
  },

  update: async (id, payload) => {
    set({ error: null });
    try {
      await ensureCsrf();
      const { data } = await api.put<{ data: SavedAddress }>(`/saved-addresses/${id}`, payload);
      set({ addresses: get().addresses.map((a) => (a.id === id ? data.data : a)) });
      return true;
    } catch (err) {
      set({ error: apiError(err) });
      return false;
    }
  },

  remove: async (id) => {
    set({ error: null });
    try {
      await ensureCsrf();
      await api.delete(`/saved-addresses/${id}`);
      set({ addresses: get().addresses.filter((a) => a.id !== id) });
      return true;
    } catch (err) {
      set({ error: apiError(err) });
      return false;
    }
  },
}));
```

Note: confirm `api`, `apiError`, and `ensureCsrf` are the exact exports in `frontend/src/lib/api.ts` (they are used by `quoteStore.ts`). If `ensureCsrf` has a different name there, match it.

- [ ] **Step 3: Typecheck**

Run: `cd frontend && npx tsc --noEmit`
Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/types.ts frontend/src/stores/savedAddressStore.ts
git commit -m "feat(shipping): frontend types + saved-address store"
```

---

## Task 6: Shared shipping form fields component

**Files:**
- Create: `frontend/src/components/checkout/ShippingFields.tsx`
- Test: `frontend/src/components/checkout/ShippingFields.test.tsx`

- [ ] **Step 1: Create the component**

Create `frontend/src/components/checkout/ShippingFields.tsx`:

```tsx
import { Input } from '../../ui';
import type { ShippingAddressInput } from '../../types';

/** Value carried by the form: the ship-to fields plus an optional book label. */
export interface ShippingFieldsValue extends ShippingAddressInput {
  label?: string | null;
}

export const EMPTY_SHIPPING: ShippingFieldsValue = {
  label: '',
  recipient_name: '',
  phone: '',
  email: '',
  line1: '',
  line2: '',
  city: '',
  state: '',
  postal_code: '',
  country: 'SG',
  notes: '',
};

/** The four fields the courier must have; used to gate submission. */
export function isShippingValid(v: ShippingFieldsValue): boolean {
  return (
    v.recipient_name.trim() !== '' &&
    v.phone.trim() !== '' &&
    v.line1.trim() !== '' &&
    v.postal_code.trim() !== ''
  );
}

interface Props {
  value: ShippingFieldsValue;
  onChange: (next: ShippingFieldsValue) => void;
  /** Show the address-book label field (address book only, not checkout). */
  showLabel?: boolean;
  idPrefix?: string;
}

export default function ShippingFields({ value, onChange, showLabel = false, idPrefix = 'ship' }: Props) {
  const set = (field: keyof ShippingFieldsValue) => (e: React.ChangeEvent<HTMLInputElement>) =>
    onChange({ ...value, [field]: e.target.value });

  return (
    <div className="grid gap-3 sm:grid-cols-2">
      {showLabel && (
        <label className="flex flex-col gap-1 text-sm sm:col-span-2">
          <span className="text-fg-subtle">Label (optional)</span>
          <Input value={value.label ?? ''} onChange={set('label')} placeholder="Office, Warehouse…" />
        </label>
      )}
      <label className="flex flex-col gap-1 text-sm">
        <span className="text-fg-subtle">Recipient name *</span>
        <Input value={value.recipient_name} onChange={set('recipient_name')} required />
      </label>
      <label className="flex flex-col gap-1 text-sm">
        <span className="text-fg-subtle">Phone *</span>
        <Input value={value.phone} onChange={set('phone')} required />
      </label>
      <label className="flex flex-col gap-1 text-sm sm:col-span-2">
        <span className="text-fg-subtle">Address line 1 *</span>
        <Input value={value.line1} onChange={set('line1')} required />
      </label>
      <label className="flex flex-col gap-1 text-sm sm:col-span-2">
        <span className="text-fg-subtle">Address line 2</span>
        <Input value={value.line2 ?? ''} onChange={set('line2')} />
      </label>
      <label className="flex flex-col gap-1 text-sm">
        <span className="text-fg-subtle">City</span>
        <Input value={value.city ?? ''} onChange={set('city')} />
      </label>
      <label className="flex flex-col gap-1 text-sm">
        <span className="text-fg-subtle">Postal code *</span>
        <Input value={value.postal_code} onChange={set('postal_code')} required />
      </label>
      <label className="flex flex-col gap-1 text-sm">
        <span className="text-fg-subtle">State / region</span>
        <Input value={value.state ?? ''} onChange={set('state')} />
      </label>
      <label className="flex flex-col gap-1 text-sm">
        <span className="text-fg-subtle">Country *</span>
        <Input value={value.country} onChange={set('country')} maxLength={2} required />
      </label>
      <label className="flex flex-col gap-1 text-sm sm:col-span-2">
        <span className="text-fg-subtle">Delivery notes</span>
        <Input value={value.notes ?? ''} onChange={set('notes')} />
      </label>
    </div>
  );
}
```

Note: confirm `Input` is exported from `frontend/src/ui` (it is used in `SiteHeader.tsx`). If it needs different props, match the existing `Input` API.

- [ ] **Step 2: Write the failing test**

Create `frontend/src/components/checkout/ShippingFields.test.tsx`:

```tsx
import { expect, it } from 'vitest';
import { EMPTY_SHIPPING, isShippingValid } from './ShippingFields';

it('requires recipient, phone, line1, and postal code', () => {
  expect(isShippingValid(EMPTY_SHIPPING)).toBe(false);
  expect(
    isShippingValid({
      ...EMPTY_SHIPPING,
      recipient_name: 'A',
      phone: '+6591234567',
      line1: '1 Marina Blvd',
      postal_code: '018989',
    }),
  ).toBe(true);
});
```

- [ ] **Step 3: Run the test**

Run: `cd frontend && npx vitest run src/components/checkout/ShippingFields.test.tsx`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/components/checkout/ShippingFields.tsx frontend/src/components/checkout/ShippingFields.test.tsx
git commit -m "feat(shipping): shared ShippingFields form component"
```

---

## Task 7: createQuote carries the shipping address

**Files:**
- Modify: `frontend/src/stores/quoteStore.ts:34-40,89-113`

- [ ] **Step 1: Extend the createQuote type**

In `frontend/src/stores/quoteStore.ts`, add the import:

```typescript
import type { ShippingAddressInput } from '../types';
```

(Adjust if `types` is already imported — add `ShippingAddressInput` to the existing import.) Update the `createQuote` signature in the state interface:

```typescript
  createQuote: (
    companyId: number,
    lines: CartLine[],
    notes: string | null,
    neededBy?: string | null,
    idempotencyKey?: string | null,
    shippingAddress?: ShippingAddressInput | null,
  ) => Promise<Quote | null>;
```

- [ ] **Step 2: Send it in the request body**

Update the `createQuote` implementation to accept and forward the address:

```typescript
  createQuote: async (companyId, lines, notes, neededBy = null, idempotencyKey = null, shippingAddress = null) => {
    set({ error: null });
    try {
      await ensureCsrf();
      const { data } = await api.post<{ data: Quote }>('/quotes', {
        company_id: companyId,
        notes,
        needed_by: neededBy || null,
        idempotency_key: idempotencyKey,
        // Buyer's checkout ship-to; snapshotted server-side onto the quote.
        shipping_address: shippingAddress,
        line_items: lines.map((l) => ({
          product_id: l.product.id,
          variant_id: l.variant?.id ?? null,
          qty: l.qty,
          customization: l.customization,
        })),
      });
      return data.data;
    } catch (err) {
      set({ error: apiError(err) });
      return null;
    }
  },
```

- [ ] **Step 3: Typecheck**

Run: `cd frontend && npx tsc --noEmit`
Expected: `CheckoutPage.tsx` may now error because it does not yet pass the address — that is fixed in Task 8. If it errors ONLY there, proceed; otherwise fix the reported issue.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/stores/quoteStore.ts
git commit -m "feat(shipping): createQuote accepts a shipping address"
```

---

## Task 8: Checkout — address picker + form + validation

**Files:**
- Modify: `frontend/src/pages/CheckoutPage.tsx`
- Test: `frontend/src/pages/CheckoutPage.test.tsx`

- [ ] **Step 1: Add imports and load saved addresses**

In `frontend/src/pages/CheckoutPage.tsx`, add imports:

```typescript
import ShippingFields, {
  EMPTY_SHIPPING,
  isShippingValid,
  type ShippingFieldsValue,
} from '../components/checkout/ShippingFields';
import { useSavedAddressStore } from '../stores/savedAddressStore';
import type { SavedAddress, ShippingAddressInput } from '../types';
```

Inside the component, after the existing store hooks, add:

```typescript
  const savedAddresses = useSavedAddressStore((s) => s.addresses);
  const fetchSaved = useSavedAddressStore((s) => s.fetch);

  // 'company' | 'new' | a saved-address id (as string)
  const [selection, setSelection] = useState<string>('company');
  const [shipping, setShipping] = useState<ShippingFieldsValue>(EMPTY_SHIPPING);

  useEffect(() => {
    if (user) void fetchSaved();
  }, [user, fetchSaved]);
```

- [ ] **Step 2: Add the prefill helpers (module scope, above the component)**

```typescript
function companyToShipping(company: { name?: string | null; phone?: string | null; address?: string | null } | null): ShippingFieldsValue {
  return {
    ...EMPTY_SHIPPING,
    recipient_name: company?.name ?? '',
    phone: company?.phone ?? '',
    line1: company?.address ?? '',
  };
}

function savedToShipping(a: SavedAddress): ShippingFieldsValue {
  return {
    label: a.label ?? '',
    recipient_name: a.recipient_name,
    phone: a.phone,
    email: a.email ?? '',
    line1: a.line1,
    line2: a.line2 ?? '',
    city: a.city ?? '',
    state: a.state ?? '',
    postal_code: a.postal_code,
    country: a.country || 'SG',
    notes: a.notes ?? '',
  };
}

function toShippingInput(v: ShippingFieldsValue): ShippingAddressInput {
  return {
    recipient_name: v.recipient_name.trim(),
    phone: v.phone.trim(),
    email: v.email?.trim() || null,
    line1: v.line1.trim(),
    line2: v.line2?.trim() || null,
    city: v.city?.trim() || null,
    state: v.state?.trim() || null,
    postal_code: v.postal_code.trim(),
    country: (v.country || 'SG').trim(),
    notes: v.notes?.trim() || null,
  };
}
```

- [ ] **Step 3: Prefill the form when the selection or defaults change**

Add this effect after the `useEffect` that fetches saved addresses:

```typescript
  useEffect(() => {
    if (selection === 'company') {
      setShipping(companyToShipping(company));
    } else if (selection === 'new') {
      setShipping(EMPTY_SHIPPING);
    } else {
      const picked = savedAddresses.find((a) => String(a.id) === selection);
      if (picked) setShipping(savedToShipping(picked));
    }
  }, [selection, company, savedAddresses]);
```

- [ ] **Step 4: Gate placeOrder on a valid address and send it**

Change the `placeOrder` body's `createQuote` call to include the address, and add a validity guard at the top of `placeOrder` (after the existing `user`/`company_id` guards):

```typescript
    if (!isShippingValid(shipping)) {
      setSubmitError('Please complete the shipping address (recipient, phone, address, postal code).');
      return;
    }
```

And update the call:

```typescript
    const quote = await createQuote(
      user.company_id,
      lines,
      null,
      neededBy,
      idempotencyKey.current,
      toShippingInput(shipping),
    );
```

- [ ] **Step 5: Replace the read-only "Ships to" block with the picker + form**

In the Delivery `Card` (the `{user && ( ... )}` block), replace the `Ships to` `<div>` (the one rendering `company?.address`) with:

```tsx
                <div className="flex flex-col gap-2">
                  <label className="flex flex-col gap-1">
                    <span className="text-fg-subtle">Ship to</span>
                    <select
                      value={selection}
                      onChange={(e) => setSelection(e.target.value)}
                      className="h-11 rounded-md border border-border bg-surface px-3 text-sm text-fg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                      {savedAddresses.map((a) => (
                        <option key={a.id} value={String(a.id)}>
                          {a.label ? `${a.label} — ${a.line1}` : a.line1}
                        </option>
                      ))}
                      <option value="company">Company default address</option>
                      <option value="new">Enter a new address</option>
                    </select>
                  </label>
                  <ShippingFields value={shipping} onChange={setShipping} />
                  {!isShippingValid(shipping) && (
                    <p className="text-xs text-fg-subtle">
                      Complete recipient, phone, address line 1, and postal code to place the order.
                    </p>
                  )}
                </div>
```

Leave the existing "Need it by" row directly below, unchanged.

- [ ] **Step 6: Default the selection to the first saved address**

Update the initial `selection` effect so that when saved addresses load and the user hasn't chosen yet, the first saved address is selected. Replace the fetch effect from Step 1 with:

```typescript
  useEffect(() => {
    if (!user) return;
    void fetchSaved();
  }, [user, fetchSaved]);

  useEffect(() => {
    if (savedAddresses.length > 0 && selection === 'company') {
      setSelection(String(savedAddresses[0].id));
    }
    // Only nudge the default once, when addresses first arrive.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [savedAddresses]);
```

- [ ] **Step 7: Update the anonymous-checkout test and add a validation test**

In `frontend/src/pages/CheckoutPage.test.tsx`, add after the existing test:

```tsx
import { fireEvent } from '@testing-library/react';
import { useSavedAddressStore } from '../stores/savedAddressStore';

it('blocks placing the order until the shipping address is valid', () => {
  useSavedAddressStore.setState({ addresses: [], loading: false, error: null });
  useCartStore.setState({
    lines: [{ key: 'k', product: { id: 5, name: 'A5' } as any, variant: null, qty: 1, customization: {} }],
  });
  useAuthStore.setState({
    user: { id: 1, company_id: 1, role: 'buyer', company: { name: 'Acme', address: '' } } as any,
    status: 'ready',
  } as any);

  render(
    <ThemeProvider>
      <MemoryRouter initialEntries={['/checkout']}>
        <Routes>
          <Route path="/checkout" element={<CheckoutPage />} />
        </Routes>
      </MemoryRouter>
    </ThemeProvider>,
  );

  fireEvent.click(screen.getByRole('button', { name: /place order/i }));
  expect(screen.getByText(/complete the shipping address/i)).toBeInTheDocument();
});
```

Note: match the exact "Place order" button label in `CheckoutPage.tsx` (read the file). If the anonymous test relied on `useSavedAddressStore` being unset, add `useSavedAddressStore.setState({ addresses: [] })` to its setup too.

- [ ] **Step 8: Run tests + typecheck**

Run: `cd frontend && npx tsc --noEmit && npx vitest run src/pages/CheckoutPage.test.tsx`
Expected: no type errors; tests PASS.

- [ ] **Step 9: Commit**

```bash
git add frontend/src/pages/CheckoutPage.tsx frontend/src/pages/CheckoutPage.test.tsx
git commit -m "feat(shipping): checkout address picker, form, and validation gate"
```

---

## Task 9: Header account menu

**Files:**
- Modify: `frontend/src/components/SiteHeader.tsx`
- Test: `frontend/src/components/SiteHeader.test.tsx`

- [ ] **Step 1: Add a buyer account dropdown**

In `frontend/src/components/SiteHeader.tsx`, add an `AccountMenu` component (model it on the existing `CategoriesMenu` disclosure pattern — click-outside close, Escape restores focus, `aria-expanded`/`aria-haspopup`):

```tsx
function AccountMenu({ user, onLogout }: { user: User; onLogout: () => void }) {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);
  const buttonRef = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    if (!open) return;
    const onDocClick = (e: MouseEvent) => {
      if (!ref.current?.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', onDocClick);
    return () => document.removeEventListener('mousedown', onDocClick);
  }, [open]);

  return (
    <div
      ref={ref}
      className="relative"
      onKeyDown={(e) => {
        if (e.key === 'Escape') {
          setOpen(false);
          buttonRef.current?.focus();
        }
      }}
    >
      <button
        ref={buttonRef}
        type="button"
        aria-expanded={open}
        aria-haspopup="true"
        onClick={() => setOpen((o) => !o)}
        className={cn(
          'flex min-h-[44px] items-center gap-1 rounded-md px-3 py-2 text-sm font-medium transition-colors duration-fast',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
          open ? 'bg-surface-2 text-fg' : 'text-fg-muted hover:bg-surface-2 hover:text-fg',
        )}
      >
        {user.name} <span aria-hidden="true">▾</span>
      </button>
      {open && (
        <div className="absolute right-0 top-full z-dropdown mt-1 flex w-48 flex-col rounded-lg border border-border bg-surface p-1 shadow-lg">
          <Link
            to="/quotes"
            onClick={() => setOpen(false)}
            className="rounded-md px-3 py-2 text-sm text-fg hover:bg-surface-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          >
            My Orders
          </Link>
          <Link
            to="/account/addresses"
            onClick={() => setOpen(false)}
            className="rounded-md px-3 py-2 text-sm text-fg hover:bg-surface-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          >
            Addresses
          </Link>
          <button
            type="button"
            onClick={() => {
              setOpen(false);
              onLogout();
            }}
            className="rounded-md px-3 py-2 text-left text-sm text-fg hover:bg-surface-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          >
            Log out
          </button>
        </div>
      )}
    </div>
  );
}
```

- [ ] **Step 2: Use the dropdown for buyers in the desktop bar**

Replace the desktop account block (the `<div className="hidden md:flex md:items-center md:gap-1">` containing `<AccountLink>` and the Log out `<Button>`) with:

```tsx
          <div className="hidden md:flex md:items-center md:gap-1">
            {user && !isStaffRole(user.role) ? (
              <AccountMenu user={user} onLogout={onLogout} />
            ) : (
              <>
                <AccountLink user={user} />
                {user && (
                  <Button variant="ghost" size="sm" onClick={onLogout}>
                    Log out
                  </Button>
                )}
              </>
            )}
          </div>
```

- [ ] **Step 3: Add the Addresses link to the mobile drawer**

In `MobileDrawer`, in the account section (the `<div className="mt-2 flex flex-col gap-1 border-t border-border pt-3">`), add an Addresses link for buyers just above `<AccountLink ... />`:

```tsx
              {user && !isStaffRole(user.role) && (
                <NavLink to="/account/addresses" onClick={onClose} className={navLinkClass}>
                  Addresses
                </NavLink>
              )}
```

- [ ] **Step 4: Write / extend the header test**

In `frontend/src/components/SiteHeader.test.tsx`, add a test that a buyer sees the account menu with an Addresses item. Follow the file's existing render harness (auth store setup + `MemoryRouter`). Example:

```tsx
it('shows a buyer account menu with Addresses', async () => {
  useAuthStore.setState({ user: { id: 1, name: 'Rachel', role: 'buyer', company_id: 1 } as any, status: 'ready' } as any);
  render(
    <ThemeProvider>
      <MemoryRouter>
        <SiteHeader />
      </MemoryRouter>
    </ThemeProvider>,
  );
  fireEvent.click(screen.getByRole('button', { name: /rachel/i }));
  expect(screen.getByRole('link', { name: /addresses/i })).toBeInTheDocument();
});
```

Match the exact imports already used at the top of `SiteHeader.test.tsx` (add `fireEvent` if absent).

- [ ] **Step 5: Run tests + typecheck**

Run: `cd frontend && npx tsc --noEmit && npx vitest run src/components/SiteHeader.test.tsx`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/components/SiteHeader.tsx frontend/src/components/SiteHeader.test.tsx
git commit -m "feat(shipping): buyer account menu in header + drawer"
```

---

## Task 10: Address book page

**Files:**
- Create: `frontend/src/pages/AddressBookPage.tsx`
- Modify: `frontend/src/App.tsx`
- Test: `frontend/src/pages/AddressBookPage.test.tsx`

- [ ] **Step 1: Create the page**

Create `frontend/src/pages/AddressBookPage.tsx`:

```tsx
import { useEffect, useState } from 'react';
import { Button, Card } from '../ui';
import { MAX_SAVED_ADDRESSES, useSavedAddressStore } from '../stores/savedAddressStore';
import ShippingFields, {
  EMPTY_SHIPPING,
  isShippingValid,
  type ShippingFieldsValue,
} from '../components/checkout/ShippingFields';
import type { SavedAddress } from '../types';

function toValue(a: SavedAddress): ShippingFieldsValue {
  return {
    label: a.label ?? '',
    recipient_name: a.recipient_name,
    phone: a.phone,
    email: a.email ?? '',
    line1: a.line1,
    line2: a.line2 ?? '',
    city: a.city ?? '',
    state: a.state ?? '',
    postal_code: a.postal_code,
    country: a.country || 'SG',
    notes: a.notes ?? '',
  };
}

export default function AddressBookPage() {
  const { addresses, error, fetch, create, update, remove } = useSavedAddressStore();
  const [editing, setEditing] = useState<number | 'new' | null>(null);
  const [form, setForm] = useState<ShippingFieldsValue>(EMPTY_SHIPPING);

  useEffect(() => {
    void fetch();
  }, [fetch]);

  const startNew = () => {
    setForm(EMPTY_SHIPPING);
    setEditing('new');
  };
  const startEdit = (a: SavedAddress) => {
    setForm(toValue(a));
    setEditing(a.id);
  };

  const save = async () => {
    const payload = {
      label: form.label?.trim() || null,
      recipient_name: form.recipient_name.trim(),
      phone: form.phone.trim(),
      email: form.email?.trim() || null,
      line1: form.line1.trim(),
      line2: form.line2?.trim() || null,
      city: form.city?.trim() || null,
      state: form.state?.trim() || null,
      postal_code: form.postal_code.trim(),
      country: (form.country || 'SG').trim(),
      notes: form.notes?.trim() || null,
    };
    const ok = editing === 'new' ? await create(payload) : await update(editing as number, payload);
    if (ok) setEditing(null);
  };

  return (
    <section aria-labelledby="addresses-heading" className="mx-auto max-w-2xl">
      <h1 id="addresses-heading" className="mb-6 font-display text-3xl text-fg">
        Saved addresses
      </h1>

      {error && <p className="mb-4 text-sm text-danger" role="alert">{error}</p>}

      <div className="flex flex-col gap-3">
        {addresses.map((a) => (
          <Card key={a.id} padding="lg">
            <div className="flex items-start justify-between gap-4">
              <div className="min-w-0 text-sm">
                {a.label && <p className="font-medium text-fg">{a.label}</p>}
                <p className="text-fg">{a.recipient_name}</p>
                <p className="text-fg-muted">{a.line1}{a.line2 ? `, ${a.line2}` : ''}</p>
                <p className="text-fg-muted">{a.postal_code} · {a.country}</p>
              </div>
              <div className="flex shrink-0 gap-2">
                <Button variant="ghost" size="sm" onClick={() => startEdit(a)}>Edit</Button>
                <Button variant="ghost" size="sm" onClick={() => void remove(a.id)}>Delete</Button>
              </div>
            </div>
          </Card>
        ))}
      </div>

      {editing !== null ? (
        <Card padding="lg" className="mt-4">
          <h2 className="mb-3 font-display text-xl text-fg">
            {editing === 'new' ? 'Add address' : 'Edit address'}
          </h2>
          <ShippingFields value={form} onChange={setForm} showLabel idPrefix="book" />
          <div className="mt-4 flex gap-2">
            <Button variant="primary" onClick={() => void save()} disabled={!isShippingValid(form)}>
              Save
            </Button>
            <Button variant="ghost" onClick={() => setEditing(null)}>Cancel</Button>
          </div>
        </Card>
      ) : (
        addresses.length < MAX_SAVED_ADDRESSES && (
          <Button variant="secondary" className="mt-4" onClick={startNew}>
            Add address
          </Button>
        )
      )}

      {addresses.length >= MAX_SAVED_ADDRESSES && editing === null && (
        <p className="mt-3 text-xs text-fg-subtle">
          You&rsquo;ve saved the maximum of {MAX_SAVED_ADDRESSES} addresses. Delete one to add another.
        </p>
      )}
    </section>
  );
}
```

Note: confirm `Card` accepts a `padding` prop and `Button` accepts `variant`/`size` (both are used across the app, e.g. `CartPage.tsx`). Match actual APIs.

- [ ] **Step 2: Register the route**

In `frontend/src/App.tsx`, add the lazy import near the other page imports:

```typescript
const AddressBookPage = lazy(() => import('./pages/AddressBookPage'));
```

(Match the existing lazy-import style in the file.) Add the route inside the `ProtectedRoute` group (beside `quotes`):

```tsx
              <Route path="account/addresses" element={<AddressBookPage />} />
```

- [ ] **Step 3: Write the failing test**

Create `frontend/src/pages/AddressBookPage.test.tsx`:

```tsx
import { expect, it, afterEach, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { ThemeProvider } from '../ui';
import AddressBookPage from './AddressBookPage';
import { useSavedAddressStore } from '../stores/savedAddressStore';

afterEach(() => {
  useSavedAddressStore.setState({ addresses: [], loading: false, error: null });
});

it('hides Add when three addresses exist', () => {
  useSavedAddressStore.setState({
    addresses: [
      { id: 1, label: 'A', recipient_name: 'x', phone: '1', line1: 'l1', postal_code: 'p', country: 'SG' },
      { id: 2, label: 'B', recipient_name: 'x', phone: '1', line1: 'l1', postal_code: 'p', country: 'SG' },
      { id: 3, label: 'C', recipient_name: 'x', phone: '1', line1: 'l1', postal_code: 'p', country: 'SG' },
    ] as any,
    fetch: vi.fn(),
  } as any);

  render(
    <ThemeProvider>
      <MemoryRouter>
        <AddressBookPage />
      </MemoryRouter>
    </ThemeProvider>,
  );

  expect(screen.queryByRole('button', { name: /add address/i })).not.toBeInTheDocument();
  expect(screen.getByText(/maximum of 3/i)).toBeInTheDocument();
});
```

- [ ] **Step 4: Run tests + typecheck**

Run: `cd frontend && npx tsc --noEmit && npx vitest run src/pages/AddressBookPage.test.tsx`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/pages/AddressBookPage.tsx frontend/src/pages/AddressBookPage.test.tsx frontend/src/App.tsx
git commit -m "feat(shipping): address book page at /account/addresses"
```

---

## Task 11: Full-suite verification

**Files:** none (verification only)

- [ ] **Step 1: Backend suite**

Run: `php artisan test`
Expected: all PASS (esp. `SavedAddressTest`, `CheckoutShippingTest`, `Quote*`, `ShippingAddressTest`).

- [ ] **Step 2: Frontend typecheck + tests**

Run: `cd frontend && npx tsc --noEmit && npx vitest run`
Expected: no type errors; all tests PASS.

- [ ] **Step 3: Manual smoke via preview (per verification workflow)**

Start the dev servers (`api` + `frontend` from `.claude/launch.json`), sign in as a buyer, and confirm:
- Header shows the account menu → Addresses opens `/account/addresses`.
- Add up to 3 addresses; the 4th Add is hidden; server rejects a forced 4th (422).
- At `/checkout`, the picker lists saved addresses + Company default + New; selecting prefills; Place order is blocked until the four required fields are filled.
- Place an order, then edit the saved address → the placed quote's ship-to is unchanged (check `/quotes/:id` or DB).

- [ ] **Step 4: Final commit (if any smoke fixes were needed)**

```bash
git add -A
git commit -m "test(shipping): full-suite verification fixes"
```

---

## Self-Review Notes

- **Spec coverage:** data model (Task 1), per-user max-3 (Tasks 1/3), owner-only policy (Task 2), CRUD routes (Task 3), Approach-A snapshot in the quote transaction (Task 4), buyer-required/staff-optional validation (Task 4), snapshot immutability test (Task 4), types + store (Task 5), shared fields (Task 6), createQuote payload (Task 7), checkout picker/form/validation (Task 8), header account menu + drawer (Task 9), address book page + route (Task 10), full verification (Task 11). All spec sections mapped.
- **Type consistency:** `ShippingAddressInput` / `SavedAddress` (types) → `ShippingFieldsValue` (fields component, extends input + `label`) → `toShippingInput()` narrows the form value to the API payload; `createQuote(..., shippingAddress?: ShippingAddressInput | null)` matches the store call in Task 8. `isShippingValid` / `EMPTY_SHIPPING` / `MAX_SAVED_ADDRESSES` are defined once and imported everywhere they are used.
- **Assumptions to confirm during execution (called out inline):** exact `api`/`ensureCsrf` exports in `lib/api.ts`; `Input`/`Card`/`Button` prop APIs in `ui`; the exact "Place order" button label; `seedPricing()` availability in feature tests; existing lazy-import style in `App.tsx`.
