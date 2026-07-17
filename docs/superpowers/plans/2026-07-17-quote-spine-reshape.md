# Quote-Spine Reshape — Implementation Plan (Workstream A)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restrict quote cancellation to staff, let staff send a proof with the quote so the buyer approves price+artwork in one action, rename `PO_ISSUED`→`INVOICED` coherently, and email the buyer whenever a quote/proof is ready to review.

**Architecture:** Layered onto the existing quote state machine. The rename lands first on a green tree. Cancel tightens the controller gate. The slim path adds one state edge (`DRAFT→PROOFING`) and a new nullable `accepted_at` that both records acceptance and discriminates the slim-vs-existing rejection behavior. The email is new Laravel Mail infrastructure (queued Mailable + Blade) fired from `QuoteService`.

**Tech Stack:** Laravel 11 / PHP 8.3, Pest. React 18 + TypeScript, Vite, Zustand, Tailwind, Vitest. Reverb (existing). Queue worker (existing, Supervisor).

**Spec:** [`docs/superpowers/specs/2026-07-17-quote-spine-reshape-design.md`](../specs/2026-07-17-quote-spine-reshape-design.md)

---

## ⚠️ Parallel-worktree coordination (read first)

Workstream B (delivery/courier) edits several of the same files: `QuoteDetailPage.tsx`, `QuoteService.php`, the quotes migrations set, `quoteStore.ts`, and `QuoteResource`. **Land Workstream A first, then rebase B onto it.** If both run truly in parallel, expect conflicts in exactly those files and resolve at merge. Do not both edit `app/Enums/QuoteState.php` — A owns the state-machine changes.

## Orientation — facts the engineer needs

- **State machine is the single source of truth.** `QuoteState::nextStates()` (`app/Enums/QuoteState.php:31-46`) lists legal edges; `Quote::transitionTo()` throws `InvalidStateTransitionException` on an illegal move. Add edges there, never bypass.
- **All quote mutations go through `QuoteService`** (`app/Services/QuoteService.php`) and broadcast via `Broadcasting::dispatch(fn () => QuoteStateChanged::dispatch(...))`. Follow that pattern for any new transition.
- **`decimal` casts return strings** from Eloquent (`weight`, `total`, `amount` → `'4250.00'`).
- **Tests run from repo root:** `vendor/bin/pest` (backend), `cd frontend && npm run test` / `npx vitest run <file>` (frontend). Full frontend `vitest run` STALLS on this Windows box — run targeted files.
- **Auth in tests:** `Sanctum::actingAs($user)`. Factories: `User::factory()->staffAdmin()`, `->superadmin()`; default `User::factory()` is a buyer. `Quote::factory()` exists; check `database/factories/QuoteFactory.php` for states/relations.
- **No notification infra exists** — you are building the first Mailable. Mail driver is `log` by default (`config/mail.php`).

## File Structure

**Feature 3 — rename (do first)**

| File | Change |
|---|---|
| `app/Enums/QuoteState.php` | `PoIssued`/`'PO_ISSUED'` → `Invoiced`/`'INVOICED'` |
| `database/migrations/<new>_rename_po_issued_state_and_table.php` | Alter `quotes.state` enum + backfill; rename `purchase_orders`→`invoices` |
| `app/Models/PurchaseOrder.php` → `app/Models/Invoice.php` | Rename model + `$table` |
| `app/Http/Requests/IssuePurchaseOrderRequest.php` → `IssueInvoiceRequest.php` | Rename + unique rules target `invoices` |
| `app/Http/Controllers/QuoteController.php:97-117` | `issuePurchaseOrder`→`issueInvoice`, return key `invoice` |
| `app/Services/QuoteService.php:330-356` | `issuePurchaseOrder`→`issueInvoice`, `PurchaseOrder`→`Invoice`, `PoIssued`→`Invoiced` |
| `routes/api.php:106` | `/purchase-order`→`/invoice` |
| `frontend/src/stores/quoteStore.ts` | `issuePurchaseOrder`→`issueInvoice`, path |
| `frontend/src/pages/QuoteDetailPage.tsx` | "Issue PO" → "Issue invoice", `PO_ISSUED` label |
| `frontend/src/types.ts` | `PublishState`/quote-state union `PO_ISSUED`→`INVOICED` |
| `docs/API.md` | PO → invoice references |
| `tests/**` | any `PO_ISSUED`/`PurchaseOrder`/`purchase-order` references |

**Feature 1 — staff-only cancel**

| File | Change |
|---|---|
| `app/Http/Controllers/QuoteController.php:126-131` | authorize `manageProduction` instead of `update` |
| `app/Http/Requests/CancelQuoteRequest.php` | **Create** — validation only |
| `routes/api.php:108` | point cancel at the FormRequest signature (controller change only) |
| `frontend/src/stores/quoteStore.ts` | add `cancelQuote(id, reason)` |
| `frontend/src/pages/QuoteDetailPage.tsx` | staff-only Cancel control + confirm modal |
| `tests/Feature/QuoteCancelTest.php` | **Create** |

**Feature 2 — slim path + acceptance stamp**

| File | Change |
|---|---|
| `database/migrations/<new>_add_acceptance_to_quotes.php` | **Create** — `accepted_at`, `accepted_by` |
| `app/Models/Quote.php` | fillable/casts for the two columns |
| `app/Enums/QuoteState.php` | add `Proofing` to `Draft`'s edges |
| `app/Services/QuoteService.php` | `send()` accepts optional proof; `accept()` stamps; `approveProof()` stamps if null; `requestProofChanges()` branches on `accepted_at` |
| `app/Http/Controllers/QuoteController.php:83-88` | `send` reads optional proof payload |
| `app/Http/Requests/SendQuoteRequest.php` | **Create** — optional proof fields + `manageProduction` context |
| `frontend/src/stores/quoteStore.ts` | `send` passes optional proof; buyer approve/request-changes already exist |
| `frontend/src/pages/QuoteDetailPage.tsx` | staff send-with-proof; buyer PROOFING approve/request-changes |
| `tests/Feature/SlimQuoteFlowTest.php` | **Create** |

**Feature 4 — buyer email**

| File | Change |
|---|---|
| `config/mail.php`, `.env.example` | Gmail SMTP mailer + env keys |
| `app/Mail/QuoteReadyMail.php` | **Create** — queued Mailable, variants |
| `resources/views/mail/quote-ready.blade.php` | **Create** — premium responsive template |
| `app/Http/Controllers/ProofImageController.php` | **Create** — signed thumbnail route |
| `routes/api.php` | signed `GET /proofs/{proof}/image` (public, signed) |
| `app/Services/QuoteService.php` | dispatch `QuoteReadyMail` at send / issueProof |
| `tests/Feature/QuoteReadyMailTest.php`, `ProofImageTest.php` | **Create** |

---

## Task 1: Rename the enum case + state machine

**Files:**
- Modify: `app/Enums/QuoteState.php`
- Test: `tests/Unit/StateMachineTest.php`

- [ ] **Step 1: Update the failing test first**

In `tests/Unit/StateMachineTest.php`, find every `QuoteState::PoIssued` / `'PO_ISSUED'` and replace with `QuoteState::Invoiced` / `'INVOICED'`. Run to see it fail (enum case gone).

```bash
vendor/bin/pest tests/Unit/StateMachineTest.php
```
Expected: FAIL — `Undefined constant App\Enums\QuoteState::Invoiced`.

- [ ] **Step 2: Rename the enum case**

In `app/Enums/QuoteState.php`:
- Line 19: `case PoIssued = 'PO_ISSUED';` → `case Invoiced = 'INVOICED';`
- In `nextStates()`: `self::ProofApproved => [self::PoIssued, self::Cancelled],` → `self::ProofApproved => [self::Invoiced, self::Cancelled],` and `self::PoIssued => [self::Confirmed, self::Cancelled],` → `self::Invoiced => [self::Confirmed, self::Cancelled],`

- [ ] **Step 3: Run the unit test**

```bash
vendor/bin/pest tests/Unit/StateMachineTest.php
```
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add app/Enums/QuoteState.php tests/Unit/StateMachineTest.php
git commit -m "refactor(quote): rename QuoteState PoIssued -> Invoiced"
```

---

## Task 2: Rename the model, table, and migration

**Files:**
- Create: `database/migrations/2026_07_17_000001_rename_purchase_orders_to_invoices.php`
- Rename: `app/Models/PurchaseOrder.php` → `app/Models/Invoice.php`

- [ ] **Step 1: Write the rename migration**

Create `database/migrations/2026_07_17_000001_rename_purchase_orders_to_invoices.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Alter the quotes.state enum to include INVOICED, then migrate any rows.
        // MySQL enum change is a raw statement; keep the full list in sync with
        // QuoteState. (No row normally rests at PO_ISSUED — it's transient — but
        // migrate defensively.)
        DB::statement("ALTER TABLE quotes MODIFY COLUMN state ENUM(
            'DRAFT','SENT','CHANGES_REQUESTED','ACCEPTED','PROOFING','PROOF_APPROVED',
            'PO_ISSUED','INVOICED','CONFIRMED','PROCURING','READY','CLOSED','CANCELLED'
        ) NOT NULL DEFAULT 'DRAFT'");
        DB::table('quotes')->where('state', 'PO_ISSUED')->update(['state' => 'INVOICED']);
        DB::statement("ALTER TABLE quotes MODIFY COLUMN state ENUM(
            'DRAFT','SENT','CHANGES_REQUESTED','ACCEPTED','PROOFING','PROOF_APPROVED',
            'INVOICED','CONFIRMED','PROCURING','READY','CLOSED','CANCELLED'
        ) NOT NULL DEFAULT 'DRAFT'");

        Schema::rename('purchase_orders', 'invoices');
    }

    public function down(): void
    {
        Schema::rename('invoices', 'purchase_orders');
        DB::statement("ALTER TABLE quotes MODIFY COLUMN state ENUM(
            'DRAFT','SENT','CHANGES_REQUESTED','ACCEPTED','PROOFING','PROOF_APPROVED',
            'PO_ISSUED','INVOICED','CONFIRMED','PROCURING','READY','CLOSED','CANCELLED'
        ) NOT NULL DEFAULT 'DRAFT'");
        DB::table('quotes')->where('state', 'INVOICED')->update(['state' => 'PO_ISSUED']);
        DB::statement("ALTER TABLE quotes MODIFY COLUMN state ENUM(
            'DRAFT','SENT','CHANGES_REQUESTED','ACCEPTED','PROOFING','PROOF_APPROVED',
            'PO_ISSUED','CONFIRMED','PROCURING','READY','CLOSED','CANCELLED'
        ) NOT NULL DEFAULT 'DRAFT'");
    }
};
```

Note: the test DB may be SQLite (check `phpunit.xml`). On SQLite, `ENUM` is stored as text and `MODIFY COLUMN` is unsupported — if `phpunit.xml` uses SQLite, guard the enum `ALTER`s with `if (DB::getDriverName() === 'mysql')` and rely on the string column on SQLite. Verify the driver before running and adapt.

- [ ] **Step 2: Rename the model**

`git mv app/Models/PurchaseOrder.php app/Models/Invoice.php`. In the new file: `class PurchaseOrder` → `class Invoice`, and add `protected $table = 'invoices';` if the class name no longer maps (Laravel would infer `invoices` from `Invoice` — so the explicit `$table` is optional; set it only if you keep any alias). Update the docblock.

- [ ] **Step 3: Update all references to the model**

```bash
grep -rln "PurchaseOrder" app/ tests/ | grep -v vendor
```
Update each `use App\Models\PurchaseOrder;` → `use App\Models\Invoice;` and `PurchaseOrder::` → `Invoice::`. (Primary sites: `QuoteService.php`, `QuoteController.php`, `Quote.php` relation, any factory/seeder.)

- [ ] **Step 4: Run migration + full suite**

```bash
php artisan migrate:fresh --seed && vendor/bin/pest
```
Expected: migration runs; suite is GREEN except any remaining `PurchaseOrder`/`PO_ISSUED` string references you'll fix in Task 3.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "refactor(quote): rename purchase_orders table + PurchaseOrder model to invoices/Invoice"
```

---

## Task 3: Rename the endpoint, request, and service method

**Files:**
- Rename: `app/Http/Requests/IssuePurchaseOrderRequest.php` → `IssueInvoiceRequest.php`
- Modify: `QuoteService.php`, `QuoteController.php`, `routes/api.php`
- Test: `tests/Feature/QuoteFlowTest.php` (and any PO-referencing test)

- [ ] **Step 1: Update the spine test to the new names**

In `tests/Feature/QuoteFlowTest.php` (and grep others), change `POST .../purchase-order` → `.../invoice`, the request payload key stays `po_ref` (column unchanged), and assertions on the JSON key `purchase_order` → `invoice`. Run to fail.

```bash
grep -rln "purchase-order\|purchase_order\|PO_ISSUED\|IssuePurchaseOrder\|issuePurchaseOrder" app/ routes/ tests/ | grep -v vendor
```

- [ ] **Step 2: Rename the FormRequest**

`git mv app/Http/Requests/IssuePurchaseOrderRequest.php app/Http/Requests/IssueInvoiceRequest.php`. Rename the class to `IssueInvoiceRequest`. Update the `unique:` rules to target the renamed table:

```php
'po_ref' => ['required', 'string', 'max:64', 'unique:invoices,po_ref'],
'invoice_ref' => ['nullable', 'string', 'max:64', 'unique:invoices,invoice_ref'],
'terms' => ['nullable', 'string', 'max:255'],
```

- [ ] **Step 3: Rename service + controller + route**

In `app/Services/QuoteService.php` (method at `:330-356`): rename `issuePurchaseOrder` → `issueInvoice`, `PurchaseOrder::create` → `Invoice::create`, `QuoteState::PoIssued` → `QuoteState::Invoiced`, audit event `'purchase_order.issued'` → `'invoice.issued'`, return type `PurchaseOrder` → `Invoice`.

In `app/Http/Controllers/QuoteController.php` (`:97-117`): rename method `issuePurchaseOrder` → `issueInvoice`, type-hint `IssueInvoiceRequest`, JSON response key `'purchase_order'` → `'invoice'`, and `import` update.

In `routes/api.php:106`: `Route::post('/quotes/{quote}/invoice', [QuoteController::class, 'issueInvoice']);`

- [ ] **Step 4: Update the frontend + docs**

- `frontend/src/stores/quoteStore.ts`: `issuePurchaseOrder` action → `issueInvoice`, path `/quotes/${id}/purchase-order` → `/invoice`.
- `frontend/src/pages/QuoteDetailPage.tsx`: button/label "Issue PO" → "Issue invoice"; any `PO_ISSUED` state label → "Invoiced".
- `frontend/src/types.ts`: quote-state union `'PO_ISSUED'` → `'INVOICED'`.
- `docs/API.md`: the PO_ISSUED / purchase-order lines → invoice.

- [ ] **Step 5: Run everything + grep clean**

```bash
vendor/bin/pest && cd frontend && npx tsc --noEmit && cd ..
grep -rn "PO_ISSUED\|PurchaseOrder\|purchase-order\|issuePurchaseOrder" app/ routes/ frontend/src/ tests/ docs/API.md | grep -v vendor
```
Expected: Pest green, tsc clean, grep returns **nothing**.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "refactor(quote): rename issue-PO endpoint/request/service to invoice

Completes the PO_ISSUED -> INVOICED rename across API, service, frontend, docs."
```

---

## Task 4: Staff-only cancel — server

**Files:**
- Create: `app/Http/Requests/CancelQuoteRequest.php`
- Modify: `app/Http/Controllers/QuoteController.php:126-131`, `routes/api.php:108`
- Test: `tests/Feature/QuoteCancelTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/QuoteCancelTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Quote;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('lets staff cancel a quote', function (): void {
    $quote = Quote::factory()->create(['state' => 'SENT']);
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->postJson("/api/quotes/{$quote->id}/cancel", ['reason' => 'duplicate'])
        ->assertOk()
        ->assertJsonPath('data.state', 'CANCELLED');
});

it('lets a superadmin cancel a quote', function (): void {
    $quote = Quote::factory()->create(['state' => 'SENT']);
    Sanctum::actingAs(User::factory()->superadmin()->create());

    $this->postJson("/api/quotes/{$quote->id}/cancel")->assertOk();
});

it('forbids a buyer from cancelling their own company quote', function (): void {
    $buyer = User::factory()->create(); // buyer
    $quote = Quote::factory()->create(['company_id' => $buyer->company_id, 'state' => 'SENT']);
    Sanctum::actingAs($buyer);

    $this->postJson("/api/quotes/{$quote->id}/cancel")->assertStatus(403);
    expect($quote->refresh()->state->value)->toBe('SENT');
});

it('refuses to cancel a READY quote', function (): void {
    $quote = Quote::factory()->create(['state' => 'READY']);
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    // transitionTo throws -> 422/500 depending on handler; assert it did NOT cancel.
    $this->postJson("/api/quotes/{$quote->id}/cancel");
    expect($quote->refresh()->state->value)->toBe('READY');
});

it('validates the reason length', function (): void {
    $quote = Quote::factory()->create(['state' => 'SENT']);
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->postJson("/api/quotes/{$quote->id}/cancel", ['reason' => str_repeat('x', 501)])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);
});
```

Check `QuoteFactory` supports `['company_id' => ...]` and a buyer's `company_id` is non-null (the default factory user belongs to a company — verify in `UserFactory`; if buyers have null company, create a company + buyer explicitly).

- [ ] **Step 2: Run to fail**

```bash
vendor/bin/pest tests/Feature/QuoteCancelTest.php
```
Expected: the buyer test FAILS (currently 200 — buyer can cancel), reason-validation FAILS (no validation).

- [ ] **Step 3: Create the FormRequest**

Create `app/Http/Requests/CancelQuoteRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation only. Authorization is the controller's manageProduction gate
 * (staff/superadmin) — we don't re-check the role here to keep one source of
 * truth for who may cancel.
 */
class CancelQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
```

- [ ] **Step 4: Tighten the controller**

In `app/Http/Controllers/QuoteController.php`, change `cancel` (`:126-131`):

```php
public function cancel(CancelQuoteRequest $request, Quote $quote): QuoteResource
{
    $this->authorize('manageProduction', $quote);

    return new QuoteResource($this->quotes->cancel($quote, $request->input('reason')));
}
```

Add `use App\Http\Requests\CancelQuoteRequest;`.

- [ ] **Step 5: Run the tests**

```bash
vendor/bin/pest tests/Feature/QuoteCancelTest.php
```
Expected: PASS (buyer now 403, reason validated).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Requests/CancelQuoteRequest.php app/Http/Controllers/QuoteController.php tests/Feature/QuoteCancelTest.php
git commit -m "feat(quote): restrict cancel to staff (manageProduction gate) + validate reason"
```

---

## Task 5: Staff-only cancel — frontend

**Files:**
- Modify: `frontend/src/stores/quoteStore.ts`, `frontend/src/pages/QuoteDetailPage.tsx`

- [ ] **Step 1: Add the store action**

In `quoteStore.ts`, alongside the other actions, add (match the file's existing action shape — `ensureCsrf()`, `apiError`, silent refetch of the quote):

```ts
cancelQuote: async (id: number, reason?: string) => {
  set({ error: null });
  try {
    await ensureCsrf();
    await api.post(`/quotes/${id}/cancel`, { reason });
    await get().fetchQuote(id); // refresh the detail view
    return true;
  } catch (err) {
    set({ error: apiError(err) });
    return false;
  }
},
```

Declare it on the store interface. (Confirm the store's quote-refresh method name — it may be `fetchQuote`/`loadQuote`; match it.)

- [ ] **Step 2: Add the staff-only Cancel control**

In `QuoteDetailPage.tsx`, near the existing staff-only "Issue invoice" block (renamed in Task 3), add a Cancel button rendered only when `isStaff` **and** the quote state is cancellable (anything except `READY`, `CLOSED`, `CANCELLED`). Clicking opens a `ui/Modal` confirm with an optional reason `Input`, whose confirm calls `cancelQuote(quote.id, reason)` then closes. Follow the page's existing modal + toast usage.

- [ ] **Step 3: Typecheck + targeted test**

```bash
cd frontend && npx tsc --noEmit && npx vitest run src/pages/QuoteDetailPage.test.tsx
```
Expected: clean. If `QuoteDetailPage.test.tsx` asserts the action set, extend it: staff sees Cancel, buyer does not.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/stores/quoteStore.ts frontend/src/pages/QuoteDetailPage.tsx frontend/src/pages/QuoteDetailPage.test.tsx
git commit -m "feat(quote): staff-only cancel control with reason confirm modal"
```

---

## Task 6: Acceptance stamping (schema + accept/approve)

**Files:**
- Create: `database/migrations/2026_07_17_000002_add_acceptance_to_quotes.php`
- Modify: `app/Models/Quote.php`, `app/Services/QuoteService.php`
- Test: `tests/Feature/SlimQuoteFlowTest.php`

- [ ] **Step 1: Write the failing test (stamping)**

Create `tests/Feature/SlimQuoteFlowTest.php` with the acceptance-stamp case first:

```php
<?php

declare(strict_types=1);

use App\Models\Quote;
use App\Models\User;
use App\Services\QuoteService;

it('stamps accepted_at and accepted_by when a buyer accepts', function (): void {
    $buyer = User::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $buyer->company_id, 'state' => 'SENT']);

    $this->actingAs($buyer);
    app(QuoteService::class)->accept($quote);

    $quote->refresh();
    expect($quote->accepted_at)->not->toBeNull()
        ->and($quote->accepted_by)->toBe($buyer->id);
});
```

- [ ] **Step 2: Run to fail**

```bash
vendor/bin/pest tests/Feature/SlimQuoteFlowTest.php
```
Expected: FAIL — `accepted_at` column missing.

- [ ] **Step 3: Migration + model**

Create `database/migrations/2026_07_17_000002_add_acceptance_to_quotes.php`:

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
        Schema::table('quotes', function (Blueprint $table): void {
            // Records that the buyer agreed to the price (and who/when), even when
            // the slim path skips the ACCEPTED dwell. Also discriminates the slim
            // vs existing rejection behavior in requestProofChanges.
            $table->timestamp('accepted_at')->nullable()->after('price_snapshot_at');
            $table->foreignId('accepted_by')->nullable()->after('accepted_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('accepted_by');
            $table->dropColumn('accepted_at');
        });
    }
};
```

In `app/Models/Quote.php`, add `accepted_at`, `accepted_by` to `$fillable` and `'accepted_at' => 'datetime'` to `$casts`.

- [ ] **Step 4: Stamp in `accept()`**

In `app/Services/QuoteService.php::accept` (`:244-251`):

```php
public function accept(Quote $quote): Quote
{
    $previous = $quote->state->value;
    $quote->accepted_at = now();
    $quote->accepted_by = Auth::id();
    $quote->save();
    $quote->transitionTo(QuoteState::Accepted);
    Broadcasting::dispatch(fn () => QuoteStateChanged::dispatch($quote, $previous));

    return $quote;
}
```

`Auth` is already imported (used in `approveProof`).

- [ ] **Step 5: Run + commit**

```bash
vendor/bin/pest tests/Feature/SlimQuoteFlowTest.php
```
Expected: PASS.

```bash
git add database/migrations/2026_07_17_000002_add_acceptance_to_quotes.php app/Models/Quote.php app/Services/QuoteService.php tests/Feature/SlimQuoteFlowTest.php
git commit -m "feat(quote): record accepted_at/accepted_by on buyer accept"
```

---

## Task 7: Slim path — DRAFT→PROOFING edge + send-with-proof

**Files:**
- Modify: `app/Enums/QuoteState.php`, `app/Services/QuoteService.php`, `app/Http/Controllers/QuoteController.php`
- Create: `app/Http/Requests/SendQuoteRequest.php`
- Test: `tests/Feature/SlimQuoteFlowTest.php` (append)

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/SlimQuoteFlowTest.php`:

```php
it('sends a quote with a proof and lands in PROOFING', function (): void {
    $quote = Quote::factory()->create(['state' => 'DRAFT']);
    \Illuminate\Support\Facades\Mail::fake();
    Laravel\Sanctum\Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->postJson("/api/quotes/{$quote->id}/send", [
        'artwork_version_ref' => 'artwork/v1-key.png',
    ])->assertOk()->assertJsonPath('data.state', 'PROOFING');

    $quote->refresh();
    expect($quote->state->value)->toBe('PROOFING')
        ->and($quote->proofs()->count())->toBe(1)
        ->and($quote->proofs()->first()->version)->toBe(1);
});

it('sends a quote without a proof and lands in SENT', function (): void {
    $quote = Quote::factory()->create(['state' => 'DRAFT']);
    \Illuminate\Support\Facades\Mail::fake();
    Laravel\Sanctum\Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->postJson("/api/quotes/{$quote->id}/send")
        ->assertOk()->assertJsonPath('data.state', 'SENT');
});
```

- [ ] **Step 2: Run to fail**

```bash
vendor/bin/pest tests/Feature/SlimQuoteFlowTest.php --filter="sends a quote"
```
Expected: FAIL — send ignores the proof, no `DRAFT→PROOFING`.

- [ ] **Step 3: Add the state edge**

In `app/Enums/QuoteState.php::nextStates()`, line 33:

```php
self::Draft => [self::Sent, self::Proofing, self::Cancelled],
```

- [ ] **Step 4: Extract a proof-creation helper + branch `send()`**

In `QuoteService.php`, add a private helper that both `issueProof` and `send` use to create a version row (DRY — pull the create + broadcast out of `issueProof`):

```php
private function createProofVersion(Quote $quote, string $artworkRef, ?string $notes): Proof
{
    $nextVersion = ((int) $quote->proofs()->max('version')) + 1;
    $proof = Proof::create([
        'quote_id' => $quote->id,
        'version' => $nextVersion,
        'artwork_version_ref' => $artworkRef,
        'state' => ProofState::Sent->value,
        'notes' => $notes,
    ]);
    DB::afterCommit(fn () => Broadcasting::dispatch(fn () => ProofStatusChanged::dispatch($proof, $quote->company_id)));

    return $proof;
}
```

Refactor `issueProof` to call `$this->createProofVersion(...)` for the create block (behavior unchanged). Then change `send()`:

```php
public function send(Quote $quote, ?string $artworkRef = null, ?string $proofNotes = null): Quote
{
    return DB::transaction(function () use ($quote, $artworkRef, $proofNotes): Quote {
        $previous = $quote->state->value;
        $quote->price_snapshot_at = now();
        $quote->save();

        if ($artworkRef !== null) {
            $quote->transitionTo(QuoteState::Proofing);          // slim path
            $this->createProofVersion($quote, $artworkRef, $proofNotes);
        } else {
            $quote->transitionTo(QuoteState::Sent);
        }

        DB::afterCommit(fn () => Broadcasting::dispatch(fn () => QuoteStateChanged::dispatch($quote, $previous)));

        return $quote;
    });
}
```

- [ ] **Step 5: SendQuoteRequest + controller**

Create `app/Http/Requests/SendQuoteRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Optional proof-with-quote payload. Authorization is the controller's
 * manageProduction gate. When artwork_version_ref is present the quote takes
 * the slim path (DRAFT -> PROOFING) with a v1 proof attached.
 */
class SendQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'artwork_version_ref' => ['nullable', 'string', 'max:2048'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
```

In `QuoteController.php::send` (`:83-88`):

```php
public function send(SendQuoteRequest $request, Quote $quote): QuoteResource
{
    $this->authorize('manageProduction', $quote);

    return new QuoteResource($this->quotes->send(
        $quote,
        $request->input('artwork_version_ref'),
        $request->input('notes'),
    ));
}
```

Add `use App\Http\Requests\SendQuoteRequest;`.

- [ ] **Step 6: Run + commit**

```bash
vendor/bin/pest tests/Feature/SlimQuoteFlowTest.php && vendor/bin/pest tests/Feature/QuoteFlowTest.php
```
Expected: PASS (new slim tests + existing spine untouched).

```bash
git add app/Enums/QuoteState.php app/Services/QuoteService.php app/Http/Controllers/QuoteController.php app/Http/Requests/SendQuoteRequest.php tests/Feature/SlimQuoteFlowTest.php
git commit -m "feat(quote): optional send-with-proof slim path (DRAFT->PROOFING)"
```

---

## Task 8: Slim-path approve stamps acceptance + reject branches

**Files:**
- Modify: `app/Services/QuoteService.php` (`approveProof`, `requestProofChanges`)
- Test: `tests/Feature/SlimQuoteFlowTest.php` (append)

- [ ] **Step 1: Write the failing tests**

Append:

```php
it('stamps acceptance when a buyer approves a slim-path proof', function (): void {
    $buyer = User::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $buyer->company_id, 'state' => 'DRAFT', 'accepted_at' => null]);
    Laravel\Sanctum\Sanctum::actingAs(User::factory()->staffAdmin()->create());
    $this->postJson("/api/quotes/{$quote->id}/send", ['artwork_version_ref' => 'a/v1.png']);

    $proof = $quote->fresh()->proofs()->first();
    Laravel\Sanctum\Sanctum::actingAs($buyer);
    $this->postJson("/api/proofs/{$proof->id}/decide", ['decision' => 'approve'])->assertOk();

    $quote->refresh();
    expect($quote->state->value)->toBe('PROOF_APPROVED')
        ->and($quote->accepted_at)->not->toBeNull();
});

it('routes a slim-path request-changes to CHANGES_REQUESTED', function (): void {
    $buyer = User::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $buyer->company_id, 'state' => 'DRAFT', 'accepted_at' => null]);
    Laravel\Sanctum\Sanctum::actingAs(User::factory()->staffAdmin()->create());
    $this->postJson("/api/quotes/{$quote->id}/send", ['artwork_version_ref' => 'a/v1.png']);
    $proof = $quote->fresh()->proofs()->first();

    Laravel\Sanctum\Sanctum::actingAs($buyer);
    $this->postJson("/api/proofs/{$proof->id}/decide", ['decision' => 'request_changes', 'notes' => 'too pricey'])->assertOk();

    expect($quote->refresh()->state->value)->toBe('CHANGES_REQUESTED');
});

it('keeps an accepted quote in PROOFING on request-changes (existing behavior)', function (): void {
    $buyer = User::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $buyer->company_id, 'state' => 'ACCEPTED', 'accepted_at' => now(), 'accepted_by' => $buyer->id]);
    Laravel\Sanctum\Sanctum::actingAs(User::factory()->staffAdmin()->create());
    $this->postJson("/api/quotes/{$quote->id}/proofs", ['artwork_version_ref' => 'a/v1.png']);
    $proof = $quote->fresh()->proofs()->first();

    Laravel\Sanctum\Sanctum::actingAs($buyer);
    $this->postJson("/api/proofs/{$proof->id}/decide", ['decision' => 'request_changes', 'notes' => 'fix logo'])->assertOk();

    expect($quote->refresh()->state->value)->toBe('PROOFING');
});
```

- [ ] **Step 2: Run to fail**

```bash
vendor/bin/pest tests/Feature/SlimQuoteFlowTest.php --filter="slim-path|existing behavior"
```
Expected: approve-stamp and slim-reject FAIL (no stamping, reject stays PROOFING).

- [ ] **Step 3: Stamp in `approveProof`**

In `QuoteService.php::approveProof` (`:289-311`), after resolving `$quote` and before/with the transition, stamp acceptance if it never happened:

```php
$quote = $proof->quote;
if ($quote->accepted_at === null) {
    $quote->accepted_at = now();
    $quote->accepted_by = Auth::id();
    $quote->save();
}
$previous = $quote->state->value;
$quote->transitionTo(QuoteState::ProofApproved);
```

- [ ] **Step 4: Branch `requestProofChanges`**

Replace `requestProofChanges` (`:317-327`) so a slim-path reject (price never accepted) advances the quote to `CHANGES_REQUESTED`:

```php
public function requestProofChanges(Proof $proof, ?string $notes): Proof
{
    return DB::transaction(function () use ($proof, $notes): Proof {
        if ($notes !== null) {
            $proof->notes = $notes;
        }
        $proof->transitionTo(ProofState::ChangesRequested);

        $quote = $proof->quote;
        // Slim path: price was never separately accepted, so the rejection may be
        // about price or artwork -> send to CHANGES_REQUESTED for staff triage.
        // Existing path (accepted_at set): artwork-only revision -> stay PROOFING.
        if ($quote->accepted_at === null && $quote->state === QuoteState::Proofing) {
            $previous = $quote->state->value;
            $quote->transitionTo(QuoteState::ChangesRequested);
            DB::afterCommit(fn () => Broadcasting::dispatch(fn () => QuoteStateChanged::dispatch($quote, $previous)));
        }

        DB::afterCommit(fn () => Broadcasting::dispatch(fn () => ProofStatusChanged::dispatch($proof, $quote->company_id)));

        return $proof;
    });
}
```

- [ ] **Step 5: Run + commit**

```bash
vendor/bin/pest tests/Feature/SlimQuoteFlowTest.php && vendor/bin/pest tests/Feature/ProofFlowTest.php
```
Expected: PASS (slim behavior + existing proof flow both green).

```bash
git add app/Services/QuoteService.php tests/Feature/SlimQuoteFlowTest.php
git commit -m "feat(quote): slim-path approve stamps acceptance; reject triages to CHANGES_REQUESTED"
```

---

## Task 9: Slim path — frontend

**Files:**
- Modify: `frontend/src/stores/quoteStore.ts`, `frontend/src/pages/QuoteDetailPage.tsx`

- [ ] **Step 1: Extend `send` in the store**

The `send` action currently posts no body. Change it to accept an optional artwork ref + notes and pass them:

```ts
send: async (id: number, proof?: { artwork_version_ref: string; notes?: string }) => {
  set({ error: null });
  try {
    await ensureCsrf();
    await api.post(`/quotes/${id}/send`, proof ?? {});
    await get().fetchQuote(id);
    return true;
  } catch (err) {
    set({ error: apiError(err) });
    return false;
  }
},
```

Update the interface signature. (Confirm existing `send` callers still compile — they call `send(id)` with no proof, which is fine.)

- [ ] **Step 2: Staff send-with-proof UI**

In `QuoteDetailPage.tsx`, in the staff DRAFT view, add an optional "Attach proof" affordance next to Send — reuse the artwork-upload flow the proof-issue UI already uses (find the existing `issueProof` UI and its artwork-ref source). When an artwork ref is set, "Send" calls `send(id, { artwork_version_ref })`; otherwise `send(id)`.

- [ ] **Step 3: Buyer PROOFING view**

Ensure the buyer's PROOFING view shows the price summary **and** the proof together, with **Approve** and **Request changes** (note field) buttons that call the existing `decideProof(proofId, 'approve' | 'request_changes', notes)` store action. (Much of this may already exist for the non-slim PROOFING state — verify it renders for slim-path quotes too, since state is identical.)

- [ ] **Step 4: Typecheck + test + commit**

```bash
cd frontend && npx tsc --noEmit && npx vitest run src/pages/QuoteDetailPage.test.tsx
```

```bash
git add frontend/src/stores/quoteStore.ts frontend/src/pages/QuoteDetailPage.tsx
git commit -m "feat(quote): staff send-with-proof + buyer one-step approve UI"
```

---

## Task 10: Mail config (Gmail SMTP) + signed proof image route

**Files:**
- Modify: `config/mail.php`, `.env.example`
- Create: `app/Http/Controllers/ProofImageController.php`, `routes/api.php` entry
- Test: `tests/Feature/ProofImageTest.php`

- [ ] **Step 1: Wire Gmail SMTP env**

Confirm `config/mail.php` has the standard `smtp` mailer (Laravel default does). In `.env.example`, add:

```
MAIL_MAILER=log
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="no-reply@giftlab.local"
MAIL_FROM_NAME="Gift Lab"
```

Comment above them: to send live, set `MAIL_MAILER=smtp`, `MAIL_USERNAME` to the Gmail address, and `MAIL_PASSWORD` to a Gmail **App Password** (not the account password; requires 2FA enabled). Until then `log` keeps it inert.

- [ ] **Step 2: Write the failing test (signed image)**

Create `tests/Feature/ProofImageTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Proof;
use App\Models\Quote;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

it('serves a proof image over a valid signed url', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('artwork/v1.png', 'PNGDATA');
    $quote = Quote::factory()->create();
    $proof = Proof::create([
        'quote_id' => $quote->id, 'version' => 1,
        'artwork_version_ref' => 'artwork/v1.png', 'state' => 'SENT',
    ]);

    $url = URL::temporarySignedRoute('proofs.image', now()->addDays(14), ['proof' => $proof->id]);
    $this->get($url)->assertOk();
});

it('rejects an unsigned proof image request', function (): void {
    $quote = Quote::factory()->create();
    $proof = Proof::create([
        'quote_id' => $quote->id, 'version' => 1,
        'artwork_version_ref' => 'artwork/v1.png', 'state' => 'SENT',
    ]);

    $this->get("/api/proofs/{$proof->id}/image")->assertStatus(403);
});
```

Confirm the proof storage disk (the artwork upload path — check `ArtworkUploadRequest`/the artwork-preview controller for the disk name; use the same).

- [ ] **Step 3: Controller + route**

Create `app/Http/Controllers/ProofImageController.php` — a signed, sessionless image stream (mirror the existing signed artwork-preview / track-view controllers for the disk + streaming approach):

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Proof;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sessionless, signature-authenticated proof thumbnail for emails. The signed
 * URL is the auth (email clients can't send cookies), with a long TTL so a
 * later-opened email still renders. Falls back to 404 if the ref isn't a
 * stored raster image; the email template then shows its placeholder tile.
 */
class ProofImageController extends Controller
{
    public function __invoke(Request $request, Proof $proof): StreamedResponse
    {
        $disk = (string) config('proofs.disk', 'local');
        $ref = (string) $proof->artwork_version_ref;
        abort_unless($ref !== '' && Storage::disk($disk)->exists($ref), 404);

        return Storage::disk($disk)->response($ref);
    }
}
```

In `routes/api.php`, register OUTSIDE the `auth:sanctum` group (public, signed), near the other signed routes:

```php
Route::get('/proofs/{proof}/image', ProofImageController::class)
    ->name('proofs.image')
    ->middleware('signed');
```

Confirm the disk name (`config('proofs.disk')` may not exist — use whatever the artwork upload uses, likely `'local'` or an S3 disk; adjust the config key).

- [ ] **Step 4: Run + commit**

```bash
vendor/bin/pest tests/Feature/ProofImageTest.php
```
Expected: PASS.

```bash
git add config/mail.php .env.example app/Http/Controllers/ProofImageController.php routes/api.php tests/Feature/ProofImageTest.php
git commit -m "feat(mail): Gmail SMTP env scaffold + signed proof-image route for emails"
```

---

## Task 11: The Mailable + premium template

**Files:**
- Create: `app/Mail/QuoteReadyMail.php`, `resources/views/mail/quote-ready.blade.php`
- Test: `tests/Feature/QuoteReadyMailTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/QuoteReadyMailTest.php`:

```php
<?php

declare(strict_types=1);

use App\Mail\QuoteReadyMail;
use App\Models\Quote;

it('builds the quote+proof variant with a subject', function (): void {
    $quote = Quote::factory()->create();
    $mail = new QuoteReadyMail($quote, hasProof: true, proofImageUrl: 'https://x/img');

    $mail->assertHasSubject('Your quote & proof are ready to review — Gift Lab');
    $mail->assertSeeInHtml('Review &amp; approve');
});

it('uses the quote-only subject when no proof', function (): void {
    $quote = Quote::factory()->create();
    $mail = new QuoteReadyMail($quote, hasProof: false, proofImageUrl: null);

    $mail->assertHasSubject('Your quote is ready to review — Gift Lab');
});
```

(`Mailable::assertHasSubject`/`assertSeeInHtml` are Laravel 11 built-ins.)

- [ ] **Step 2: Run to fail**

```bash
vendor/bin/pest tests/Feature/QuoteReadyMailTest.php
```
Expected: FAIL — class missing.

- [ ] **Step 3: The Mailable**

Create `app/Mail/QuoteReadyMail.php`:

```php
<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Quote;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Buyer-facing "your quote (and proof) are ready" email. Queued so a slow SMTP
 * handshake never blocks the send/approve request. One template, two variants.
 */
class QuoteReadyMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Quote $quote,
        public bool $hasProof,
        public ?string $proofImageUrl,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->hasProof
            ? 'Your quote & proof are ready to review — Gift Lab'
            : 'Your quote is ready to review — Gift Lab';

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.quote-ready',
            with: [
                'quote' => $this->quote,
                'hasProof' => $this->hasProof,
                'proofImageUrl' => $this->proofImageUrl,
                'quoteUrl' => rtrim((string) config('app.frontend_url', config('app.url')), '/').'/quotes/'.$this->quote->id,
            ],
        );
    }
}
```

(Confirm `config('app.frontend_url')` exists; if not, add it reading `FRONTEND_URL` env, defaulting to the SPA origin — the deep link must point at the SPA, not the API.)

- [ ] **Step 4: The template**

Create `resources/views/mail/quote-ready.blade.php` implementing the locked premium design — table-based, inline styles, a stacking media query for mobile, warm paper ground (`#f4f1ec`), ivory card, letter-spaced `GIFT LAB` wordmark, serif headline, a summary table (Quote ref, items, needed-by, total in `#6b4de6`), a proof strip (`<img src="{{ $proofImageUrl }}">` when `$hasProof && $proofImageUrl`, else the placeholder tile), and one CTA button linking `{{ $quoteUrl }}` labelled "Review & approve". Pull the exact markup from the approved mockup at `.superpowers/brainstorm/*/content/email-draft.html` (the desktop 600px block), converting the two static samples into `{{ }}` bindings:
- Quote ref → `{{ $quote->tracking_code ?? $quote->id }}`
- Items → line count + total unit qty from `$quote->lineItems`
- Needed by → `{{ optional($quote->needed_by)->format('j M Y') ?? '—' }}`
- Total → `S${{ number_format((float) $quote->total, 2) }}`
- Greeting name → the recipient buyer's first name (pass it in via `with` if needed).

- [ ] **Step 5: Run + commit**

```bash
vendor/bin/pest tests/Feature/QuoteReadyMailTest.php
```
Expected: PASS.

```bash
git add app/Mail/QuoteReadyMail.php resources/views/mail/quote-ready.blade.php tests/Feature/QuoteReadyMailTest.php
git commit -m "feat(mail): QuoteReadyMail + premium responsive template"
```

---

## Task 12: Fire the email at the trigger points

**Files:**
- Modify: `app/Services/QuoteService.php` (`send`, `issueProof`)
- Test: `tests/Feature/QuoteReadyMailTest.php` (append)

- [ ] **Step 1: Write the failing tests**

Append:

```php
use Illuminate\Support\Facades\Mail;
use App\Models\User;

it('emails the buyer with the proof variant on slim send', function (): void {
    Mail::fake();
    $buyer = User::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $buyer->company_id, 'state' => 'DRAFT', 'created_by' => $buyer->id]);
    Laravel\Sanctum\Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->postJson("/api/quotes/{$quote->id}/send", ['artwork_version_ref' => 'a/v1.png'])->assertOk();

    Mail::assertQueued(App\Mail\QuoteReadyMail::class, fn ($m) =>
        $m->hasProof === true && $m->hasTo($buyer->email));
});

it('emails the buyer with the quote-only variant on plain send', function (): void {
    Mail::fake();
    $buyer = User::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $buyer->company_id, 'state' => 'DRAFT', 'created_by' => $buyer->id]);
    Laravel\Sanctum\Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->postJson("/api/quotes/{$quote->id}/send")->assertOk();

    Mail::assertQueued(App\Mail\QuoteReadyMail::class, fn ($m) => $m->hasProof === false);
});
```

- [ ] **Step 2: Run to fail**

```bash
vendor/bin/pest tests/Feature/QuoteReadyMailTest.php --filter="emails the buyer"
```
Expected: FAIL — nothing queued.

- [ ] **Step 3: Dispatch from the service**

Add a private helper on `QuoteService` and call it from `send()` (both branches) and `issueProof()` (when it transitions into `PROOFING`):

```php
private function emailQuoteReady(Quote $quote, bool $hasProof): void
{
    $recipient = $quote->creator; // created_by relation on Quote
    if ($recipient === null || $recipient->email === null) {
        return;
    }

    $proofImageUrl = null;
    if ($hasProof && ($proof = $quote->proofs()->latest('version')->first()) !== null) {
        $proofImageUrl = URL::temporarySignedRoute('proofs.image', now()->addDays(14), ['proof' => $proof->id]);
    }

    DB::afterCommit(fn () => Mail::to($recipient->email)->queue(
        new QuoteReadyMail($quote, $hasProof, $proofImageUrl)
    ));
}
```

Call `$this->emailQuoteReady($quote, true)` on the slim `send` branch and in `issueProof` after the `ACCEPTED→PROOFING` transition; `$this->emailQuoteReady($quote, false)` on the plain `send` branch. Add imports: `use Illuminate\Support\Facades\Mail;`, `use Illuminate\Support\Facades\URL;`, `use App\Mail\QuoteReadyMail;`. Confirm `Quote::creator()` relation exists (`created_by` FK); if not, add `public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }` to `Quote`.

- [ ] **Step 4: Run + full suite**

```bash
vendor/bin/pest tests/Feature/QuoteReadyMailTest.php && vendor/bin/pest
```
Expected: PASS across the board.

- [ ] **Step 5: Commit**

```bash
git add app/Services/QuoteService.php app/Models/Quote.php tests/Feature/QuoteReadyMailTest.php
git commit -m "feat(mail): queue QuoteReadyMail on send and proof-issue"
```

---

## Task 13: Full verification

- [ ] **Step 1: Backend + frontend suites**

```bash
vendor/bin/pest
cd frontend && npx tsc --noEmit && npx vitest run src/pages/QuoteDetailPage.test.tsx && cd ..
```
Expected: all green. Report real counts.

- [ ] **Step 2: Grep the rename is total**

```bash
grep -rn "PO_ISSUED\|PurchaseOrder\|purchase-order\|purchase_order\|issuePurchaseOrder" app/ routes/ frontend/src/ tests/ docs/ | grep -v vendor | grep -v node_modules
```
Expected: **nothing**.

- [ ] **Step 3: Drive it in the browser** (use the preview tool per the harness `verify` flow)

Log in as staff, open a DRAFT quote, Send **with** a proof → confirm it lands in PROOFING and (with `MAIL_MAILER=log`) a `QuoteReadyMail` is written to `storage/logs/laravel.log`. Log in as the buyer, Approve → confirm PROOF_APPROVED and `accepted_at` set (`php artisan tinker`). As the buyer, confirm there is **no** Cancel control; as staff, cancel a SENT quote and confirm it returns stock. Issue an invoice (renamed) and confirm the flow reaches CONFIRMED.

- [ ] **Step 4: Final commit if any fixes**

```bash
git add -A && git commit -m "test(quote): verification fixes for spine reshape"
```

---

## Self-Review Notes

- **Spec coverage:** Feature 1 (cancel) → Tasks 4–5. Feature 2 (slim + stamp) → Tasks 6–9. Feature 3 (rename) → Tasks 1–3. Feature 4 (email) → Tasks 10–12. Verification → Task 13.
- **Build order** matches the spec: rename first on a green tree, then cancel, then slim path, then email.
- **Naming consistency:** `accepted_at`/`accepted_by` (Tasks 6, 8), `createProofVersion` helper (Task 7, reused conceptually in Task 8's flow), `QuoteReadyMail(quote, hasProof, proofImageUrl)` (Tasks 11–12), `proofs.image` signed route (Tasks 10, 12).
- **Verify-before-relying flags for the engineer:** the test DB driver for the enum `ALTER` (Task 2), the proof storage disk name (Tasks 10–11), `Quote::creator`/`created_by` relation (Task 12), `config('app.frontend_url')` for the deep link (Task 11), and the store's quote-refresh method name (Tasks 5, 9). Each is called out at its task.
