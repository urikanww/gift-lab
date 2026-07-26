# Multi-Agent Workflow Test Harness Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a role-based, HTTP-driven test harness (`tests/Harness/`) whose agents drive the real Gift Lab order state machine end-to-end and assert workflow invariants, including regression coverage for the three once-broken `ORDER_WORKFLOW.md` blocker paths.

**Architecture:** Four PHP agent classes each play one actor by calling the real `/api/...` routes through Laravel's test HTTP client authenticated with `Sanctum::actingAs()` — `StaffAgent`, `BuyerAgent`, `ProductionAgent`, plus a read-only `ValidatorAgent` oracle. A `HarnessContext` holds the actors and the quote under test; a `Violation` value object records invariant failures. Five Pest scenarios compose the agents.

**Tech Stack:** PHP 8.3, Laravel, Pest v3, `Laravel\Sanctum`, in-memory SQLite (`RefreshDatabase`).

**Design source:** `docs/superpowers/specs/2026-07-26-workflow-harness-agents-design.md`

**Verified ground truth (payloads used below are read from code, not guessed):**
- Create draft: `POST /api/quotes` — `{company_id, line_items:[{product_id, variant_id, qty, customization:{mode,artwork_ref}}], shipping_address:{...}}` (`StoreQuoteRequest` accepts per-line `customization.mode` in `designer|buyer_uploaded` and `customization.artwork_ref`).
- Send: `POST /api/quotes/{quote}/send` (staff; artwork field omitted).
- Buyer accept: `POST /api/quotes/{quote}/accept` (no body).
- Stage proof: `POST /api/quotes/{quote}/lines/{lineItem}/proofs` — `{artwork_version_ref}` → 201, `data.id` = proof id.
- Send proofs: `POST /api/quotes/{quote}/proofs/send`.
- Proof decide: `POST /api/proofs/{proof}/decide` — `{decision: 'approve'}` or `{decision: 'request_changes', notes}` (authorized to the owning-company **buyer**, not staff).
- Issue invoice: `POST /api/quotes/{quote}/invoice` — `{po_ref}` (required, unique) → 201, `invoice.*`.
- Procure: `POST /api/quotes/{quote}/procure`.
- Reconfirm line: `POST /api/line-items/{lineItem}/reconfirm` — `{action: 'amend'|'approve'|'drop', qty?, unit_price?}` (permission `procurement.manage`).
- Production queue: `GET /api/production-queue` → `data[]`.
- Advance job: `POST /api/production-jobs/{job}/advance` — `{state: 'IN_PRODUCTION'|'SHIPPED'|'CLOSED', consignment_ref?, carrier?}`; `SHIPPED` requires `consignment_ref`. `JobState`: READY→IN_PRODUCTION→SHIPPED→CLOSED; when the last job closes, the quote transitions to CLOSED.
- Cancel: `POST /api/quotes/{quote}/cancel` — `{reason}`.
- Chase: artisan `quotes:chase`; increments `quotes.reminders_sent`.
- `Carrier` enum values include `NINJAVAN`.
- `User::factory()->staffAdmin()` = role `staff_admin` (holds staff/procurement/production permissions, per existing Feature tests); default factory user = `buyer`.
- `LineItem` factory: `->ready()` sets `line_state = READY`; `->awaitingReconfirm()` sets `AWAITING_RECONFIRM`. `Proof` factory: `->forLine($line)`, `->approved()`.
- Price-first route: buyer `accept()` **before** proof approval sets `accepted_at`, so approving the last proof lands `PROOF_APPROVED` (not `ARTWORK_APPROVED`).

---

## File Structure

```
tests/
  Pest.php                                    MODIFY: bind RefreshDatabase to Harness/
  Harness/
    Support/
      Violation.php                           value object {code, message, state}
      HarnessContext.php                      actors + quote-under-test + action log
    Agents/
      ValidatorAgent.php                      oracle: assertLegalTransition + check() + violations()
      StaffAgent.php                          internal staff actions
      BuyerAgent.php                          customer actions
      ProductionAgent.php                     procurement + floor actions
    Scenarios/
      HappyPathTest.php
      ChangesRequestedRecoversTest.php
      AcceptAsIsRetotalsTest.php
      Cancel3dFilamentReturnTest.php
      SilentBuyerChaseTest.php
tests/Unit/Harness/
  ViolationTest.php                           pure value-object unit test
  ValidatorAgentTransitionTest.php            assertLegalTransition against QuoteState
```

---

### Task 1: Pest binding + `Violation` value object

**Files:**
- Modify: `tests/Pest.php`
- Create: `tests/Harness/Support/Violation.php`
- Test: `tests/Unit/Harness/ViolationTest.php`

- [ ] **Step 1: Bind RefreshDatabase + TestCase to the Harness directory**

In `tests/Pest.php`, after the existing `->in('Feature')` binding, add:

```php
pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Harness');
```

Note: `tests/Harness/` is a sibling of `tests/Feature/`. The path glob `'Harness'` matches it. Support/Agents classes are plain classes (not test files) and are ignored by Pest's test collector but autoloaded via the `Tests\` PSR-4 namespace already configured in `composer.json`.

- [ ] **Step 2: Write the failing unit test for `Violation`**

Create `tests/Unit/Harness/ViolationTest.php`:

```php
<?php

declare(strict_types=1);

use Tests\Harness\Support\Violation;

it('carries a code, message and state', function (): void {
    $v = new Violation('ILLEGAL_TRANSITION', 'READY -> SENT is illegal', 'READY');

    expect($v->code)->toBe('ILLEGAL_TRANSITION')
        ->and($v->message)->toBe('READY -> SENT is illegal')
        ->and($v->state)->toBe('READY');
});

it('renders a readable string for test failure output', function (): void {
    $v = new Violation('INVOICE_MATCHES_PRODUCED', 'billed 5 != produced 2', 'PROCURING');

    expect((string) $v)->toBe('[INVOICE_MATCHES_PRODUCED] billed 5 != produced 2 (state: PROCURING)');
});
```

- [ ] **Step 3: Run it to verify it fails**

Run: `vendor/bin/pest tests/Unit/Harness/ViolationTest.php`
Expected: FAIL — class `Tests\Harness\Support\Violation` not found.

- [ ] **Step 4: Implement `Violation`**

Create `tests/Harness/Support/Violation.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Harness\Support;

use Stringable;

/**
 * One invariant failure recorded by the ValidatorAgent. Immutable; the harness
 * collects these and a scenario asserts on the set rather than throwing at the
 * first failure, so one run can surface every break at once.
 */
final class Violation implements Stringable
{
    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly ?string $state = null,
    ) {}

    public function __toString(): string
    {
        return "[{$this->code}] {$this->message} (state: {$this->state})";
    }
}
```

- [ ] **Step 5: Run it to verify it passes**

Run: `vendor/bin/pest tests/Unit/Harness/ViolationTest.php`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add tests/Pest.php tests/Harness/Support/Violation.php tests/Unit/Harness/ViolationTest.php
git commit -m "test(harness): Violation value object + Harness Pest binding"
```

---

### Task 2: `HarnessContext`

**Files:**
- Create: `tests/Harness/Support/HarnessContext.php`

No standalone test — it is exercised by every agent and scenario. It is a plain
data holder with no logic to unit-test in isolation.

- [ ] **Step 1: Implement `HarnessContext`**

Create `tests/Harness/Support/HarnessContext.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Harness\Support;

use App\Models\Quote;
use App\Models\User;
use Tests\TestCase;

/**
 * Per-scenario shared state: the authenticated actors, the quote currently
 * under test, and an ordered log of the actions the agents performed (useful
 * when a scenario fails and you need the journey that led there).
 *
 * `staff` is a staff_admin used by both StaffAgent and ProductionAgent (the
 * production/procurement routes are staff-gated). `buyer` belongs to the same
 * company as the quote and drives the customer-facing endpoints.
 */
final class HarnessContext
{
    public ?Quote $quote = null;

    /** @var array<int, array<string, mixed>> */
    public array $log = [];

    public function __construct(
        public readonly TestCase $test,
        public readonly User $staff,
        public readonly User $buyer,
    ) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public function record(string $action, array $meta = []): void
    {
        $this->log[] = ['action' => $action] + $meta;
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add tests/Harness/Support/HarnessContext.php
git commit -m "test(harness): HarnessContext shared per-scenario state"
```

---

### Task 3: `ValidatorAgent`

**Files:**
- Create: `tests/Harness/Agents/ValidatorAgent.php`
- Test: `tests/Unit/Harness/ValidatorAgentTransitionTest.php`

- [ ] **Step 1: Write the failing unit test for transition legality**

Create `tests/Unit/Harness/ValidatorAgentTransitionTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\QuoteState;
use App\Models\User;
use Tests\Harness\Agents\ValidatorAgent;
use Tests\Harness\Support\HarnessContext;

function validator(): ValidatorAgent
{
    // The oracle's transition check reads only the enum; a bare context with
    // unsaved users is enough (no DB access on this path).
    $ctx = new HarnessContext(test(), new User(), new User());

    return new ValidatorAgent($ctx);
}

it('records no violation for a legal quote transition', function (): void {
    $v = validator();

    $v->assertLegalTransition(QuoteState::Draft, QuoteState::Sent);

    expect($v->violations())->toBeEmpty();
});

it('records an ILLEGAL_TRANSITION violation for an illegal move', function (): void {
    $v = validator();

    $v->assertLegalTransition(QuoteState::Ready, QuoteState::Sent);

    expect($v->violations())->toHaveCount(1)
        ->and($v->violations()[0]->code)->toBe('ILLEGAL_TRANSITION');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/pest tests/Unit/Harness/ValidatorAgentTransitionTest.php`
Expected: FAIL — class `Tests\Harness\Agents\ValidatorAgent` not found.

- [ ] **Step 3: Implement `ValidatorAgent`**

Create `tests/Harness/Agents/ValidatorAgent.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Harness\Agents;

use App\Enums\QuoteState;
use App\Models\StockMovement;
use InvalidArgumentException;
use Tests\Harness\Support\HarnessContext;
use Tests\Harness\Support\Violation;

/**
 * Read-only test oracle. Never mutates app state. Records a Violation instead of
 * throwing, so a scenario can drive the whole journey and then assert on the
 * complete set of invariant failures.
 */
final class ValidatorAgent
{
    /** @var array<int, Violation> */
    private array $violations = [];

    public function __construct(private readonly HarnessContext $ctx) {}

    /**
     * Legality is defined by the state machine itself, so this stays correct if
     * QuoteState changes.
     */
    public function assertLegalTransition(QuoteState $from, QuoteState $to): void
    {
        if (! $from->canTransitionTo($to)) {
            $this->violations[] = new Violation(
                'ILLEGAL_TRANSITION',
                "{$from->value} -> {$to->value} is not a legal quote transition",
                $from->value,
            );
        }
    }

    public function check(string $invariant): void
    {
        match ($invariant) {
            'INVOICE_MATCHES_PRODUCED' => $this->checkInvoiceMatchesProduced(),
            'LEDGER_BALANCED_AFTER_CANCEL' => $this->checkLedgerBalancedAfterCancel(),
            default => throw new InvalidArgumentException("Unknown invariant: {$invariant}"),
        };
    }

    /** @return array<int, Violation> */
    public function violations(): array
    {
        return $this->violations;
    }

    /**
     * Every non-dropped line must bill the quantity it actually produces. A
     * produced_qty of 0 means procurement has not run for the line yet, so it is
     * not asserted.
     */
    private function checkInvoiceMatchesProduced(): void
    {
        $quote = $this->ctx->quote?->fresh(['lineItems']);

        foreach ($quote?->lineItems ?? [] as $line) {
            if ($line->line_state->value === 'DROPPED') {
                continue;
            }

            $produced = (int) $line->procured_qty;

            if ($produced > 0 && (int) $line->qty !== $produced) {
                $this->violations[] = new Violation(
                    'INVOICE_MATCHES_PRODUCED',
                    "Line {$line->id}: billed qty {$line->qty} != produced qty {$produced}",
                    $quote?->state->value,
                );
            }
        }
    }

    /**
     * After a cancel, every variant-backed line's stock movements must net to
     * zero (SALE consumed, RETURN gave back the same). Lines with no variant are
     * skipped here and handled by the scenario that owns them.
     */
    private function checkLedgerBalancedAfterCancel(): void
    {
        $quote = $this->ctx->quote?->fresh(['lineItems.variant']);

        foreach ($quote?->lineItems ?? [] as $line) {
            if ($line->variant === null) {
                continue;
            }

            $net = (int) StockMovement::query()
                ->where('ref_type', $line->getMorphClass())
                ->where('ref_id', $line->getKey())
                ->sum('delta');

            if ($net !== 0) {
                $this->violations[] = new Violation(
                    'LEDGER_BALANCED_AFTER_CANCEL',
                    "Line {$line->id}: net stock movement {$net} != 0 after cancel",
                    $quote?->state->value,
                );
            }
        }
    }
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `vendor/bin/pest tests/Unit/Harness/ValidatorAgentTransitionTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add tests/Harness/Agents/ValidatorAgent.php tests/Unit/Harness/ValidatorAgentTransitionTest.php
git commit -m "test(harness): ValidatorAgent oracle (transition + invariant checks)"
```

---

### Task 4: `StaffAgent`

**Files:**
- Create: `tests/Harness/Agents/StaffAgent.php`
- Test: `tests/Harness/Scenarios/StaffAgentSmokeTest.php` (temporary smoke; kept as coverage)

- [ ] **Step 1: Write the failing smoke test**

Create `tests/Harness/Scenarios/StaffAgentSmokeTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use Tests\Harness\Agents\StaffAgent;
use Tests\Harness\Support\HarnessContext;

beforeEach(function (): void {
    seedPricing();
    $this->company = Company::factory()->create();
    $this->buyer = User::factory()->create(['company_id' => $this->company->id, 'role' => 'buyer']);
    $this->staff = User::factory()->staffAdmin()->create();
    $this->product = Product::factory()->create(['base_cost' => 10, 'print_method' => 'UV', 'publish_state' => 'PUBLISHED']);
    Variant::factory()->create(['product_id' => $this->product->id]);
    $this->ctx = new HarnessContext($this, $this->staff, $this->buyer);
});

it('StaffAgent creates a draft and sends it to the buyer', function (): void {
    $staff = new StaffAgent($this->ctx);

    $staff->createDraft($this->company->id, [
        ['product_id' => $this->product->id, 'variant_id' => null, 'qty' => 3],
    ]);

    expect($this->ctx->quote->state->value)->toBe('DRAFT');

    $staff->send();

    expect($this->ctx->quote->fresh()->state->value)->toBe('SENT');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/pest tests/Harness/Scenarios/StaffAgentSmokeTest.php`
Expected: FAIL — class `Tests\Harness\Agents\StaffAgent` not found.

- [ ] **Step 3: Implement `StaffAgent`**

Create `tests/Harness/Agents/StaffAgent.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Harness\Agents;

use App\Models\LineItem;
use App\Models\Quote;
use Laravel\Sanctum\Sanctum;
use Tests\Harness\Support\HarnessContext;

/**
 * Plays internal staff. Each method performs one real staff action over HTTP as
 * the staff_admin actor and asserts the transport succeeded (never a 500). It
 * does not assert workflow correctness — that is the ValidatorAgent's job.
 */
final class StaffAgent
{
    public function __construct(private readonly HarnessContext $ctx) {}

    private function actAsStaff(): void
    {
        Sanctum::actingAs($this->ctx->staff);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lineItems
     * @param  array<string, mixed>  $opts  merged into the payload (e.g. needed_by)
     * @return array<string, mixed>  the created quote resource
     */
    public function createDraft(int $companyId, array $lineItems, array $opts = []): array
    {
        $this->actAsStaff();

        $payload = ['company_id' => $companyId, 'line_items' => $lineItems] + $opts;
        $payload['shipping_address'] ??= [
            'recipient_name' => 'Rachel Tan',
            'phone' => '+6591234567',
            'line1' => '1 Marina Blvd',
            'postal_code' => '018989',
        ];

        $data = $this->ctx->test->postJson('/api/quotes', $payload)
            ->assertCreated()
            ->json('data');

        $this->ctx->quote = Quote::findOrFail($data['id']);
        $this->ctx->record('staff.createDraft', ['quote_id' => $data['id']]);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $opts  optional send payload (artwork omitted by default)
     */
    public function send(array $opts = []): void
    {
        $this->actAsStaff();
        $this->ctx->test->postJson("/api/quotes/{$this->ctx->quote->id}/send", $opts)->assertOk();
        $this->ctx->record('staff.send');
    }

    /**
     * Stage one line's artwork as a DRAFT proof. Returns the proof resource
     * (its `id` feeds BuyerAgent::approveProof / requestChanges).
     *
     * @return array<string, mixed>
     */
    public function stageProof(LineItem $line, string $ref): array
    {
        $this->actAsStaff();

        $data = $this->ctx->test
            ->postJson("/api/quotes/{$this->ctx->quote->id}/lines/{$line->id}/proofs", [
                'artwork_version_ref' => $ref,
            ])
            ->assertCreated()
            ->json('data');

        $this->ctx->record('staff.stageProof', ['line_id' => $line->id, 'proof_id' => $data['id']]);

        return $data;
    }

    public function sendProofs(): void
    {
        $this->actAsStaff();
        $this->ctx->test->postJson("/api/quotes/{$this->ctx->quote->id}/proofs/send")->assertOk();
        $this->ctx->record('staff.sendProofs');
    }

    /**
     * @return array<string, mixed>  the invoice payload
     */
    public function issueInvoice(string $poRef): array
    {
        $this->actAsStaff();

        $invoice = $this->ctx->test
            ->postJson("/api/quotes/{$this->ctx->quote->id}/invoice", ['po_ref' => $poRef])
            ->assertCreated()
            ->json('invoice');

        $this->ctx->record('staff.issueInvoice', ['po_ref' => $poRef]);

        return $invoice;
    }

    public function procure(): void
    {
        $this->actAsStaff();
        $this->ctx->test->postJson("/api/quotes/{$this->ctx->quote->id}/procure")->assertOk();
        $this->ctx->record('staff.procure');
    }

    public function cancel(?string $reason = 'harness cancel'): void
    {
        $this->actAsStaff();
        $this->ctx->test->postJson("/api/quotes/{$this->ctx->quote->id}/cancel", ['reason' => $reason])->assertOk();
        $this->ctx->record('staff.cancel', ['reason' => $reason]);
    }
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `vendor/bin/pest tests/Harness/Scenarios/StaffAgentSmokeTest.php`
Expected: PASS (1 test). If `createDraft` returns a 422, read `app/Http/Requests/StoreQuoteRequest.php` for a required field the smoke payload omits and add it — do not weaken the assertion.

- [ ] **Step 5: Commit**

```bash
git add tests/Harness/Agents/StaffAgent.php tests/Harness/Scenarios/StaffAgentSmokeTest.php
git commit -m "test(harness): StaffAgent drives real staff endpoints"
```

---

### Task 5: `BuyerAgent`

**Files:**
- Create: `tests/Harness/Agents/BuyerAgent.php`
- Test: `tests/Harness/Scenarios/BuyerAgentSmokeTest.php`

- [ ] **Step 1: Write the failing smoke test**

Create `tests/Harness/Scenarios/BuyerAgentSmokeTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Quote;
use App\Models\User;
use Tests\Harness\Agents\BuyerAgent;
use Tests\Harness\Support\HarnessContext;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->buyer = User::factory()->create(['company_id' => $this->company->id, 'role' => 'buyer']);
    $this->staff = User::factory()->staffAdmin()->create();
    $this->ctx = new HarnessContext($this, $this->staff, $this->buyer);
});

it('BuyerAgent accepts a SENT quote', function (): void {
    $this->ctx->quote = Quote::factory()->create([
        'company_id' => $this->company->id,
        'state' => 'SENT',
    ]);

    (new BuyerAgent($this->ctx))->accept();

    expect($this->ctx->quote->fresh()->state->value)->toBe('ACCEPTED');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/pest tests/Harness/Scenarios/BuyerAgentSmokeTest.php`
Expected: FAIL — class `Tests\Harness\Agents\BuyerAgent` not found.

- [ ] **Step 3: Implement `BuyerAgent`**

Create `tests/Harness/Agents/BuyerAgent.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Harness\Agents;

use Laravel\Sanctum\Sanctum;
use Tests\Harness\Support\HarnessContext;

/**
 * Plays the customer. Scriptable disposition: approve, request changes, or go
 * silent. Acts as the owning-company buyer, which is who the proof-decision and
 * accept endpoints authorize.
 */
final class BuyerAgent
{
    public function __construct(private readonly HarnessContext $ctx) {}

    private function actAsBuyer(): void
    {
        Sanctum::actingAs($this->ctx->buyer);
    }

    public function accept(): void
    {
        $this->actAsBuyer();
        $this->ctx->test->postJson("/api/quotes/{$this->ctx->quote->id}/accept")->assertOk();
        $this->ctx->record('buyer.accept');
    }

    public function approveProof(int $proofId): void
    {
        $this->actAsBuyer();
        $this->ctx->test->postJson("/api/proofs/{$proofId}/decide", ['decision' => 'approve'])->assertOk();
        $this->ctx->record('buyer.approveProof', ['proof_id' => $proofId]);
    }

    public function requestChanges(int $proofId, string $notes): void
    {
        $this->actAsBuyer();
        $this->ctx->test->postJson("/api/proofs/{$proofId}/decide", [
            'decision' => 'request_changes',
            'notes' => $notes,
        ])->assertOk();
        $this->ctx->record('buyer.requestChanges', ['proof_id' => $proofId]);
    }

    /**
     * Models a non-responding buyer. Deliberately does nothing so the chase
     * mechanism has an un-actioned order to act on.
     */
    public function goSilent(): void
    {
        $this->ctx->record('buyer.goSilent');
    }
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `vendor/bin/pest tests/Harness/Scenarios/BuyerAgentSmokeTest.php`
Expected: PASS (1 test).

- [ ] **Step 5: Commit**

```bash
git add tests/Harness/Agents/BuyerAgent.php tests/Harness/Scenarios/BuyerAgentSmokeTest.php
git commit -m "test(harness): BuyerAgent drives buyer endpoints"
```

---

### Task 6: `ProductionAgent`

**Files:**
- Create: `tests/Harness/Agents/ProductionAgent.php`
- Test: `tests/Harness/Scenarios/ProductionAgentSmokeTest.php`

- [ ] **Step 1: Write the failing smoke test**

This builds a READY quote with an approved-proof line via factories (the same
pattern as `tests/Feature/ProductionQueueTest.php`'s `readyQuoteWithProof`),
queues jobs through `QueueService`, then drives the job forward with the agent.

Create `tests/Harness/Scenarios/ProductionAgentSmokeTest.php`:

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
use Tests\Harness\Agents\ProductionAgent;
use Tests\Harness\Support\HarnessContext;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->buyer = User::factory()->create(['company_id' => $this->company->id, 'role' => 'buyer']);
    $this->staff = User::factory()->staffAdmin()->create();
    $this->product = Product::factory()->create(['class' => 'CORE', 'print_method' => 'UV']);
    $this->ctx = new HarnessContext($this, $this->staff, $this->buyer);
});

it('ProductionAgent starts a queued job into production', function (): void {
    $quote = Quote::factory()->create(['company_id' => $this->company->id, 'state' => 'PROCURING']);
    $line = LineItem::factory()->ready()->create([
        'quote_id' => $quote->id,
        'product_id' => $this->product->id,
        'qty' => 10,
        'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/core.png'],
    ]);
    Proof::factory()->forLine($line)->approved()->create();

    $job = app(QueueService::class)->buildJobsForQuote($quote->load('lineItems.product'))->first();
    $this->ctx->quote = $quote->fresh();

    (new ProductionAgent($this->ctx))->startJob($job);

    expect($job->fresh()->state->value)->toBe('IN_PRODUCTION');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/pest tests/Harness/Scenarios/ProductionAgentSmokeTest.php`
Expected: FAIL — class `Tests\Harness\Agents\ProductionAgent` not found.

- [ ] **Step 3: Implement `ProductionAgent`**

Create `tests/Harness/Agents/ProductionAgent.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Harness\Agents;

use App\Models\LineItem;
use App\Models\ProductionJob;
use Laravel\Sanctum\Sanctum;
use Tests\Harness\Support\HarnessContext;

/**
 * Plays procurement + the production floor. Acts as the staff_admin actor (the
 * procurement/production routes are staff-gated). Job progress is driven through
 * the explicit `advance` action — downloading a print file no longer starts a
 * job in this app, so the harness never relies on that side effect.
 */
final class ProductionAgent
{
    public function __construct(private readonly HarnessContext $ctx) {}

    private function actAsStaff(): void
    {
        Sanctum::actingAs($this->ctx->staff);
    }

    /**
     * Resolve a line stuck in AWAITING_RECONFIRM.
     *
     * @param  'amend'|'approve'|'drop'  $action
     * @param  array<string, mixed>  $data  amend also needs {qty, unit_price}
     */
    public function reconfirm(LineItem $line, string $action, array $data = []): void
    {
        $this->actAsStaff();
        $this->ctx->test
            ->postJson("/api/line-items/{$line->id}/reconfirm", ['action' => $action] + $data)
            ->assertOk();
        $this->ctx->record('production.reconfirm', ['line_id' => $line->id, 'action' => $action]);
    }

    /**
     * @return array<int, array<string, mixed>>  queued job resources
     */
    public function pullQueue(): array
    {
        $this->actAsStaff();

        return $this->ctx->test->getJson('/api/production-queue')->assertOk()->json('data');
    }

    public function startJob(ProductionJob $job): void
    {
        $this->actAsStaff();
        $this->ctx->test
            ->postJson("/api/production-jobs/{$job->id}/advance", ['state' => 'IN_PRODUCTION'])
            ->assertOk();
        $this->ctx->record('production.startJob', ['job_id' => $job->id]);
    }

    public function ship(ProductionJob $job, string $consignmentRef = 'TRACK-1', string $carrier = 'NINJAVAN'): void
    {
        $this->actAsStaff();
        $this->ctx->test
            ->postJson("/api/production-jobs/{$job->id}/advance", [
                'state' => 'SHIPPED',
                'consignment_ref' => $consignmentRef,
                'carrier' => $carrier,
            ])
            ->assertOk();
        $this->ctx->record('production.ship', ['job_id' => $job->id]);
    }

    public function closeJob(ProductionJob $job): void
    {
        $this->actAsStaff();
        $this->ctx->test
            ->postJson("/api/production-jobs/{$job->id}/advance", ['state' => 'CLOSED'])
            ->assertOk();
        $this->ctx->record('production.closeJob', ['job_id' => $job->id]);
    }
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `vendor/bin/pest tests/Harness/Scenarios/ProductionAgentSmokeTest.php`
Expected: PASS (1 test).

- [ ] **Step 5: Commit**

```bash
git add tests/Harness/Agents/ProductionAgent.php tests/Harness/Scenarios/ProductionAgentSmokeTest.php
git commit -m "test(harness): ProductionAgent drives procurement + floor endpoints"
```

---

### Task 7: Scenario 1 — Happy path (DRAFT → CLOSED)

**Files:**
- Create: `tests/Harness/Scenarios/HappyPathTest.php`

- [ ] **Step 1: Write the scenario test**

Create `tests/Harness/Scenarios/HappyPathTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Support\Facades\Mail;
use Tests\Harness\Agents\BuyerAgent;
use Tests\Harness\Agents\ProductionAgent;
use Tests\Harness\Agents\StaffAgent;
use Tests\Harness\Agents\ValidatorAgent;
use Tests\Harness\Support\HarnessContext;

beforeEach(function (): void {
    Mail::fake();
    seedPricing();
    $this->company = Company::factory()->create();
    $this->buyer = User::factory()->create(['company_id' => $this->company->id, 'role' => 'buyer']);
    $this->staff = User::factory()->staffAdmin()->create();
    $this->product = Product::factory()->create(['base_cost' => 10, 'print_method' => 'UV', 'publish_state' => 'PUBLISHED', 'class' => 'CORE']);
    Variant::factory()->create(['product_id' => $this->product->id, 'stock_on_hand' => 100]);
    $this->ctx = new HarnessContext($this, $this->staff, $this->buyer);
});

it('runs a customized order from draft to closed with no violations', function (): void {
    $staff = new StaffAgent($this->ctx);
    $buyer = new BuyerAgent($this->ctx);
    $production = new ProductionAgent($this->ctx);
    $validator = new ValidatorAgent($this->ctx);

    // 1. Staff drafts a customized (proofable) line and sends it.
    $staff->createDraft($this->company->id, [
        [
            'product_id' => $this->product->id,
            'variant_id' => null,
            'qty' => 5,
            'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/logo-v1.png'],
        ],
    ]);
    $staff->send();
    expect($this->ctx->quote->fresh()->state->value)->toBe('SENT');

    // 2. Buyer accepts the price first (price-first route).
    $buyer->accept();
    expect($this->ctx->quote->fresh()->state->value)->toBe('ACCEPTED');

    // 3. Staff issues the proof for the line; buyer approves it.
    $line = $this->ctx->quote->fresh('lineItems')->lineItems->first();
    $proof = $staff->stageProof($line, 'artwork/logo-v1.png');
    $staff->sendProofs();
    $buyer->approveProof($proof['id']);
    // Price agreed before sign-off => PROOF_APPROVED, not ARTWORK_APPROVED.
    expect($this->ctx->quote->fresh()->state->value)->toBe('PROOF_APPROVED');

    // 4. Staff invoices (drives INVOICED -> CONFIRMED) then procures.
    $staff->issueInvoice('PO-HAPPY-1');
    expect($this->ctx->quote->fresh()->state->value)->toBe('CONFIRMED');
    $staff->procure();
    expect($this->ctx->quote->fresh()->state->value)->toBe('READY');

    // 5. Each built job carries a print file (per-line-file invariant, scenario form).
    $jobs = $this->ctx->quote->fresh('jobs')->jobs;
    expect($jobs)->not->toBeEmpty();
    foreach ($jobs as $job) {
        expect($job->artwork_refs)->not->toBeEmpty();
    }

    // 6. Floor runs each job to CLOSED; the last close closes the quote.
    foreach ($jobs as $job) {
        $production->startJob($job);
        $production->ship($job);
        $production->closeJob($job);
    }
    expect($this->ctx->quote->fresh()->state->value)->toBe('CLOSED');

    // 7. Invariant: billed == produced across every line.
    $validator->check('INVOICE_MATCHES_PRODUCED');
    expect($validator->violations())->toBeEmpty(
        implode("\n", array_map('strval', $validator->violations())),
    );
});
```

- [ ] **Step 2: Run it**

Run: `vendor/bin/pest tests/Harness/Scenarios/HappyPathTest.php`
Expected: PASS. If a state assertion fails, read the failure and the relevant service method (do not weaken the assertion). Common real fixes:
- If `stageProof` 403s: confirm the line's `customization.mode` is `designer` so `LineItem::needsProof()` is true.
- If step 3 lands `ARTWORK_APPROVED`: the buyer `accept()` in step 2 did not set `accepted_at`; confirm accept returned 200 before proofing.

- [ ] **Step 3: Commit**

```bash
git add tests/Harness/Scenarios/HappyPathTest.php
git commit -m "test(harness): happy-path scenario draft->closed, zero violations"
```

---

### Task 8: Scenario 2 — Changes-requested recovers (Blocker 1 regression)

**Files:**
- Create: `tests/Harness/Scenarios/ChangesRequestedRecoversTest.php`

- [ ] **Step 1: Write the scenario test**

Proves `CHANGES_REQUESTED` is no longer a dead end: buyer rejects a proof, staff
reissues, buyer approves, order advances.

Create `tests/Harness/Scenarios/ChangesRequestedRecoversTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Support\Facades\Mail;
use Tests\Harness\Agents\BuyerAgent;
use Tests\Harness\Agents\StaffAgent;
use Tests\Harness\Agents\ValidatorAgent;
use Tests\Harness\Support\HarnessContext;

beforeEach(function (): void {
    Mail::fake();
    seedPricing();
    $this->company = Company::factory()->create();
    $this->buyer = User::factory()->create(['company_id' => $this->company->id, 'role' => 'buyer']);
    $this->staff = User::factory()->staffAdmin()->create();
    $this->product = Product::factory()->create(['base_cost' => 10, 'print_method' => 'UV', 'publish_state' => 'PUBLISHED', 'class' => 'CORE']);
    Variant::factory()->create(['product_id' => $this->product->id, 'stock_on_hand' => 100]);
    $this->ctx = new HarnessContext($this, $this->staff, $this->buyer);
});

it('recovers from CHANGES_REQUESTED through a revised proof (blocker 1 regression)', function (): void {
    $staff = new StaffAgent($this->ctx);
    $buyer = new BuyerAgent($this->ctx);
    $validator = new ValidatorAgent($this->ctx);

    $staff->createDraft($this->company->id, [
        [
            'product_id' => $this->product->id,
            'variant_id' => null,
            'qty' => 4,
            'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/v1.png'],
        ],
    ]);
    $staff->send();
    $buyer->accept();

    $line = $this->ctx->quote->fresh('lineItems')->lineItems->first();

    // Round 1: buyer requests changes -> order rolls to CHANGES_REQUESTED.
    $v1 = $staff->stageProof($line, 'artwork/v1.png');
    $staff->sendProofs();
    $buyer->requestChanges($v1['id'], 'Logo too small');
    expect($this->ctx->quote->fresh()->state->value)->toBe('CHANGES_REQUESTED');

    // Round 2: staff reissue a revised proof; buyer approves -> order recovers.
    $v2 = $staff->stageProof($line, 'artwork/v2.png');
    $staff->sendProofs();
    $buyer->approveProof($v2['id']);

    // NO_STUCK_ORDER, scenario form: the order left CHANGES_REQUESTED and
    // reached an approved state rather than dying.
    expect($this->ctx->quote->fresh()->state->value)->toBe('PROOF_APPROVED');
    expect($validator->violations())->toBeEmpty();
});
```

- [ ] **Step 2: Run it**

Run: `vendor/bin/pest tests/Harness/Scenarios/ChangesRequestedRecoversTest.php`
Expected: PASS. If round 2's `stageProof` is rejected because the quote is in `CHANGES_REQUESTED`, read `app/Services/QuoteService.php` `stageProof`/`issueProof` guard and, if the app genuinely blocks the revised proof, this documents a real dead-end — record it as a failing assertion with a clear message and stop for review rather than forcing a pass.

- [ ] **Step 3: Commit**

```bash
git add tests/Harness/Scenarios/ChangesRequestedRecoversTest.php
git commit -m "test(harness): changes-requested recovery regression (blocker 1)"
```

---

### Task 9: Scenario 3 — Accept-as-is re-totals (Blocker 3 regression)

**Files:**
- Create: `tests/Harness/Scenarios/AcceptAsIsRetotalsTest.php`

**Context:** A quantity shortfall is advisory by default (line proceeds READY at
ordered qty). To reach the `AWAITING_RECONFIRM` reconfirm path this scenario sets
`block_on_qty_short = 1` in `PricingConfig`, then procures with stock < qty. The
`approve` (accept-as-is) branch now sets `qty = procured_qty` and re-totals, so
billed equals produced.

- [ ] **Step 1: Write the scenario test**

Create `tests/Harness/Scenarios/AcceptAsIsRetotalsTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\LineItem;
use App\Models\PricingConfig;
use App\Models\Product;
use App\Models\Quote;
use App\Models\User;
use App\Models\Variant;
use App\Services\Procurement\ProcurementManager;
use Tests\Harness\Agents\ProductionAgent;
use Tests\Harness\Agents\ValidatorAgent;
use Tests\Harness\Support\HarnessContext;

beforeEach(function (): void {
    seedPricing();
    $this->company = Company::factory()->create();
    $this->buyer = User::factory()->create(['company_id' => $this->company->id, 'role' => 'buyer']);
    $this->staff = User::factory()->staffAdmin()->create();
    $this->product = Product::factory()->create(['class' => 'CORE', 'print_method' => 'UV']);
    $this->ctx = new HarnessContext($this, $this->staff, $this->buyer);

    // Opt this tenant back into blocking on a shortfall so the line lands in
    // AWAITING_RECONFIRM (the path the accept-as-is decision resolves).
    PricingConfig::updateOrCreate(
        ['group' => 'procurement', 'key' => 'block_on_qty_short'],
        ['value' => 1],
    );
});

it('accept-as-is bills only what is produced (blocker 3 regression)', function (): void {
    $production = new ProductionAgent($this->ctx);
    $validator = new ValidatorAgent($this->ctx);

    // Stock 2, ordered 5 -> shortfall -> AWAITING_RECONFIRM.
    $variant = Variant::factory()->create(['product_id' => $this->product->id, 'stock_on_hand' => 2]);
    $quote = Quote::factory()->create(['company_id' => $this->company->id, 'state' => 'PROCURING']);
    $line = LineItem::factory()->create([
        'quote_id' => $quote->id,
        'product_id' => $this->product->id,
        'variant_id' => $variant->id,
        'qty' => 5,
        'unit_price' => 15.00,
        'line_state' => 'PENDING',
    ]);
    $this->ctx->quote = $quote;

    app(ProcurementManager::class)->procureLine($line->load('product', 'variant'));
    expect($line->fresh()->line_state->value)->toBe('AWAITING_RECONFIRM');

    // Staff accept what could be sourced.
    $production->reconfirm($line->fresh(), 'approve');

    // Billed qty now equals produced qty; the invariant holds.
    $fresh = $line->fresh();
    expect((int) $fresh->qty)->toBe((int) $fresh->procured_qty);

    $validator->check('INVOICE_MATCHES_PRODUCED');
    expect($validator->violations())->toBeEmpty(
        implode("\n", array_map('strval', $validator->violations())),
    );
});
```

- [ ] **Step 2: Run it**

Run: `vendor/bin/pest tests/Harness/Scenarios/AcceptAsIsRetotalsTest.php`
Expected: PASS. If the line does not reach `AWAITING_RECONFIRM`, confirm the `PricingConfig` group/key by reading `tests/Feature/ProcurementTest.php` ("blocks on a shortfall again when block_on_qty_short is set").

- [ ] **Step 3: Commit**

```bash
git add tests/Harness/Scenarios/AcceptAsIsRetotalsTest.php
git commit -m "test(harness): accept-as-is re-totals regression (blocker 3)"
```

---

### Task 10: Scenario 4 — 3D filament return on cancel (Blocker 4, status unconfirmed)

**Files:**
- Create: `tests/Harness/Scenarios/Cancel3dFilamentReturnTest.php`

**Context:** This is the one blocker whose current status is unknown. The build
step below reads the 3D procurement + return code, then the scenario asserts the
observed reality. The generic `LEDGER_BALANCED_AFTER_CANCEL` check cannot see a
direct-column filament decrement, so the filament assertion is scenario-local on
the filament source of truth.

- [ ] **Step 1: Read the 3D procurement + return code to settle the assertion**

Read these and note exactly how a MODEL_3D line consumes and (if at all) returns
filament on cancel:
- `app/Models/Model3dProcurement.php` (how filament is decremented — column write vs ledger).
- `app/Services/QuoteService.php` `returnConsumedStock()` (lines ~834-860) — it skips `variant === null` lines.
- `tests/Feature/Model3dProcurementTest.php` — how existing tests set up a 3D line and read filament (`qty_on_hand`).

Record the filament holder (model + column) you will assert on. Below, the
placeholder `$filamentBefore`/`$filamentAfter` reads must be replaced with the
real accessor found here (e.g. a `Filament`/spool model's `qty_on_hand`).

- [ ] **Step 2: Write the scenario test (assert observed reality)**

Create `tests/Harness/Scenarios/Cancel3dFilamentReturnTest.php`, modelling the 3D
line setup on `tests/Feature/Model3dProcurementTest.php`. Fill the two filament
reads from Step 1:

```php
<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Quote;
use App\Models\User;
use Tests\Harness\Agents\StaffAgent;
use Tests\Harness\Agents\ValidatorAgent;
use Tests\Harness\Support\HarnessContext;

beforeEach(function (): void {
    seedPricing();
    $this->company = Company::factory()->create();
    $this->buyer = User::factory()->create(['company_id' => $this->company->id, 'role' => 'buyer']);
    $this->staff = User::factory()->staffAdmin()->create();
    $this->ctx = new HarnessContext($this, $this->staff, $this->buyer);
});

it('returns (or documents the loss of) 3D filament when a procured order is cancelled', function (): void {
    $staff = new StaffAgent($this->ctx);
    $validator = new ValidatorAgent($this->ctx);

    // --- Arrange: a MODEL_3D line procured (filament consumed), quote PROCURING.
    //     Build this using the exact factory/model setup from
    //     tests/Feature/Model3dProcurementTest.php (a MODEL_3D product, a 3D
    //     procurement row, filament decremented).
    // $quote = ...;  $this->ctx->quote = $quote;
    // $filamentBefore = <filament holder>->fresh()-><qty column>;

    // --- Act: staff cancel the order.
    // $staff->cancel('customer withdrew');

    // --- Assert observed reality:
    // $filamentAfter = <filament holder>->fresh()-><qty column>;
    //
    // If the app returns filament on cancel (blocker fixed):
    //   expect($filamentAfter)->toBe($filamentBefore);   // fully restored
    //   $validator->check('LEDGER_BALANCED_AFTER_CANCEL');
    //   expect($validator->violations())->toBeEmpty();
    //
    // If filament is still lost (blocker live), assert the loss explicitly and
    // annotate it so the test documents the gap rather than hiding it:
    //   expect($filamentAfter)->toBeLessThan($filamentBefore);
    //   // TODO(blocker-4): 3D cancel does not restore filament — see
    //   //   QuoteService::returnConsumedStock skipping variant===null lines.

    // Replace the commented scaffold above with the real setup + whichever
    // branch matches Step 1's findings. Do not leave both branches in.
})->skip('Fill in after Step 1 settles the 3D filament source of truth.');
```

- [ ] **Step 3: Remove the `->skip()` once implemented and run it**

Run: `vendor/bin/pest tests/Harness/Scenarios/Cancel3dFilamentReturnTest.php`
Expected: PASS, asserting whichever reality Step 1 established. The test must make
a real assertion — the `skip` exists only so an incomplete scaffold never commits
green.

- [ ] **Step 4: Commit**

```bash
git add tests/Harness/Scenarios/Cancel3dFilamentReturnTest.php
git commit -m "test(harness): 3D filament-on-cancel scenario (blocker 4 status)"
```

---

### Task 11: Scenario 5 — Silent buyer chase

**Files:**
- Create: `tests/Harness/Scenarios/SilentBuyerChaseTest.php`

- [ ] **Step 1: Write the scenario test**

Buyer goes silent on a SENT quote; the nightly chase command nudges it. Mirrors
`tests/Feature/ChaseUnansweredOrdersTest.php` (a SENT quote with
`price_snapshot_at` 3 days ago crosses the first rung), driven through the
harness buyer.

Create `tests/Harness/Scenarios/SilentBuyerChaseTest.php`:

```php
<?php

declare(strict_types=1);

use App\Mail\OrderMilestoneMail;
use App\Models\Company;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\Harness\Agents\BuyerAgent;
use Tests\Harness\Support\HarnessContext;

beforeEach(function (): void {
    Mail::fake();
    $this->company = Company::factory()->create();
    $this->buyer = User::factory()->create(['company_id' => $this->company->id, 'role' => 'buyer']);
    $this->staff = User::factory()->staffAdmin()->create();
    $this->ctx = new HarnessContext($this, $this->staff, $this->buyer);
});

it('chases an order the buyer went silent on', function (): void {
    // A SENT quote waiting 3 days (past the first chase rung), created by the buyer.
    $quote = Quote::factory()->create([
        'company_id' => $this->company->id,
        'state' => 'SENT',
        'created_by' => $this->buyer->id,
        'price_snapshot_at' => now()->subDays(3),
    ]);
    $this->ctx->quote = $quote;

    // Buyer does nothing.
    (new BuyerAgent($this->ctx))->goSilent();

    // The nightly chase runs and nudges the un-actioned order exactly once.
    $this->artisan('quotes:chase')->assertSuccessful();

    expect($quote->fresh()->reminders_sent)->toBe(1);
    Mail::assertQueued(OrderMilestoneMail::class);
});
```

- [ ] **Step 2: Run it**

Run: `vendor/bin/pest tests/Harness/Scenarios/SilentBuyerChaseTest.php`
Expected: PASS. If no mail queues, confirm the chase threshold/day count against `tests/Feature/ChaseUnansweredOrdersTest.php` and adjust `subDays(3)` to cross the first rung.

- [ ] **Step 3: Run the whole harness + full suite**

Run: `vendor/bin/pest tests/Harness tests/Unit/Harness`
Expected: PASS (all scenarios + unit tests).

Run: `vendor/bin/pest`
Expected: the pre-existing suite still passes (the harness adds tests, changes no app code).

- [ ] **Step 4: Commit**

```bash
git add tests/Harness/Scenarios/SilentBuyerChaseTest.php
git commit -m "test(harness): silent-buyer chase scenario"
```

---

## Coverage Map (plan vs spec)

| Spec item | Task |
|---|---|
| `StaffAgent` | 4 |
| `BuyerAgent` | 5 |
| `ProductionAgent` | 6 |
| `ValidatorAgent` + `Violation` + `HarnessContext` | 1, 2, 3 |
| Pest `Harness` binding | 1 |
| Invariant `ILLEGAL_TRANSITION` | 3 |
| Invariant `INVOICE_MATCHES_PRODUCED` | 3 (used in 7, 9) |
| Invariant `LEDGER_BALANCED_AFTER_CANCEL` | 3 (used in 10) |
| Per-line file present (scenario form) | 7 |
| No-stuck-order (scenario form) | 8 |
| Scenario 1 Happy path | 7 |
| Scenario 2 Changes-requested recovers | 8 |
| Scenario 3 Accept-as-is re-totals | 9 |
| Scenario 4 3D filament on cancel | 10 |
| Scenario 5 Silent buyer chase | 11 |
| Bounded proof feedback loop | 8 (single revise round; `MAX_PROOF_ROUNDS` not needed — scenarios use fixed rounds) |

**Note on `MAX_PROOF_ROUNDS`:** the spec mentions a bounded loop constant. The
five scenarios each use a fixed, small number of proof rounds, so no runtime loop
cap is required. If a future scenario drives an open-ended reject loop, add the
cap then (YAGNI now).
