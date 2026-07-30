# Feature A — Per-order approval_order (price/proof-first) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give each quote a staff-controllable `approval_order` (`price_first` default, `proof_first` opt-in) and enforce the chosen ordering, without adding any new quote states or transition edges.

**Architecture:** The `QuoteState` transition table already carries both routes (`ARTWORK_APPROVED` exists; `accept()` lands on `PROOF_APPROVED` from `ARTWORK_APPROVED`; `sendProofs()` moves `DRAFT → PROOFING`). Ordering was implicit and unenforced. We add a persisted enum column plus three guards at the `QuoteService` action entry points (`send`, `sendProofs`, `accept`) — the only layer that sees both the flag and whether the order has proof-needing lines. Plain-stock orders (no proof-needing line) stay a no-op for both orderings.

**Tech Stack:** Laravel 11, PHP 8.3 backed enums, Pest v3, Eloquent, SQLite test DB. Frontend: React + Vitest/RTL + Zustand store.

**Spec:** `docs/superpowers/specs/2026-07-30-giftlab-feature-a-approval-order-design.md`

**Owner decisions:** A-1 price shown read-only in proof_first; A-2 lock at send for staff, superadmin override after send; A-3 global default `price_first`.

**Conventions found in repo:**
- Backed enums live in `app/Enums/*.php`, `declare(strict_types=1)`, `enum X: string { case … = 'VALUE'; }` (see `app/Enums/PaymentState.php`).
- Domain violations throw `App\Exceptions\DomainRuleException`.
- Superadmin check: `Auth::user()?->isSuperadmin()` (see `QuoteService::amend()`).
- Audit: `$this->audit->log($model, 'event.name', $before, $after)`.
- Run one backend test file: `php artisan test tests/Feature/ApprovalOrderTest.php`
- Run one frontend test: `cd frontend && npx vitest run src/pages/QuoteDetailPage.test.tsx`

---

## File structure

- Create: `app/Enums/ApprovalOrder.php` — the enum.
- Create: `database/migrations/2026_07_30_000001_add_approval_order_to_quotes.php` — additive column.
- Modify: `app/Models/Quote.php` — `$fillable` + cast.
- Modify: `database/factories/QuoteFactory.php` — allow the column, add `proofFirst()` state.
- Modify: `app/Services/QuoteService.php` — `setApprovalOrder()` + `requiresProofFirst()`/`requiresPriceFirst()` predicates + 3 guards.
- Create: `app/Http/Requests/SetApprovalOrderRequest.php` — enum-validated body.
- Modify: `app/Http/Controllers/QuoteController.php` — `setApprovalOrder` action.
- Modify: `routes/api.php` — `PATCH /quotes/{quote}/approval-order`.
- Modify: `app/Http/Resources/QuoteResource.php` — expose `approval_order`.
- Create: `tests/Feature/ApprovalOrderTest.php` — backend behaviour.
- Modify: `frontend/src/stores/quoteStore.ts` — `setApprovalOrder` API call.
- Modify: `frontend/src/pages/QuoteDetailPage.tsx` — DRAFT toggle + next-step copy.
- Modify: `frontend/src/pages/QuoteDetailPage.test.tsx` — toggle + copy tests.

---

### Task 1: `ApprovalOrder` enum + column + model + factory

**Files:**
- Create: `app/Enums/ApprovalOrder.php`
- Create: `database/migrations/2026_07_30_000001_add_approval_order_to_quotes.php`
- Modify: `app/Models/Quote.php:56-101`
- Modify: `database/factories/QuoteFactory.php`
- Test: `tests/Feature/ApprovalOrderTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ApprovalOrderTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\ApprovalOrder;
use App\Models\Quote;

it('defaults a new quote to price_first', function (): void {
    $quote = Quote::factory()->create();

    expect($quote->approval_order)->toBe(ApprovalOrder::PriceFirst);
});

it('persists proof_first via the factory state', function (): void {
    $quote = Quote::factory()->proofFirst()->create();

    expect($quote->fresh()->approval_order)->toBe(ApprovalOrder::ProofFirst);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/ApprovalOrderTest.php`
Expected: FAIL — `Class "App\Enums\ApprovalOrder" not found`.

- [ ] **Step 3a: Create the enum**

Create `app/Enums/ApprovalOrder.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which approval the buyer gives first. price_first (default) is the historic
 * flow: agree the price, then sign off the artwork. proof_first inverts it for
 * jobs where the art must be approved before the price is agreed. Consulted by
 * QuoteService's send/sendProofs/accept guards; a no-op on a plain-stock order
 * that has no proof-needing line.
 */
enum ApprovalOrder: string
{
    case PriceFirst = 'price_first';
    case ProofFirst = 'proof_first';
}
```

- [ ] **Step 3b: Create the migration**

Create `database/migrations/2026_07_30_000001_add_approval_order_to_quotes.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-order approval ordering (Feature A). Additive with a default, so every
 * existing quote reads 'price_first' and behaves exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->string('approval_order', 16)
                ->default('price_first')
                ->after('reminded_phase');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->dropColumn('approval_order');
        });
    }
};
```

- [ ] **Step 3c: Wire the model**

In `app/Models/Quote.php`, add `'approval_order'` to `$fillable` (after `'reminded_phase'` on line 75) and add the cast in `casts()` (after the `'state'` cast on line 87):

```php
// in $fillable, after 'reminded_phase',
'approval_order',
```

```php
// in casts(), after 'state' => QuoteState::class,
'approval_order' => \App\Enums\ApprovalOrder::class,
```

- [ ] **Step 3d: Add the factory state**

In `database/factories/QuoteFactory.php`, add `'approval_order' => 'price_first',` to the `definition()` return array (so it is explicit, not only DB-default), and add this method after `sent()`:

```php
public function proofFirst(): static
{
    return $this->state(fn (): array => [
        'approval_order' => 'proof_first',
    ]);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/ApprovalOrderTest.php`
Expected: PASS (2 passed).

- [ ] **Step 5: Commit**

```bash
git add app/Enums/ApprovalOrder.php database/migrations/2026_07_30_000001_add_approval_order_to_quotes.php app/Models/Quote.php database/factories/QuoteFactory.php tests/Feature/ApprovalOrderTest.php
git commit -m "feat(quotes): add approval_order column, enum, factory state"
```

---

### Task 2: `setApprovalOrder()` service method (A-2 lock/override)

**Files:**
- Modify: `app/Services/QuoteService.php` (add method near `send()`, ~line 735)
- Test: `tests/Feature/ApprovalOrderTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/ApprovalOrderTest.php`:

```php
use App\Enums\QuoteState;
use App\Exceptions\DomainRuleException;
use App\Models\User;
use App\Services\QuoteService;
use Illuminate\Support\Facades\Auth;

it('lets staff set approval_order on a DRAFT quote', function (): void {
    $quote = Quote::factory()->create(['state' => QuoteState::Draft->value]);

    app(QuoteService::class)->setApprovalOrder($quote, ApprovalOrder::ProofFirst);

    expect($quote->fresh()->approval_order)->toBe(ApprovalOrder::ProofFirst);
});

it('refuses to change approval_order once sent for ordinary staff', function (): void {
    $staff = User::factory()->staffAdmin()->create();
    Auth::login($staff);
    $quote = Quote::factory()->sent()->create();

    expect(fn () => app(QuoteService::class)->setApprovalOrder($quote, ApprovalOrder::ProofFirst))
        ->toThrow(DomainRuleException::class);

    expect($quote->fresh()->approval_order)->toBe(ApprovalOrder::PriceFirst);
});

it('lets a superadmin change approval_order after send', function (): void {
    $admin = User::factory()->superadmin()->create();
    Auth::login($admin);
    $quote = Quote::factory()->sent()->create();

    app(QuoteService::class)->setApprovalOrder($quote, ApprovalOrder::ProofFirst);

    expect($quote->fresh()->approval_order)->toBe(ApprovalOrder::ProofFirst);
});
```

> If `User::factory()->superadmin()` / `staffAdmin()` states are named differently, check `database/factories/UserFactory.php` and use the actual state names. `staffAdmin()` is already used across the suite (see `tests/Feature/SlimQuoteFlowTest.php`).

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/ApprovalOrderTest.php`
Expected: FAIL — `Call to undefined method App\Services\QuoteService::setApprovalOrder()`.

- [ ] **Step 3: Implement `setApprovalOrder()`**

In `app/Services/QuoteService.php`, add `use App\Enums\ApprovalOrder;` to the imports, then add this method immediately before `send()` (~line 735):

```php
/**
 * Set the buyer approval ordering for a quote (Feature A). Editable by staff
 * only while the order is still DRAFT; once sent it is locked, EXCEPT for a
 * superadmin, who may still flip it (mirrors amend()'s superadmin override).
 */
public function setApprovalOrder(Quote $quote, ApprovalOrder $order): Quote
{
    if ($quote->state !== QuoteState::Draft && ! (Auth::user()?->isSuperadmin() ?? false)) {
        throw new DomainRuleException('Approval order is locked once the order is sent.');
    }

    if ($quote->approval_order === $order) {
        return $quote;
    }

    $before = $quote->approval_order->value;
    $quote->approval_order = $order;
    $quote->save();

    $this->audit->log($quote, 'quote.approval_order_changed', ['approval_order' => $before], ['approval_order' => $order->value]);

    return $quote;
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test tests/Feature/ApprovalOrderTest.php`
Expected: PASS (5 passed).

- [ ] **Step 5: Commit**

```bash
git add app/Services/QuoteService.php tests/Feature/ApprovalOrderTest.php
git commit -m "feat(quotes): setApprovalOrder with DRAFT lock + superadmin override"
```

---

### Task 3: Ordering guards in `send`/`sendProofs`/`accept`

**Files:**
- Modify: `app/Services/QuoteService.php` — add predicates + guards
- Test: `tests/Feature/ApprovalOrderTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/ApprovalOrderTest.php`. Helper mirrors `SlimQuoteFlowTest`'s customized line (a line that `needsProof()`):

```php
use App\Models\LineItem;

/** A customized line that needs a proof. */
function proofLine(Quote $quote): LineItem
{
    return LineItem::factory()->create([
        'quote_id' => $quote->id,
        'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/x.png'],
        'line_state' => 'PENDING',
    ]);
}

it('price_first: blocks sending proofs before the price is accepted', function (): void {
    $quote = Quote::factory()->create(['state' => QuoteState::Draft->value]);
    proofLine($quote);
    app(QuoteService::class)->stageProof($quote, $quote->lineItems()->first(), 'artwork/v1.png');

    expect(fn () => app(QuoteService::class)->sendProofs($quote))
        ->toThrow(DomainRuleException::class);

    expect($quote->fresh()->state)->toBe(QuoteState::Draft);
});

it('proof_first: blocks the plain price send when there are proof lines', function (): void {
    $quote = Quote::factory()->proofFirst()->create(['state' => QuoteState::Draft->value]);
    proofLine($quote);

    expect(fn () => app(QuoteService::class)->send($quote))
        ->toThrow(DomainRuleException::class);

    expect($quote->fresh()->state)->toBe(QuoteState::Draft);
});

it('proof_first: blocks price acceptance from SENT with proof lines', function (): void {
    // Force SENT (bypassing the send() guard) to prove accept() also refuses.
    $quote = Quote::factory()->proofFirst()->sent()->create();
    proofLine($quote);

    expect(fn () => app(QuoteService::class)->accept($quote))
        ->toThrow(DomainRuleException::class);

    expect($quote->fresh()->accepted_at)->toBeNull();
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/ApprovalOrderTest.php`
Expected: FAIL — the three new cases do NOT throw (guards absent), so the `->toThrow` assertions fail.

- [ ] **Step 3: Implement predicates + guards**

In `app/Services/QuoteService.php`, add two private predicates next to `hasProofNeedingLines()` (~line 802):

```php
/** proof_first ordering that actually has artwork to approve first. */
private function requiresProofFirst(Quote $quote): bool
{
    return $quote->approval_order === ApprovalOrder::ProofFirst
        && $this->hasProofNeedingLines($quote);
}

/** price_first ordering that actually has artwork (so proofs must wait for the price). */
private function requiresPriceFirst(Quote $quote): bool
{
    return $quote->approval_order === ApprovalOrder::PriceFirst
        && $this->hasProofNeedingLines($quote);
}
```

In `send()` (after the DRAFT guard, ~line 738), add:

```php
if ($this->requiresProofFirst($quote)) {
    throw new DomainRuleException('This order is set to proof-first; send the artwork proof to the buyer before asking for the price.');
}
```

In `accept()` (after the existing state guard that allows SENT/ARTWORK_APPROVED, ~line 770), add:

```php
if ($quote->state === QuoteState::Sent && $this->requiresProofFirst($quote)) {
    throw new DomainRuleException('This order is set to proof-first; approve the artwork proof before agreeing the price.');
}
```

In `sendProofs()` (at the very top of the method, before the drafts lookup, ~line 877), add:

```php
if ($this->requiresPriceFirst($quote) && $quote->accepted_at === null) {
    throw new DomainRuleException('This order is set to price-first; the buyer must agree the price before proofs are sent.');
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test tests/Feature/ApprovalOrderTest.php`
Expected: PASS (8 passed).

- [ ] **Step 5: Run the whole quote suite for regressions**

Run: `php artisan test --filter=Quote`
Then: `php artisan test tests/Feature/SlimQuoteFlowTest.php tests/Feature/PlainStockAcceptanceTest.php tests/Feature/SendProofsTest.php tests/Feature/PerLineProofDecisionTest.php`
Expected: PASS. These exercise the price_first happy path and plain-stock; the guards must not break them (all default `price_first`, and plain-stock has no proof line so guards do not bite).

- [ ] **Step 6: Commit**

```bash
git add app/Services/QuoteService.php tests/Feature/ApprovalOrderTest.php
git commit -m "feat(quotes): enforce approval_order at send/sendProofs/accept"
```

---

### Task 4: Full-flow happy-path + plain-stock no-op tests

**Files:**
- Test: `tests/Feature/ApprovalOrderTest.php`

- [ ] **Step 1: Write the tests**

Append to `tests/Feature/ApprovalOrderTest.php`. These prove both orderings reach `PROOF_APPROVED` invoice-ready, and that plain-stock is a no-op under both flags:

```php
use App\Enums\ProofState;

it('proof_first: Draft -> Proofing -> ArtworkApproved -> accept -> ProofApproved', function (): void {
    $buyer = User::factory()->create();
    $quote = Quote::factory()->proofFirst()->create([
        'company_id' => $buyer->company_id,
        'state' => QuoteState::Draft->value,
        'accepted_at' => null,
    ]);
    $line = proofLine($quote);
    $svc = app(QuoteService::class);

    $svc->stageProof($quote, $line, 'artwork/v1.png');
    $svc->sendProofs($quote);
    expect($quote->fresh()->state)->toBe(QuoteState::Proofing);

    Auth::login($buyer);
    $proof = $quote->fresh()->proofs()->first();
    $svc->approveProof($proof);
    expect($quote->fresh()->state)->toBe(QuoteState::ArtworkApproved);

    $svc->accept($quote->fresh());
    $fresh = $quote->fresh();
    expect($fresh->state)->toBe(QuoteState::ProofApproved)
        ->and($fresh->accepted_at)->not->toBeNull();
});

it('price_first: Draft -> Sent -> accept -> Proofing -> approve -> ProofApproved', function (): void {
    $buyer = User::factory()->create();
    $staff = User::factory()->staffAdmin()->create();
    $quote = Quote::factory()->create([
        'company_id' => $buyer->company_id,
        'state' => QuoteState::Draft->value,
    ]);
    $line = proofLine($quote);
    $svc = app(QuoteService::class);

    Auth::login($staff);
    $svc->send($quote);
    expect($quote->fresh()->state)->toBe(QuoteState::Sent);

    Auth::login($buyer);
    $svc->accept($quote->fresh());
    expect($quote->fresh()->state)->toBe(QuoteState::Accepted);

    Auth::login($staff);
    $svc->stageProof($quote->fresh(), $line, 'artwork/v1.png');
    $svc->sendProofs($quote->fresh());
    expect($quote->fresh()->state)->toBe(QuoteState::Proofing);

    Auth::login($buyer);
    $svc->approveProof($quote->fresh()->proofs()->first());
    expect($quote->fresh()->state)->toBe(QuoteState::ProofApproved);
});

it('plain-stock is a no-op under both orderings', function (string $order): void {
    $buyer = User::factory()->create();
    $staff = User::factory()->staffAdmin()->create();
    // No proofLine() => plain stock. A bare Quote::factory() line is not customized.
    $quote = Quote::factory()->create([
        'company_id' => $buyer->company_id,
        'state' => QuoteState::Draft->value,
        'approval_order' => $order,
    ]);
    LineItem::factory()->create(['quote_id' => $quote->id, 'line_state' => 'PENDING']);
    $svc = app(QuoteService::class);

    Auth::login($staff);
    $svc->send($quote);            // allowed even for proof_first: nothing to proof
    expect($quote->fresh()->state)->toBe(QuoteState::Sent);

    Auth::login($buyer);
    $svc->accept($quote->fresh()); // auto-skips to PROOF_APPROVED
    expect($quote->fresh()->state)->toBe(QuoteState::ProofApproved);
})->with(['price_first', 'proof_first']);
```

> A bare `LineItem::factory()` line must not be customized (so `needsProof()` is false). If the default factory adds customization, pass `'customization' => null` explicitly.

- [ ] **Step 2: Run to verify**

Run: `php artisan test tests/Feature/ApprovalOrderTest.php`
Expected: PASS (all cases incl. both dataset rows).

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/ApprovalOrderTest.php
git commit -m "test(quotes): full-flow both orderings + plain-stock no-op"
```

---

### Task 5: API endpoint + FormRequest + route + resource

**Files:**
- Create: `app/Http/Requests/SetApprovalOrderRequest.php`
- Modify: `app/Http/Controllers/QuoteController.php` (add action near `send()`, ~line 272)
- Modify: `routes/api.php` (near line 145)
- Modify: `app/Http/Resources/QuoteResource.php` (near the `state` key, ~line 37)
- Test: `tests/Feature/ApprovalOrderTest.php`

- [ ] **Step 1: Write the failing endpoint test**

Append to `tests/Feature/ApprovalOrderTest.php`:

```php
use Laravel\Sanctum\Sanctum;

it('PATCH /approval-order updates a DRAFT quote and echoes it in the resource', function (): void {
    Sanctum::actingAs(User::factory()->staffAdmin()->create());
    $quote = Quote::factory()->create(['state' => QuoteState::Draft->value]);

    $this->patchJson("/api/quotes/{$quote->id}/approval-order", ['approval_order' => 'proof_first'])
        ->assertOk()
        ->assertJsonPath('data.approval_order', 'proof_first');

    expect($quote->fresh()->approval_order)->toBe(ApprovalOrder::ProofFirst);
});

it('PATCH /approval-order rejects an invalid value', function (): void {
    Sanctum::actingAs(User::factory()->staffAdmin()->create());
    $quote = Quote::factory()->create(['state' => QuoteState::Draft->value]);

    $this->patchJson("/api/quotes/{$quote->id}/approval-order", ['approval_order' => 'nonsense'])
        ->assertStatus(422);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/ApprovalOrderTest.php`
Expected: FAIL — 404/405 (route absent).

- [ ] **Step 3a: Create the FormRequest**

Create `app/Http/Requests/SetApprovalOrderRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ApprovalOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class SetApprovalOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // controller applies the manageProduction policy
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'approval_order' => ['required', new Enum(ApprovalOrder::class)],
        ];
    }
}
```

- [ ] **Step 3b: Add the controller action**

In `app/Http/Controllers/QuoteController.php`, add `use App\Enums\ApprovalOrder;` and `use App\Http\Requests\SetApprovalOrderRequest;` to the imports, then add this method after `send()` (~line 277):

```php
public function setApprovalOrder(SetApprovalOrderRequest $request, Quote $quote): QuoteResource
{
    $this->authorize('manageProduction', $quote);

    $order = ApprovalOrder::from($request->string('approval_order')->toString());

    return new QuoteResource($this->quotes->setApprovalOrder($quote, $order)->load('lineItems'));
}
```

- [ ] **Step 3c: Add the route**

In `routes/api.php`, next to the `send` route (line 145), add:

```php
Route::patch('/quotes/{quote}/approval-order', [QuoteController::class, 'setApprovalOrder'])->middleware('permission:quotes.edit');
```

- [ ] **Step 3d: Expose it in the resource**

In `app/Http/Resources/QuoteResource.php`, add after the `'state'` key (~line 37):

```php
'approval_order' => $this->approval_order->value,
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test tests/Feature/ApprovalOrderTest.php`
Expected: PASS (all cases).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Requests/SetApprovalOrderRequest.php app/Http/Controllers/QuoteController.php routes/api.php app/Http/Resources/QuoteResource.php tests/Feature/ApprovalOrderTest.php
git commit -m "feat(api): PATCH quotes/{quote}/approval-order + resource field"
```

---

### Task 6: Verify chase keys off the right wait per ordering

**Files:**
- Test: `tests/Feature/ApprovalOrderTest.php` (or a new `tests/Feature/ChaseApprovalOrderTest.php`)

This task is verification: `ChaseUnansweredOrders` keys off **state**, not `approval_order`, so it should compose with both orderings unchanged. Prove it; only touch code if a test fails.

- [ ] **Step 1: Inspect the chase config helper**

Read `app/Services/ReminderSchedule.php` for the ladder day arrays (`DEFAULT_QUOTE_LADDER`, `DEFAULT_PROOF_LADDER`) and how `OrderNotifier::isEnabled()` gates a milestone. Confirm the reminder milestones (`ReminderPrice`, `ReminderProof`) are enabled by default; if a config seed is required, set it in the test with `config()->set(...)` the way `OrderNotificationTest.php` does. Match that file's setup exactly.

- [ ] **Step 2: Write the tests**

Append (adjust the "days waiting" so the first ladder rung is due — read the ladder from Step 1; the price ladder's first rung is typically 2–3 days). Mirror `tests/Feature/OrderNotificationTest.php` for `Mail::fake()`/notifier setup:

```php
use App\Console\Commands\ChaseUnansweredOrders;
use App\Enums\OrderMilestone;
use Illuminate\Support\Facades\Mail;

it('proof_first waiting in PROOFING chases on the proof ladder', function (): void {
    Mail::fake();
    $buyer = User::factory()->create();
    $quote = Quote::factory()->proofFirst()->create([
        'company_id' => $buyer->company_id,
        'state' => QuoteState::Proofing->value,
    ]);
    $line = proofLine($quote);
    $svc = app(QuoteService::class);
    $svc->stageProof($quote, $line, 'artwork/v1.png');
    // Force the proof SENT + backdate so a rung is due.
    $quote->proofs()->update(['state' => ProofState::Sent->value, 'created_at' => now()->subDays(30)]);

    $this->artisan('quotes:chase')->assertSuccessful();

    expect($quote->fresh()->reminded_phase)->toBe('proof');
});

it('proof_first waiting in ARTWORK_APPROVED chases on the price ladder', function (): void {
    Mail::fake();
    $buyer = User::factory()->create();
    $quote = Quote::factory()->proofFirst()->create([
        'company_id' => $buyer->company_id,
        'state' => QuoteState::ArtworkApproved->value,
        'price_snapshot_at' => now()->subDays(30),
    ]);

    $this->artisan('quotes:chase')->assertSuccessful();

    expect($quote->fresh()->reminded_phase)->toBe('price');
});
```

> If `OrderMilestone::ReminderPrice`/`ReminderProof` are off by default, the chase no-ops (M17) and `reminded_phase` stays null. In that case enable them in the test exactly as `OrderNotificationTest.php` does before asserting. Read that file first.

- [ ] **Step 3: Run to verify**

Run: `php artisan test tests/Feature/ApprovalOrderTest.php`
Expected: PASS. If FAIL because the chaser genuinely needs an `approval_order` branch, STOP and revisit — the spec asserts it does not; a failure here is a spec-level finding to raise, not a silent code change.

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/ApprovalOrderTest.php
git commit -m "test(quotes): chase keys off state, not approval_order, both orderings"
```

---

### Task 7: Staff DRAFT toggle + buyer next-step copy (frontend)

**Files:**
- Modify: `frontend/src/stores/quoteStore.ts`
- Modify: `frontend/src/pages/QuoteDetailPage.tsx`
- Test: `frontend/src/pages/QuoteDetailPage.test.tsx`

- [ ] **Step 1: Read the current page + store**

Read `frontend/src/pages/QuoteDetailPage.tsx`, `frontend/src/pages/QuoteDetailPage.test.tsx`, and `frontend/src/stores/quoteStore.ts`. Identify: the staff DRAFT action area (where "Send to buyer" lives), the buyer "Next step" card, and how existing actions (`send`, `accept`) call the API through the store. Match those exact patterns — do not invent a new data-fetching style.

- [ ] **Step 2: Write the failing test**

Add to `frontend/src/pages/QuoteDetailPage.test.tsx` (adapt to the file's existing render helper + mock setup — reuse them, do not duplicate a new harness):

```tsx
it('shows the approval-order toggle on a DRAFT quote and PATCHes on change', async () => {
  // render a DRAFT quote as staff using the file's existing helper
  // click the "Proof first" segment
  // assert a PATCH to /api/quotes/:id/approval-order with { approval_order: 'proof_first' }
});

it('disables the approval-order toggle once the quote has left DRAFT', async () => {
  // render a SENT quote as staff
  // assert the segmented control is disabled
});

it('shows proof-first next-step copy: approve the artwork proof', async () => {
  // render a proof_first quote in PROOFING as the buyer
  // assert the next-step card reads "Review and approve your artwork proof."
});
```

Fill each test body using the file's existing render/mock utilities (e.g. the axios/msw mock and the `renderQuoteDetail`-style helper already present). The three assertions are the contract; the mechanics follow the file.

- [ ] **Step 3: Run to verify it fails**

Run: `cd frontend && npx vitest run src/pages/QuoteDetailPage.test.tsx`
Expected: FAIL — toggle/copy absent.

- [ ] **Step 4: Implement**

- In `quoteStore.ts`: add `setApprovalOrder(quoteId, order)` that `PATCH`es `/quotes/{id}/approval-order` with `{ approval_order: order }` and stores the returned quote, following the existing `send`/`accept` action shape.
- In `QuoteDetailPage.tsx`:
  - Staff + `state === 'DRAFT'` (superadmin: any state): render a segmented control "Approval order: Price first / Proof first" bound to `quote.approval_order`, calling the store action on change; `disabled` when `state !== 'DRAFT'` and the user is not superadmin.
  - "Send to buyer" branches: `price_first` → existing `/send`; `proof_first` with proof-needing lines → the proof-send flow; plain-stock proof_first → `/send`.
  - Buyer "Next step" copy by (`approval_order`, `state`):
    - price_first @ `SENT` → "Review and accept your quote."
    - proof_first @ `PROOFING` → "Review and approve your artwork proof."
    - proof_first @ `ARTWORK_APPROVED` → "Artwork approved — now review and accept your quote."
  - A-1: in proof_first, keep the price visible but read-only before proof approval (do not hide the price block).

- [ ] **Step 5: Run to verify it passes**

Run: `cd frontend && npx vitest run src/pages/QuoteDetailPage.test.tsx`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/stores/quoteStore.ts frontend/src/pages/QuoteDetailPage.tsx frontend/src/pages/QuoteDetailPage.test.tsx
git commit -m "feat(ui): approval-order toggle + per-ordering next-step copy"
```

---

### Task 8: Full-suite regression + live verify

**Files:** none (verification only)

- [ ] **Step 1: Backend full suite**

Run: `php artisan test`
Expected: green. Investigate any red before proceeding.

- [ ] **Step 2: Frontend suite**

Run: `cd frontend && npx vitest run`
Expected: green.

- [ ] **Step 3: Live smoke (per user instruction)**

`preview_start` the api + frontend configs. Open `http://localhost:5173` (NOT 127.0.0.1 — the Sanctum cookie is host-bound). Env is test-configured (`QUEUE=sync`). Do NOT delete seeded/walkthrough data.

Smoke both orderings on a fresh DRAFT order:
- price_first (default): Send → buyer accept → staff proof → buyer approve → Proof-approved.
- proof_first: flip the toggle on DRAFT → Send (proof) → buyer approve → buyer accept → Proof-approved.
- Confirm the DRAFT toggle disables after send, and the buyer next-step copy matches the ordering.

- [ ] **Step 4: No commit** (verification only). Report results.

---

## Self-review

**Spec coverage:**
- Data (enum + column + cast + factory) → Task 1. ✔
- setApprovalOrder A-2 lock/override → Task 2. ✔
- Enforcement guards (send/sendProofs/accept), plain-stock no-op → Tasks 3, 4. ✔
- API endpoint + FormRequest + route + resource → Task 5. ✔
- Chase verification per ordering → Task 6. ✔
- Staff toggle + buyer A-1 read-only price + next-step copy → Task 7. ✔
- A-3 global default → Task 1 migration default + factory. ✔
- Regression + live verify → Task 8. ✔

**Placeholder scan:** Task 7 test bodies are described-not-coded by design — the frontend render/mock harness is file-specific and must be read first (Step 1); the three assertions are the fixed contract. Every backend step has complete code. Acceptable: the how is pinned to "reuse the existing helper", not left open.

**Type consistency:** `ApprovalOrder::{PriceFirst,ProofFirst}` = `'price_first'`/`'proof_first'` used consistently. `setApprovalOrder(Quote, ApprovalOrder): Quote`, predicates `requiresProofFirst`/`requiresPriceFirst`, audit event `quote.approval_order_changed`, resource key `approval_order`, route `PATCH /quotes/{quote}/approval-order` — all consistent across tasks.
