# Per-Line-Item Proofs Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give every customized line item its own proof lineage — reviewed and approved independently by the buyer, staged and sent per round by staff — so a multi-item order can carry different artwork per item and never freezes on one lagging item.

**Architecture:** `proofs` gains `line_item_id` and a `DRAFT` staged state. Staff attach artwork per line (`DRAFT`), then one "send" flips the round to `SENT` and emails once. The buyer approves/requests-changes per line. A single `Quote::recomputeProofState()` rolls the per-line proof states up to the order state (advances when every artwork line is approved-or-dropped). Production builds one batched UV job carrying each approved line's file (3D unchanged).

**Tech Stack:** Laravel 12 (Pest), React + Zustand + Vitest, MySQL, Reverb broadcasts, S3/Spaces artwork disk.

**Reference spec:** `docs/superpowers/specs/2026-07-24-per-line-item-proofs-design.md`

**Pre-launch:** no live proof data — migrations reseed, no legacy path.

---

## Phase 0 — Schema & enum foundations

### Task 0.1: `ProofState` gains `DRAFT`

**Files:**
- Modify: `app/Enums/ProofState.php`
- Test: `tests/Unit/ProofStateTest.php` (create)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\ProofState;

it('lets a draft be sent and a sent proof be decided', function (): void {
    expect(ProofState::Draft->canTransitionTo(ProofState::Sent))->toBeTrue()
        ->and(ProofState::Sent->canTransitionTo(ProofState::Approved))->toBeTrue()
        ->and(ProofState::Sent->canTransitionTo(ProofState::ChangesRequested))->toBeTrue();
});

it('never sends a draft straight to approved', function (): void {
    expect(ProofState::Draft->canTransitionTo(ProofState::Approved))->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/ProofStateTest.php`
Expected: FAIL — `ProofState::Draft` undefined.

- [ ] **Step 3: Add the `Draft` case + transitions**

In `app/Enums/ProofState.php` add the case and wire `nextStates()`:

```php
enum ProofState: string
{
    case Draft = 'DRAFT';
    case Sent = 'SENT';
    case ChangesRequested = 'CHANGES_REQUESTED';
    case Approved = 'APPROVED';

    /**
     * @return array<int, self>
     */
    public function nextStates(): array
    {
        return match ($this) {
            self::Draft => [self::Sent],
            self::Sent => [self::ChangesRequested, self::Approved],
            self::ChangesRequested => [],
            self::Approved => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->nextStates(), true);
    }

    public function isApproved(): bool
    {
        return $this === self::Approved;
    }

    /** True while the buyer still owes a decision on this proof. */
    public function isAwaitingBuyer(): bool
    {
        return $this === self::Sent;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/ProofStateTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Enums/ProofState.php tests/Unit/ProofStateTest.php
git commit -m "feat(proofs): add DRAFT proof state"
```

### Task 0.2: proofs table — `line_item_id`, per-line uniqueness (reseed)

**Files:**
- Modify: `database/migrations/2026_07_01_000012_create_proofs_table.php`

- [ ] **Step 1: Edit the migration** (no separate test — schema verified by later feature tests)

Replace the `quote_id`/unique block so proofs belong to a line:

```php
$table->foreignId('quote_id')->constrained('quotes')->cascadeOnDelete();
// One proof lineage PER LINE ITEM: each customized line has its own
// versions and its own approval, independent of every other line.
$table->foreignId('line_item_id')->constrained('line_items')->cascadeOnDelete();
$table->unsignedInteger('version')->default(1);
$table->string('artwork_version_ref')->comment('object-store key; = production print file when approved');
$table->enum('state', ['DRAFT', 'SENT', 'CHANGES_REQUESTED', 'APPROVED'])->default('DRAFT');
```

And the indexes/unique:

```php
$table->index('quote_id');
$table->index('line_item_id');
$table->index('state');
$table->index(['quote_id', 'state']);
// Versions run per line, not per order.
$table->unique(['line_item_id', 'version']);
```

- [ ] **Step 2: Re-migrate fresh**

Run: `php artisan migrate:fresh --seed`
Expected: migrates clean (seeder is updated in Phase 9; if it errors on proofs, proceed — Task 9.1 fixes the seeder, and until then use `migrate:fresh` without `--seed`).

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_07_01_000012_create_proofs_table.php
git commit -m "feat(proofs): proofs belong to a line item, versions per line"
```

### Task 0.3: `Proof` model — fillable + `lineItem` relation

**Files:**
- Modify: `app/Models/Proof.php`
- Test: `tests/Feature/ProofModelTest.php` (create)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Proof;
use App\Models\Quote;

it('belongs to a line item', function (): void {
    $company = Company::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $company->id]);
    $product = Product::factory()->create();
    $line = LineItem::factory()->create(['quote_id' => $quote->id, 'product_id' => $product->id]);
    $proof = Proof::factory()->create(['quote_id' => $quote->id, 'line_item_id' => $line->id]);

    expect($proof->lineItem->id)->toBe($line->id);
});
```

- [ ] **Step 2: Run test — expect FAIL** (`line_item_id` not fillable / relation missing)

Run: `php artisan test tests/Feature/ProofModelTest.php`

- [ ] **Step 3: Add `line_item_id` to `$fillable` and the relation**

Add `'line_item_id'` to the `$fillable` array in `app/Models/Proof.php`, and add:

```php
public function lineItem(): \Illuminate\Database\Eloquent\Relations\BelongsTo
{
    return $this->belongsTo(LineItem::class);
}
```

(Import `use App\Models\LineItem;` if not present.)

- [ ] **Step 4: Update `ProofFactory`**

In `database/factories/ProofFactory.php`, ensure a `line_item_id`. Add to the definition:

```php
'line_item_id' => LineItem::factory(),
'state' => \App\Enums\ProofState::Draft->value,
```

(Import `use App\Models\LineItem;`.) If existing callers pass `quote_id` only, keep `quote_id => Quote::factory()` as-is; tests that need line+quote coherence pass both explicitly.

- [ ] **Step 5: Run test — expect PASS**

Run: `php artisan test tests/Feature/ProofModelTest.php`

- [ ] **Step 6: Commit**

```bash
git add app/Models/Proof.php database/factories/ProofFactory.php tests/Feature/ProofModelTest.php
git commit -m "feat(proofs): Proof belongs to a line item"
```

### Task 0.4: `LineItem::needsProof()`

**Files:**
- Modify: `app/Models/LineItem.php`
- Test: `tests/Feature/LineItemNeedsProofTest.php` (create)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\LineItem;

it('needs a proof for a customized line', function (): void {
    $line = new LineItem(['customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/x.png']]);
    expect($line->needsProof())->toBeTrue();
});

it('needs a proof for a buyer-uploaded finished-look line', function (): void {
    $line = new LineItem(['customization' => ['mode' => 'buyer_uploaded', 'artwork_ref' => 'artwork/y.png']]);
    expect($line->needsProof())->toBeTrue();
});

it('needs no proof for a plain stock line', function (): void {
    expect((new LineItem(['customization' => null]))->needsProof())->toBeFalse();
    expect((new LineItem(['customization' => []]))->needsProof())->toBeFalse();
});
```

- [ ] **Step 2: Run test — expect FAIL** (method missing)

Run: `php artisan test tests/Feature/LineItemNeedsProofTest.php`

- [ ] **Step 3: Implement `needsProof()`**

Add to `app/Models/LineItem.php`:

```php
/**
 * Whether this line carries artwork that must be signed off before print.
 * True for any customization (designer OR buyer-uploaded finished-look);
 * false for plain stock lines, which never proof.
 */
public function needsProof(): bool
{
    $customization = $this->customization ?? [];

    return is_array($customization)
        && ($customization['mode'] ?? null) !== null
        && $customization !== [];
}
```

- [ ] **Step 4: Run test — expect PASS**

Run: `php artisan test tests/Feature/LineItemNeedsProofTest.php`

- [ ] **Step 5: Commit**

```bash
git add app/Models/LineItem.php tests/Feature/LineItemNeedsProofTest.php
git commit -m "feat(proofs): LineItem::needsProof()"
```

### Task 0.5: `production_jobs.artwork_refs` list (reseed)

**Files:**
- Modify: `database/migrations/2026_07_01_000010_create_production_jobs_table.php`
- Modify: `app/Models/ProductionJob.php`

- [ ] **Step 1: Edit the migration** — replace the single `artwork_ref` column:

```php
// A job prints one file per artwork line it covers. Each entry:
// { line_item_id, product_name, ref }. A 3D job has one; a batched UV
// job has one per approved UV line.
$table->json('artwork_refs')->nullable()->comment('print-ready files: [{line_item_id, product_name, ref}]');
```

- [ ] **Step 2: Cast it on the model**

In `app/Models/ProductionJob.php` add `'artwork_refs' => 'array'` to the `casts()` array, and add `'artwork_refs'` to `$fillable`. Remove any `'artwork_ref'` fillable/cast entry.

- [ ] **Step 3: Re-migrate**

Run: `php artisan migrate:fresh`
Expected: clean.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_07_01_000010_create_production_jobs_table.php app/Models/ProductionJob.php
git commit -m "feat(production): job carries a list of print files"
```

---

## Phase 1 — Order-state aggregation gate

### Task 1.1: `Quote::recomputeProofState()`

**Files:**
- Modify: `app/Models/Quote.php`
- Test: `tests/Feature/RecomputeProofStateTest.php` (create)

**Behaviour (from spec §Domain rules):** consider only artwork lines that are not dropped. Precedence: (1) any such line has an open `SENT` proof OR is not yet prepared (no non-terminal proof) → `PROOFING`; (2) else any such line's latest proof is `CHANGES_REQUESTED` → `CHANGES_REQUESTED`; (3) else every artwork line is `APPROVED` → artwork-approved outcome branching on `accepted_at`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\ProofState;
use App\Enums\QuoteState;
use App\Models\Company;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Proof;
use App\Models\Quote;

function customizedLine(Quote $quote): LineItem
{
    return LineItem::factory()->create([
        'quote_id' => $quote->id,
        'product_id' => Product::factory()->create()->id,
        'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/a.png'],
        'line_state' => 'PENDING',
    ]);
}

function proofFor(LineItem $line, ProofState $state, int $version = 1): Proof
{
    return Proof::factory()->create([
        'quote_id' => $line->quote_id,
        'line_item_id' => $line->id,
        'version' => $version,
        'state' => $state->value,
    ]);
}

it('stays in proofing while any artwork line still awaits the buyer', function (): void {
    $quote = Quote::factory()->create(['state' => 'PROOFING']);
    $a = customizedLine($quote);
    $b = customizedLine($quote);
    proofFor($a, ProofState::Approved);
    proofFor($b, ProofState::Sent);

    $quote->recomputeProofState();

    expect($quote->fresh()->state)->toBe(QuoteState::Proofing);
});

it('reports changes-requested when nothing awaits the buyer but a line needs revision', function (): void {
    $quote = Quote::factory()->create(['state' => 'PROOFING']);
    $a = customizedLine($quote);
    $b = customizedLine($quote);
    proofFor($a, ProofState::Approved);
    proofFor($b, ProofState::ChangesRequested);

    $quote->recomputeProofState();

    expect($quote->fresh()->state)->toBe(QuoteState::ChangesRequested);
});

it('advances to ARTWORK_APPROVED once every artwork line is approved (price not yet accepted)', function (): void {
    $quote = Quote::factory()->create(['state' => 'PROOFING', 'accepted_at' => null]);
    proofFor(customizedLine($quote), ProofState::Approved);
    proofFor(customizedLine($quote), ProofState::Approved);

    $quote->recomputeProofState();

    expect($quote->fresh()->state)->toBe(QuoteState::ArtworkApproved);
});

it('advances to PROOF_APPROVED once every artwork line is approved and the price was accepted', function (): void {
    $quote = Quote::factory()->create(['state' => 'PROOFING', 'accepted_at' => now()]);
    proofFor(customizedLine($quote), ProofState::Approved);

    $quote->recomputeProofState();

    expect($quote->fresh()->state)->toBe(QuoteState::ProofApproved);
});

it('excludes dropped lines from the gate', function (): void {
    $quote = Quote::factory()->create(['state' => 'PROOFING', 'accepted_at' => null]);
    proofFor(customizedLine($quote), ProofState::Approved);
    $dropped = customizedLine($quote);
    $dropped->update(['line_state' => 'DROPPED']);
    proofFor($dropped, ProofState::ChangesRequested);

    $quote->recomputeProofState();

    expect($quote->fresh()->state)->toBe(QuoteState::ArtworkApproved);
});

it('stays in proofing when a customized line has no proof prepared yet', function (): void {
    $quote = Quote::factory()->create(['state' => 'PROOFING']);
    proofFor(customizedLine($quote), ProofState::Approved);
    customizedLine($quote); // no proof staged

    $quote->recomputeProofState();

    expect($quote->fresh()->state)->toBe(QuoteState::Proofing);
});
```

- [ ] **Step 2: Run test — expect FAIL** (method missing)

Run: `php artisan test tests/Feature/RecomputeProofStateTest.php`

- [ ] **Step 3: Implement `recomputeProofState()`**

Add to `app/Models/Quote.php` (imports: `App\Enums\ProofState`, `App\Enums\QuoteState`, `App\Enums\LineItemState`):

```php
/**
 * Roll the per-line proof states up to the order state. Only artwork lines
 * that are not dropped count. Called after every per-line proof decision,
 * every line drop, and every amend touching an artwork line.
 *
 * Precedence:
 *  1. Any counting line awaits the buyer (open SENT proof) OR is not yet
 *     prepared (no DRAFT/SENT/CHANGES/APPROVED proof) -> PROOFING.
 *  2. Else any counting line's latest proof is CHANGES_REQUESTED -> CHANGES_REQUESTED.
 *  3. Else every counting line is APPROVED -> ARTWORK_APPROVED (accepted_at null)
 *     or PROOF_APPROVED (price already accepted).
 * A quote with NO artwork lines is left untouched (plain-quote path).
 */
public function recomputeProofState(): void
{
    $this->loadMissing(['lineItems', 'proofs']);

    $countingLines = $this->lineItems->filter(
        fn (LineItem $line): bool => $line->needsProof() && $line->line_state !== LineItemState::Dropped
    );

    if ($countingLines->isEmpty()) {
        return;
    }

    $latestByLine = $this->proofs
        ->groupBy('line_item_id')
        ->map(fn ($group) => $group->sortByDesc('version')->first());

    $anyAwaitingOrUnprepared = false;
    $anyChanges = false;
    $allApproved = true;

    foreach ($countingLines as $line) {
        $proof = $latestByLine->get($line->id);

        if ($proof === null || $proof->state === ProofState::Draft) {
            $anyAwaitingOrUnprepared = true;
            $allApproved = false;
            continue;
        }
        if ($proof->state === ProofState::Sent) {
            $anyAwaitingOrUnprepared = true;
            $allApproved = false;
        } elseif ($proof->state === ProofState::ChangesRequested) {
            $anyChanges = true;
            $allApproved = false;
        }
    }

    $target = match (true) {
        $anyAwaitingOrUnprepared => QuoteState::Proofing,
        $anyChanges => QuoteState::ChangesRequested,
        $allApproved => $this->accepted_at === null
            ? QuoteState::ArtworkApproved
            : QuoteState::ProofApproved,
        default => null,
    };

    if ($target === null || $this->state === $target) {
        return;
    }

    // Only move along an edge the state machine actually allows; a recompute
    // that lands on an illegal edge (e.g. from a terminal state) is a no-op
    // rather than a thrown request.
    if (! $this->state->canTransitionTo($target)) {
        return;
    }

    $previous = $this->state->value;
    $this->transitionTo($target);
    \Illuminate\Support\Facades\DB::afterCommit(
        fn () => \App\Support\Broadcasting::dispatch(
            fn () => \App\Events\QuoteStateChanged::dispatch($this, $previous)
        )
    );
}
```

- [ ] **Step 4: Verify QuoteState transitions allow these edges**

Read `app/Enums/QuoteState.php`. Confirm `Proofing → ArtworkApproved`, `Proofing → ProofApproved`, `Proofing → ChangesRequested`, `ChangesRequested → Proofing`, and `ChangesRequested → ArtworkApproved/ProofApproved` are permitted `nextStates()`. If any needed edge is missing (notably `ChangesRequested → ArtworkApproved`, `Proofing → ChangesRequested`), add it, with a one-line comment explaining the per-line rollup needs it. Re-run the test after each edit.

- [ ] **Step 5: Run test — expect PASS**

Run: `php artisan test tests/Feature/RecomputeProofStateTest.php`

- [ ] **Step 6: Commit**

```bash
git add app/Models/Quote.php app/Enums/QuoteState.php tests/Feature/RecomputeProofStateTest.php
git commit -m "feat(proofs): Quote::recomputeProofState aggregates per-line proofs"
```

### Task 1.2: Rework `approveProof` / `requestProofChanges` to delegate to the gate

**Files:**
- Modify: `app/Services/QuoteService.php:642-716`
- Test: `tests/Feature/PerLineProofDecisionTest.php` (create)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\ProofState;
use App\Enums\QuoteState;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Proof;
use App\Models\Quote;
use App\Services\QuoteService;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    Mail::fake();
    $this->svc = app(QuoteService::class);
});

function artworkLine(Quote $quote): LineItem
{
    return LineItem::factory()->create([
        'quote_id' => $quote->id,
        'product_id' => Product::factory()->create()->id,
        'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/a.png'],
        'line_state' => 'PENDING',
    ]);
}

it('approving one line proof leaves the order in proofing while another is open', function (): void {
    $quote = Quote::factory()->create(['state' => 'PROOFING', 'accepted_at' => null]);
    $pa = Proof::factory()->create(['quote_id' => $quote->id, 'line_item_id' => artworkLine($quote)->id, 'state' => 'SENT']);
    Proof::factory()->create(['quote_id' => $quote->id, 'line_item_id' => artworkLine($quote)->id, 'state' => 'SENT']);

    $this->svc->approveProof($pa->fresh());

    expect($pa->fresh()->state)->toBe(ProofState::Approved)
        ->and($quote->fresh()->state)->toBe(QuoteState::Proofing);
});

it('approving the last open line proof advances the order to artwork-approved', function (): void {
    $quote = Quote::factory()->create(['state' => 'PROOFING', 'accepted_at' => null]);
    $only = Proof::factory()->create(['quote_id' => $quote->id, 'line_item_id' => artworkLine($quote)->id, 'state' => 'SENT']);

    $this->svc->approveProof($only->fresh());

    expect($quote->fresh()->state)->toBe(QuoteState::ArtworkApproved);
});

it('requesting changes on one line does not drag an approved sibling backwards', function (): void {
    $quote = Quote::factory()->create(['state' => 'PROOFING', 'accepted_at' => null]);
    Proof::factory()->create(['quote_id' => $quote->id, 'line_item_id' => artworkLine($quote)->id, 'state' => 'APPROVED']);
    $b = Proof::factory()->create(['quote_id' => $quote->id, 'line_item_id' => artworkLine($quote)->id, 'state' => 'SENT']);

    $this->svc->requestProofChanges($b->fresh(), 'fix logo', []);

    expect($quote->fresh()->state)->toBe(QuoteState::ChangesRequested);
});
```

- [ ] **Step 2: Run test — expect FAIL** (old logic transitions the whole quote off one proof)

Run: `php artisan test tests/Feature/PerLineProofDecisionTest.php`

- [ ] **Step 3: Rewrite the two methods to delegate**

In `approveProof()` (`QuoteService.php:642`), replace the block that transitions the quote (`$quote->transitionTo(... ArtworkApproved/ProofApproved ...)` and its broadcast) with a single call:

```php
$quote = $proof->quote;
$quote->recomputeProofState();

DB::afterCommit(fn () => Broadcasting::dispatch(fn () => ProofStatusChanged::dispatch($proof, $quote->company_id)));

return $proof;
```

In `requestProofChanges()` (`QuoteService.php:686`), replace the `accepted_at === null && state === Proofing` transition block with:

```php
$quote = $proof->quote;
$quote->recomputeProofState();

DB::afterCommit(fn () => Broadcasting::dispatch(fn () => ProofStatusChanged::dispatch($proof, $quote->company_id)));
DB::afterCommit(fn () => $this->staffNotifier->proofChangesRequested($proof));

return $proof;
```

- [ ] **Step 4: Run test — expect PASS**

Run: `php artisan test tests/Feature/PerLineProofDecisionTest.php`

- [ ] **Step 5: Run existing proof suites, fix fallout**

Run: `php artisan test --filter="Proof|Quote"`
Existing tests that seeded one order-level proof now need a `line_item_id`. Update those factories/fixtures to attach a proof to a customized line (add a customized `LineItem` + `line_item_id`). Fix until green.

- [ ] **Step 6: Commit**

```bash
git add app/Services/QuoteService.php tests/Feature/PerLineProofDecisionTest.php tests/
git commit -m "feat(proofs): per-line decisions roll up through recomputeProofState"
```

---

## Phase 2 — Staff staging & batched send

### Task 2.1: `QuoteService::stageProof()` (create/replace a line's DRAFT)

**Files:**
- Modify: `app/Services/QuoteService.php`
- Test: `tests/Feature/StageProofTest.php` (create)

**Rule:** stage artwork for one line → create the next version in `DRAFT` (buyer not emailed). If the line already has an open `DRAFT` (not yet sent), replace its artwork rather than spawning versions. The order does NOT change state on staging.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\ProofState;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Quote;
use App\Services\QuoteService;
use Illuminate\Support\Facades\Mail;

beforeEach(fn () => Mail::fake());

function line(Quote $q): LineItem
{
    return LineItem::factory()->create([
        'quote_id' => $q->id, 'product_id' => Product::factory()->create()->id,
        'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/a.png'], 'line_state' => 'PENDING',
    ]);
}

it('stages a draft proof for a line and emails nobody', function (): void {
    $quote = Quote::factory()->create(['state' => 'ACCEPTED']);
    $line = line($quote);

    $proof = app(QuoteService::class)->stageProof($quote, $line, 'artwork/v1.png');

    expect($proof->state)->toBe(ProofState::Draft)
        ->and($proof->line_item_id)->toBe($line->id)
        ->and($proof->version)->toBe(1);
    Mail::assertNothingQueued();
});

it('replaces the artwork on a still-staged draft instead of versioning up', function (): void {
    $quote = Quote::factory()->create(['state' => 'ACCEPTED']);
    $line = line($quote);
    $svc = app(QuoteService::class);

    $svc->stageProof($quote, $line, 'artwork/v1.png');
    $second = $svc->stageProof($quote, $line, 'artwork/v2.png');

    expect($second->version)->toBe(1)
        ->and($second->artwork_version_ref)->toBe('artwork/v2.png')
        ->and($line->proofs()->count())->toBe(1);
});
```

Note: needs a `Proof lineItem` hasMany on `LineItem`. Add in Task 2.1 Step 3.

- [ ] **Step 2: Run — expect FAIL**

Run: `php artisan test tests/Feature/StageProofTest.php`

- [ ] **Step 3: Add `LineItem::proofs()` + `stageProof()`**

`app/Models/LineItem.php`:

```php
public function proofs(): \Illuminate\Database\Eloquent\Relations\HasMany
{
    return $this->hasMany(Proof::class);
}
```

`app/Services/QuoteService.php`:

```php
/**
 * Stage artwork for one line as a DRAFT proof (buyer not yet emailed). If the
 * line already holds an unsent DRAFT, its artwork is replaced rather than
 * bumping the version - re-picking a file before sending is not a revision.
 */
public function stageProof(Quote $quote, LineItem $line, string $artworkRef): Proof
{
    if (! $line->needsProof()) {
        throw new DomainRuleException('This line does not take a proof.');
    }

    return DB::transaction(function () use ($quote, $line, $artworkRef): Proof {
        $openDraft = $line->proofs()
            ->where('state', ProofState::Draft->value)
            ->orderByDesc('version')
            ->first();

        if ($openDraft !== null) {
            $openDraft->artwork_version_ref = $artworkRef;
            $openDraft->save();
            $proof = $openDraft;
        } else {
            $nextVersion = ((int) $line->proofs()->max('version')) + 1;
            $proof = Proof::create([
                'quote_id' => $quote->id,
                'line_item_id' => $line->id,
                'version' => $nextVersion,
                'artwork_version_ref' => $artworkRef,
                'state' => ProofState::Draft->value,
            ]);
        }

        DB::afterCommit(fn () => Broadcasting::dispatch(fn () => ProofStatusChanged::dispatch($proof, $quote->company_id)));

        return $proof;
    });
}
```

(Imports: `App\Models\LineItem` at top of `QuoteService`.)

- [ ] **Step 4: Run — expect PASS**

Run: `php artisan test tests/Feature/StageProofTest.php`

- [ ] **Step 5: Commit**

```bash
git add app/Services/QuoteService.php app/Models/LineItem.php tests/Feature/StageProofTest.php
git commit -m "feat(proofs): stage per-line DRAFT proofs"
```

### Task 2.2: `QuoteService::sendProofs()` (one batched email)

**Files:**
- Modify: `app/Services/QuoteService.php`
- Test: `tests/Feature/SendProofsTest.php` (create)

**Rule:** flip every `DRAFT` proof on the order → `SENT`, move the order into `PROOFING` (from ACCEPTED/CHANGES_REQUESTED/DRAFT), and send exactly ONE `QuoteReadyMail` listing the round's items. Errors if no DRAFT proofs.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\ProofState;
use App\Enums\QuoteState;
use App\Mail\QuoteReadyMail;
use App\Models\Company;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Proof;
use App\Models\Quote;
use App\Models\User;
use App\Services\QuoteService;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    Mail::fake();
    $this->company = Company::factory()->create();
    $this->buyer = User::factory()->create(['company_id' => $this->company->id, 'role' => 'buyer']);
});

function draftFor(Quote $q, string $ref): Proof
{
    $line = LineItem::factory()->create([
        'quote_id' => $q->id, 'product_id' => Product::factory()->create()->id,
        'customization' => ['mode' => 'designer', 'artwork_ref' => $ref], 'line_state' => 'PENDING',
    ]);

    return Proof::factory()->create(['quote_id' => $q->id, 'line_item_id' => $line->id, 'artwork_version_ref' => $ref, 'state' => 'DRAFT']);
}

it('sends every staged proof in one email and moves the order to proofing', function (): void {
    $quote = Quote::factory()->create(['company_id' => $this->company->id, 'created_by' => $this->buyer->id, 'state' => 'ACCEPTED']);
    draftFor($quote, 'artwork/a.png');
    draftFor($quote, 'artwork/b.png');

    app(QuoteService::class)->sendProofs($quote);

    expect($quote->fresh()->state)->toBe(QuoteState::Proofing)
        ->and($quote->proofs()->where('state', ProofState::Sent->value)->count())->toBe(2);
    Mail::assertQueued(QuoteReadyMail::class, 1);
});

it('refuses to send when nothing is staged', function (): void {
    $quote = Quote::factory()->create(['state' => 'ACCEPTED']);

    expect(fn () => app(QuoteService::class)->sendProofs($quote))
        ->toThrow(\App\Exceptions\DomainRuleException::class);
});
```

- [ ] **Step 2: Run — expect FAIL**

Run: `php artisan test tests/Feature/SendProofsTest.php`

- [ ] **Step 3: Implement `sendProofs()`**

```php
/**
 * Send the current round: flip every staged DRAFT proof to SENT, move the
 * order into PROOFING, and email the buyer ONCE with the round's items.
 */
public function sendProofs(Quote $quote): Quote
{
    $drafts = $quote->proofs()->where('state', ProofState::Draft->value)->get();

    if ($drafts->isEmpty()) {
        throw new DomainRuleException('Nothing is staged to send.');
    }

    return DB::transaction(function () use ($quote, $drafts): Quote {
        foreach ($drafts as $draft) {
            $draft->transitionTo(ProofState::Sent);
            DB::afterCommit(fn () => Broadcasting::dispatch(fn () => ProofStatusChanged::dispatch($draft, $quote->company_id)));
        }

        if (in_array($quote->state, [QuoteState::Accepted, QuoteState::ChangesRequested, QuoteState::Draft], true)) {
            $previous = $quote->state->value;
            if ($quote->state === QuoteState::Draft) {
                $quote->price_snapshot_at = now();
                $quote->save();
            }
            $quote->transitionTo(QuoteState::Proofing);
            DB::afterCommit(fn () => Broadcasting::dispatch(fn () => QuoteStateChanged::dispatch($quote, $previous)));
        }

        DB::afterCommit(fn () => $this->emailProofsReady($quote));

        return $quote;
    });
}
```

Requires `Proof::transitionTo(ProofState)` — verify it exists in `app/Models/Proof.php` (the current `approveProof` uses `$proof->transitionTo(...)`, so it does). Requires `emailProofsReady()` — added in Task 5.1; for now stub it privately:

```php
private function emailProofsReady(Quote $quote): void
{
    // Real batched email wired in Task 5.1.
    $this->emailQuoteReady($quote, true);
}
```

- [ ] **Step 4: Run — expect PASS** (uses the temporary `emailQuoteReady` bridge)

Run: `php artisan test tests/Feature/SendProofsTest.php`

- [ ] **Step 5: Commit**

```bash
git add app/Services/QuoteService.php tests/Feature/SendProofsTest.php
git commit -m "feat(proofs): batched send flips DRAFT->SENT, one email"
```

### Task 2.3: Drop-line cancels its open proof and recomputes

**Files:**
- Modify: `app/Services/QuoteService.php` (the line-drop path — search `LineItemState::Dropped`, ~`:911`)
- Test: `tests/Feature/DropLineUnblocksProofTest.php` (create)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\QuoteState;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Proof;
use App\Models\Quote;

it('dropping the last unresolved artwork line advances the order', function (): void {
    $quote = Quote::factory()->create(['state' => 'PROOFING', 'accepted_at' => null]);
    $done = LineItem::factory()->create(['quote_id' => $quote->id, 'product_id' => Product::factory()->create()->id, 'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/a.png'], 'line_state' => 'PENDING']);
    Proof::factory()->create(['quote_id' => $quote->id, 'line_item_id' => $done->id, 'state' => 'APPROVED']);
    $stuck = LineItem::factory()->create(['quote_id' => $quote->id, 'product_id' => Product::factory()->create()->id, 'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/b.png'], 'line_state' => 'PENDING']);
    Proof::factory()->create(['quote_id' => $quote->id, 'line_item_id' => $stuck->id, 'state' => 'CHANGES_REQUESTED']);

    // Drop via the same path staff use (adapt to the real drop entry point).
    $stuck->update(['line_state' => 'DROPPED']);
    $stuck->proofs()->whereIn('state', ['DRAFT', 'SENT', 'CHANGES_REQUESTED'])->delete();
    $quote->recomputeProofState();

    expect($quote->fresh()->state)->toBe(QuoteState::ArtworkApproved);
});
```

- [ ] **Step 2: Run — expect FAIL or PASS-by-accident**; then wire the real drop path.

Run: `php artisan test tests/Feature/DropLineUnblocksProofTest.php`

- [ ] **Step 3: Find the staff line-drop entry point and fold in proof-cancel + recompute**

Locate where a line transitions to `LineItemState::Dropped` for a staff drop (in `amend()` / a drop action around `QuoteService.php:911`). After the drop, add:

```php
// A dropped line leaves proofing: cancel its open proof so it stops
// blocking the order gate, then roll the order state up.
$line->proofs()->whereIn('state', [
    ProofState::Draft->value, ProofState::Sent->value, ProofState::ChangesRequested->value,
])->delete();
$quote->recomputeProofState();
```

- [ ] **Step 4: Run — expect PASS**; run `--filter="Amend|Quote"` and fix fallout.

- [ ] **Step 5: Commit**

```bash
git add app/Services/QuoteService.php tests/Feature/DropLineUnblocksProofTest.php
git commit -m "feat(proofs): dropping a line cancels its proof and unblocks the order"
```

---

## Phase 3 — Routes, controller, approve-all

### Task 3.1: Routes — drop order-level issue, add per-line stage/send/approve-all

**Files:**
- Modify: `routes/api.php:155`
- Modify: `app/Http/Controllers/ProofController.php`
- Test: `tests/Feature/ProofRoutesTest.php` (create)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Proof;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    Mail::fake();
    $this->company = Company::factory()->create();
    $this->staff = User::factory()->staffAdmin()->create();
    $this->buyer = User::factory()->create(['company_id' => $this->company->id, 'role' => 'buyer']);
    $this->quote = Quote::factory()->create(['company_id' => $this->company->id, 'created_by' => $this->buyer->id, 'state' => 'ACCEPTED']);
    $this->line = LineItem::factory()->create(['quote_id' => $this->quote->id, 'product_id' => Product::factory()->create()->id, 'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/a.png'], 'line_state' => 'PENDING']);
});

it('stages a line proof then sends the round', function (): void {
    $this->actingAs($this->staff)
        ->postJson("/api/quotes/{$this->quote->id}/lines/{$this->line->id}/proofs", ['artwork_version_ref' => 'artwork/a.png'])
        ->assertCreated();

    $this->actingAs($this->staff)
        ->postJson("/api/quotes/{$this->quote->id}/proofs/send")
        ->assertOk();

    expect($this->quote->fresh()->state->value)->toBe('PROOFING');
});

it('approves every awaiting line proof in one call', function (): void {
    $line2 = LineItem::factory()->create(['quote_id' => $this->quote->id, 'product_id' => Product::factory()->create()->id, 'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/b.png'], 'line_state' => 'PENDING']);
    $this->quote->update(['state' => 'PROOFING']);
    Proof::factory()->create(['quote_id' => $this->quote->id, 'line_item_id' => $this->line->id, 'state' => 'SENT']);
    Proof::factory()->create(['quote_id' => $this->quote->id, 'line_item_id' => $line2->id, 'state' => 'SENT']);

    $this->actingAs($this->buyer)
        ->postJson("/api/quotes/{$this->quote->id}/proofs/approve-all")
        ->assertOk();

    expect($this->quote->fresh()->state->value)->toBe('ARTWORK_APPROVED');
});

it('no longer exposes the order-level issue route', function (): void {
    $this->actingAs($this->staff)
        ->postJson("/api/quotes/{$this->quote->id}/proofs", ['artwork_version_ref' => 'artwork/a.png'])
        ->assertStatus(405);
});
```

- [ ] **Step 2: Run — expect FAIL**

Run: `php artisan test tests/Feature/ProofRoutesTest.php`

- [ ] **Step 3: Replace the route + add controller actions**

`routes/api.php` — replace line 155 (`POST /quotes/{quote}/proofs`) with:

```php
// Stage artwork for one line (DRAFT), then send the whole round in one email.
Route::post('/quotes/{quote}/lines/{lineItem}/proofs', [ProofController::class, 'stage'])->middleware('permission:quotes.edit');
Route::post('/quotes/{quote}/proofs/send', [ProofController::class, 'send'])->middleware('permission:quotes.edit');
// Buyer approves every item still awaiting them in one action.
Route::post('/quotes/{quote}/proofs/approve-all', [ProofController::class, 'approveAll']);
```

`app/Http/Controllers/ProofController.php` — remove `store()`, add:

```php
public function stage(StoreProofRequest $request, Quote $quote, LineItem $lineItem): JsonResponse
{
    abort_unless($lineItem->quote_id === $quote->id, 404);

    $proof = $this->quotes->stageProof($quote, $lineItem, $request->string('artwork_version_ref')->toString());
    $proof->setRelation('quote', $quote);

    return (new ProofResource($proof))->response()->setStatusCode(201);
}

public function send(Request $request, Quote $quote): JsonResponse
{
    abort_unless($request->user()->isStaff(), 403);
    $this->quotes->sendProofs($quote);

    return response()->json(['message' => 'Proofs sent to the buyer.']);
}

public function approveAll(Request $request, Quote $quote): JsonResponse
{
    $this->authorize('view', $quote); // buyer of the company or staff
    $this->quotes->approveAllOpenProofs($quote, $request->user());

    return response()->json(['message' => 'Approved.']);
}
```

(Imports: `App\Models\LineItem`.) Update `StoreProofRequest` if it validated a `notes` field tied to the old flow — keep `artwork_version_ref` required. If `DecideProofRequest`/`store` referenced anything now removed, clean it.

- [ ] **Step 4: Add `approveAllOpenProofs()` to the service**

```php
/**
 * Approve every proof on the order still awaiting the buyer (SENT), in one
 * transaction. Leaves CHANGES_REQUESTED lines alone (they owe staff a
 * revision first). Attributed to the acting user (buyer, or superadmin
 * on-behalf). One roll-up at the end.
 */
public function approveAllOpenProofs(Quote $quote, User $actor): void
{
    DB::transaction(function () use ($quote, $actor): void {
        $open = $quote->proofs()->where('state', ProofState::Sent->value)->get();
        foreach ($open as $proof) {
            $proof->approved_by = $actor->id;
            $proof->approved_at = now();
            $proof->transitionTo(ProofState::Approved);
            $this->audit->log($proof, 'proof.approved', null, [
                'version' => $proof->version,
                'line_item_id' => $proof->line_item_id,
                'approved_by' => $actor->id,
                'batch' => true,
            ]);
            DB::afterCommit(fn () => Broadcasting::dispatch(fn () => ProofStatusChanged::dispatch($proof, $quote->company_id)));
        }
        $quote->recomputeProofState();
    });
}
```

(Import `App\Models\User`.)

- [ ] **Step 5: Run — expect PASS**; run `--filter="Proof|Tenancy"`, fix policy/authorize fallout.

- [ ] **Step 6: Commit**

```bash
git add routes/api.php app/Http/Controllers/ProofController.php app/Services/QuoteService.php app/Http/Requests/StoreProofRequest.php tests/Feature/ProofRoutesTest.php
git commit -m "feat(proofs): per-line stage/send + approve-all routes; drop order-level issue"
```

---

## Phase 4 — Production

### Task 4.1: `buildJobsForQuote` writes per-line `artwork_refs`

**Files:**
- Modify: `app/Services/QueueService.php:80-140`
- Test: `tests/Feature/QueuePerLineArtworkTest.php` (create)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\LineItem;
use App\Models\Product;
use App\Models\Proof;
use App\Models\Quote;
use App\Services\QueueService;

it('gives a batched UV job one labelled file per approved UV line', function (): void {
    $quote = Quote::factory()->create(['state' => 'PROCURING', 'accepted_at' => now()]);
    $p1 = Product::factory()->create(['name' => 'Cap', 'class' => 'SCRAPED_UV']);
    $p2 = Product::factory()->create(['name' => 'Mug', 'class' => 'SCRAPED_UV']);
    $l1 = LineItem::factory()->create(['quote_id' => $quote->id, 'product_id' => $p1->id, 'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/cap.png'], 'line_state' => 'READY']);
    $l2 = LineItem::factory()->create(['quote_id' => $quote->id, 'product_id' => $p2->id, 'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/mug.png'], 'line_state' => 'READY']);
    Proof::factory()->create(['quote_id' => $quote->id, 'line_item_id' => $l1->id, 'state' => 'APPROVED', 'artwork_version_ref' => 'artwork/cap.png']);
    Proof::factory()->create(['quote_id' => $quote->id, 'line_item_id' => $l2->id, 'state' => 'APPROVED', 'artwork_version_ref' => 'artwork/mug.png']);

    $jobs = app(QueueService::class)->buildJobsForQuote($quote->fresh());

    $uv = $jobs->firstWhere('track', 'UV');
    expect($uv->artwork_refs)->toHaveCount(2);
    $refs = collect($uv->artwork_refs)->pluck('ref')->all();
    expect($refs)->toContain('artwork/cap.png')->toContain('artwork/mug.png');
    expect(collect($uv->artwork_refs)->pluck('product_name')->all())->toContain('Cap')->toContain('Mug');
});
```

(Adjust the product `class`/`track` values to whatever the codebase uses for the UV track — read `Product::class->track()`.)

- [ ] **Step 2: Run — expect FAIL**

Run: `php artisan test tests/Feature/QueuePerLineArtworkTest.php`

- [ ] **Step 3: Rework `resolveArtworkRef` → `resolveArtworkRefs` (list)**

Replace the `artwork_ref` assignment in `buildJobsForQuote` (`QueueService.php:90`) with:

```php
'artwork_refs' => $this->resolveArtworkRefs($track, $lines),
```

Replace `resolveArtworkRef()` (`:128-140`) with:

```php
/**
 * The print-ready files a job hands the floor, one entry per artwork line it
 * covers: { line_item_id, product_name, ref }. A 3D line uses its UV decal
 * (customization.print_file_ref) when present, else that line's approved
 * proof. A UV line uses that line's approved proof artwork. A line with no
 * approved proof is a gate violation - buildJobsForQuote's caller guarantees
 * every artwork line is approved-or-dropped before READY - so we throw.
 *
 * @param  Collection<int, \App\Models\LineItem>  $lines
 * @return array<int, array{line_item_id: int, product_name: string, ref: string}>
 */
private function resolveArtworkRefs(JobTrack $track, Collection $lines): array
{
    $out = [];
    foreach ($lines as $line) {
        if (! $line->needsProof()) {
            continue; // plain stock line in a batched group - nothing to print
        }

        $ref = null;
        if ($track === JobTrack::ThreeD) {
            $printFileRef = $line->customization['print_file_ref'] ?? null;
            $ref = is_string($printFileRef) && $printFileRef !== '' ? $printFileRef : null;
        }
        if ($ref === null) {
            $approved = $line->proofs()->where('state', ProofState::Approved->value)->orderByDesc('version')->first();
            if ($approved === null) {
                throw new RuntimeException("Line {$line->id} has no approved proof; cannot build its print file.");
            }
            $ref = $approved->artwork_version_ref;
        }

        $out[] = [
            'line_item_id' => $line->id,
            'product_name' => (string) ($line->product?->name ?? "Line #{$line->id}"),
            'ref' => $ref,
        ];
    }

    return $out;
}
```

Remove the now-unused `$approvedProof` param threading (the `$approvedProof = $quote->approvedProof()` guard at `:60-64` can stay as a coarse "at least one approval exists" check, or be dropped — prefer dropping it since the per-line throw is stricter). Imports: `App\Enums\ProofState`, `RuntimeException`.

- [ ] **Step 4: Run — expect PASS**; run `--filter="Queue|Production"`, fix fallout (any test asserting `job.artwork_ref` becomes `job.artwork_refs`).

- [ ] **Step 5: Commit**

```bash
git add app/Services/QueueService.php tests/Feature/QueuePerLineArtworkTest.php
git commit -m "feat(production): jobs carry a labelled file per artwork line"
```

### Task 4.2: Print-file serves the job's files (per-file + ZIP)

**Files:**
- Modify: `app/Http/Controllers/ProductionQueueController.php:80-101`
- Modify: `routes/api.php:172` (print-file route + a ZIP route)
- Test: `tests/Feature/PrintFileTest.php` (create/extend)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\ProductionJob;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

it('streams a single file of a job by ref', function (): void {
    Storage::fake('artwork'); // adjust to the artwork_disk name/config
    Storage::disk('artwork')->put('artwork/cap.png', 'PNGDATA');
    $staff = User::factory()->staffAdmin()->create();
    $quote = Quote::factory()->create(['state' => 'READY']);
    $job = ProductionJob::factory()->create([
        'quote_id' => $quote->id, 'track' => 'UV', 'state' => 'READY',
        'artwork_refs' => [['line_item_id' => 1, 'product_name' => 'Cap', 'ref' => 'artwork/cap.png']],
    ]);

    $this->actingAs($staff)
        ->get("/api/production-jobs/{$job->id}/print-file?ref=artwork/cap.png")
        ->assertOk();
});

it('404s a ref that is not on the job', function (): void {
    $staff = User::factory()->staffAdmin()->create();
    $job = ProductionJob::factory()->create(['track' => 'UV', 'state' => 'READY', 'artwork_refs' => [['line_item_id' => 1, 'product_name' => 'Cap', 'ref' => 'artwork/cap.png']]]);

    $this->actingAs($staff)
        ->get("/api/production-jobs/{$job->id}/print-file?ref=artwork/evil.png")
        ->assertNotFound();
});
```

- [ ] **Step 2: Run — expect FAIL**

Run: `php artisan test tests/Feature/PrintFileTest.php`

- [ ] **Step 3: Rework `printFile` to select a ref from the job's list**

```php
public function printFile(Request $request, ProductionJob $job): StreamedResponse
{
    $this->authorize('manageProduction', Quote::class);

    $ref = (string) $request->query('ref', '');
    $onJob = collect($job->artwork_refs ?? [])->pluck('ref')->contains($ref);

    if (! $onJob || preg_match('#^artwork/[A-Za-z0-9_\-]+\.[A-Za-z0-9]{1,10}$#', $ref) !== 1) {
        abort(404);
    }

    $disk = Storage::disk((string) config('filesystems.artwork_disk'));
    if (! $disk->exists($ref)) {
        abort(404);
    }

    return $disk->download($ref, basename($ref));
}
```

- [ ] **Step 4: Add a ZIP action (all files, named by item)**

Add `printFileZip(Request $request, ProductionJob $job)` that builds a ZIP naming each entry `"{product_name}-{basename}"` (reuse the `exportParts` ZIP builder in `AdminCatalogueController` as the pattern). Add the route:

```php
Route::get('/production-jobs/{job}/print-file', [ProductionQueueController::class, 'printFile'])->middleware('permission:production.view');
Route::get('/production-jobs/{job}/print-files.zip', [ProductionQueueController::class, 'printFileZip'])->middleware('permission:production.view');
```

(Replace the existing single print-file route.)

- [ ] **Step 5: Run — expect PASS**; run `--filter="PrintFile|Production"`.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/ProductionQueueController.php routes/api.php tests/Feature/PrintFileTest.php
git commit -m "feat(production): print-file serves per-item files + ZIP"
```

---

## Phase 5 — Notifications

### Task 5.1: Batched "proofs ready" email listing the round's items

**Files:**
- Modify: `app/Mail/QuoteReadyMail.php`
- Modify: `app/Services/QuoteService.php` (`emailProofsReady` real impl)
- Test: `tests/Feature/ProofsReadyEmailTest.php` (create)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Mail\QuoteReadyMail;
use App\Models\Company;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Proof;
use App\Models\Quote;
use App\Models\User;
use App\Services\QuoteService;
use Illuminate\Support\Facades\Mail;

it('lists every item in the round on one email', function (): void {
    Mail::fake();
    $company = Company::factory()->create();
    $buyer = User::factory()->create(['company_id' => $company->id, 'role' => 'buyer']);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'created_by' => $buyer->id, 'state' => 'ACCEPTED']);
    foreach (['Cap', 'Mug'] as $name) {
        $line = LineItem::factory()->create(['quote_id' => $quote->id, 'product_id' => Product::factory()->create(['name' => $name])->id, 'customization' => ['mode' => 'designer', 'artwork_ref' => "artwork/{$name}.png"], 'line_state' => 'PENDING']);
        Proof::factory()->create(['quote_id' => $quote->id, 'line_item_id' => $line->id, 'artwork_version_ref' => "artwork/{$name}.png", 'state' => 'DRAFT']);
    }

    app(QuoteService::class)->sendProofs($quote->fresh());

    Mail::assertQueued(QuoteReadyMail::class, function (QuoteReadyMail $m): bool {
        return count($m->proofItems) === 2;
    });
});
```

- [ ] **Step 2: Run — expect FAIL** (no `proofItems` on the mailable)

Run: `php artisan test tests/Feature/ProofsReadyEmailTest.php`

- [ ] **Step 3: Extend `QuoteReadyMail` to carry per-item rows; wire `emailProofsReady`**

Give `QuoteReadyMail` a public `array $proofItems` (each `{product_name, thumbnail_url}`), populated from the order's `SENT` proofs. Build thumbnails via `ProofCompositeService::signedCompositeUrl($proof, ...)`. Update the Blade view to loop `$proofItems` (a row per item with its thumbnail). Then replace the temporary `emailProofsReady` in `QuoteService`:

```php
private function emailProofsReady(Quote $quote): void
{
    $recipient = $this->buyerRecipient($quote); // reuse existing recipient resolution
    if ($recipient?->email === null) {
        return;
    }
    $items = $quote->proofs()->where('state', ProofState::Sent->value)->with('lineItem.product')->get();
    Mail::to($recipient->email)->queue(new QuoteReadyMail($quote, $items));
}
```

Keep the existing `QuoteReadyMail` single-proof constructor working for the DRAFT slim-send path, OR migrate that path to `sendProofs` too. Prefer: the DRAFT slim send (`send()` with artworkRef) now also stages + sends via the new path — but that is a bigger change; MINIMUM here is the batched constructor overload. Match the existing mailable's constructor style.

- [ ] **Step 4: Run — expect PASS**

Run: `php artisan test tests/Feature/ProofsReadyEmailTest.php`

- [ ] **Step 5: Run `--filter="QuoteReady|Notification|Email"`, fix fallout. Commit.**

```bash
git add app/Mail/QuoteReadyMail.php resources/views/emails app/Services/QuoteService.php tests/Feature/ProofsReadyEmailTest.php
git commit -m "feat(proofs): batched proofs-ready email lists the round's items"
```

### Task 5.2: `ProofCompositeService` matches by line, not by ref

**Files:**
- Modify: `app/Services/ProofCompositeService.php:80-102`
- Test: `tests/Feature/ProofCompositeTest.php` (extend the existing untracked test)

- [ ] **Step 1: Add a test** asserting the composite uses `proof->lineItem->product->image_url` directly.

```php
it('composites onto the proof line item product photo', function (): void {
    // Build a proof with lineItem.product.image_url set; assert matchingProductImage returns it.
    // (Use reflection or a thin public wrapper if matchingProductImage stays private.)
});
```

- [ ] **Step 2: Run — expect FAIL**

- [ ] **Step 3: Simplify `matchingProductImage()`** to read the direct relation:

```php
private function matchingProductImage(Proof $proof): ?string
{
    $line = $proof->lineItem;
    if ($line === null) {
        return null;
    }
    $customization = $line->customization ?? [];
    if (($customization['mode'] ?? null) === 'buyer_uploaded') {
        return null; // finished-look photo already shows the product
    }
    $imageUrl = (string) ($line->product?->image_url ?? '');

    return $imageUrl !== '' ? $imageUrl : null;
}
```

- [ ] **Step 4: Run — expect PASS. Commit.**

```bash
git add app/Services/ProofCompositeService.php tests/Feature/ProofCompositeTest.php
git commit -m "refactor(proofs): composite matches by proof line item"
```

### Task 5.3: Reminder wording counts items

**Files:**
- Modify: `app/Services/ReminderSchedule.php`
- Test: `tests/Feature/ReminderScheduleTest.php` (extend)

- [ ] **Step 1:** `ReminderSchedule::next()`'s proof branch already keys on the order being in `PROOFING` with an open `SENT` proof — verify it still holds with per-line proofs (the `proofs()->contains(SENT)` query is unchanged). Add a `awaiting_count` to the returned array = number of `SENT` proofs, so the panel/email can say "N items still awaiting your approval". Add a test asserting `awaiting_count` for a 2-open-proof order. Implement, run, commit.

```bash
git add app/Services/ReminderSchedule.php tests/Feature/ReminderScheduleTest.php
git commit -m "feat(proofs): reminder reports how many items await the buyer"
```

---

## Phase 6 — API resources

### Task 6.1: `ProofResource` gains line identity

**Files:**
- Modify: `app/Http/Resources/ProofResource.php`
- Test: `tests/Feature/ProofResourceTest.php` (create)

- [ ] **Step 1: Write the failing test** asserting the serialized proof includes `line_item_id` and `product_name`.

- [ ] **Step 2: Run — expect FAIL.**

- [ ] **Step 3: Add fields** to `toArray()`:

```php
'line_item_id' => $this->line_item_id,
'product_name' => $this->lineItem?->product?->name,
```

- [ ] **Step 4: Run — expect PASS. Commit.**

```bash
git add app/Http/Resources/ProofResource.php tests/Feature/ProofResourceTest.php
git commit -m "feat(proofs): ProofResource carries line identity"
```

### Task 6.2: `QuoteResource` — reminder awaiting count already covered; verify proofs group cleanly

- [ ] Confirm `QuoteResource.proofs` still serializes (now each with `line_item_id`); the client groups by line. Confirm `reminder.next` (Task 5.3) exposes `awaiting_count`. Add an assertion to an existing quote-show test. Commit if changed.

---

## Phase 7 — Frontend: types & store

### Task 7.1: Types

**Files:**
- Modify: `frontend/src/types.ts`

- [ ] Add `'DRAFT'` to `ProofState`. Add `line_item_id: number` and `product_name?: string | null` to `Proof`. Add `awaiting_count?: number` to `QuoteReminderNext`. Add `'DRAFT'` handling to `proofToneMap` in `frontend/src/lib/quoteStatus.ts` (`neutral`). Run `npx tsc --noEmit`. Commit.

```bash
git add frontend/src/types.ts frontend/src/lib/quoteStatus.ts
git commit -m "feat(proofs): frontend types for per-line proofs"
```

### Task 7.2: Store actions

**Files:**
- Modify: `frontend/src/stores/quoteStore.ts`

- [ ] Replace `issueProof(id, ref, notes)` with:
  - `stageProof(quoteId: number, lineId: number, artworkRef: string): Promise<void>` → `POST /quotes/{quoteId}/lines/{lineId}/proofs`.
  - `sendProofs(quoteId: number): Promise<void>` → `POST /quotes/{quoteId}/proofs/send`.
  - `approveAllProofs(quoteId: number): Promise<boolean>` → `POST /quotes/{quoteId}/proofs/approve-all`.
  Keep `decideProof` (per-proof) and `resendProof`. Each action refetches the quote on success (mirror the existing `issueProof` pattern at `quoteStore.ts:284`). Run `npx tsc --noEmit`. Commit.

```bash
git add frontend/src/stores/quoteStore.ts
git commit -m "feat(proofs): store actions for stage/send/approve-all"
```

---

## Phase 8 — Frontend: staff per-line UI

### Task 8.1: `LineProofRow` component (staff)

**Files:**
- Create: `frontend/src/components/quote/LineProofRow.tsx`
- Test: `frontend/src/components/quote/LineProofRow.test.tsx`

- [ ] **Step 1: Write the test** — renders product name + proof-state badge; an uploader that calls `onStage(ref)`; a "Use existing artwork" button; shows "Not prepared" when the line has no proof; a "Drop" affordance.

- [ ] **Step 2: Implement** the presentational row. Props:

```ts
interface LineProofRowProps {
  line: LineItem;
  proof: Proof | null;          // latest proof for the line, or null
  artworkOptions: ArtworkOption[];
  busy: boolean;
  onStage: (artworkRef: string) => void;
  onPickExisting: () => void;
  onDrop: () => void;
}
```

Render: product image + name; a state badge (`Not prepared`/`Staged`/`Sent`/`Approved`/`In changes`) derived from `proof?.state`; `ProofFileInput` (staged uploader); "Use existing artwork" (defaults to this line's own design first in `artworkOptions`); a "Drop item" button (danger, opens the existing cancel-line flow).

- [ ] **Step 3: Run tests — PASS. Commit.**

### Task 8.2: Wire staff panel — blocker breakdown, Send button, unsent warning

**Files:**
- Modify: `frontend/src/pages/QuoteDetailPage.tsx`
- Test: `frontend/src/pages/QuoteDetailPage.test.tsx` (extend)

- [ ] **Step 1: Write tests** for: blocker breakdown counts (`Awaiting buyer: N · In changes: N · Not prepared: N`); "Send proofs (N staged)" disabled at 0, enabled with a staged line; unsent-DRAFT warning appears when a DRAFT exists.

- [ ] **Step 2: Implement.** In the staff panel (the merged `staffPanel` in `QuoteDetailPage.tsx`), for `PROOFING`/`ACCEPTED`/`CHANGES_REQUESTED`/`ARTWORK_APPROVED` states, replace the single issue-proof control with:
  - a list of `LineProofRow` for every `line.needsProof()` line;
  - a blocker breakdown line computed from the lines' latest proofs;
  - a `Send proofs to buyer (N staged)` button calling `sendProofs(quote.id)`, disabled when no `DRAFT`;
  - an inline warning when any `DRAFT` proof exists.
  Derive "latest proof per line" from `quote.proofs` grouped by `line_item_id`, max `version`.

- [ ] **Step 3: Update the approved-artwork callout** to render per line (one callout row per line with an `APPROVED` proof: "{product} — approved v{n}").

- [ ] **Step 4: Run the page test file — PASS. Fix any existing staff-proof tests that used the old single control. Commit.**

```bash
git add frontend/src/components/quote/LineProofRow.tsx frontend/src/pages/QuoteDetailPage.tsx frontend/src/pages/QuoteDetailPage.test.tsx
git commit -m "feat(proofs): staff per-line staging, blocker breakdown, batched send"
```

---

## Phase 9 — Frontend: buyer per-line UI

### Task 9.1: `BuyerProofItem` + review list

**Files:**
- Create: `frontend/src/components/quote/BuyerProofItem.tsx`
- Modify: `frontend/src/pages/QuoteDetailPage.tsx` (replace `buyerProofReview`)
- Test: `frontend/src/pages/QuoteDetailPage.test.tsx` (extend)

- [ ] **Step 1: Write tests** for: per-item Approve calls `decideProof(proof.id, 'approve')`; per-item Request-changes captures notes; "Approve all remaining" calls `approveAllProofs` and is present only when ≥1 item is `SENT`; progress banner text ("1 of 3 approved").

- [ ] **Step 2: Implement `BuyerProofItem`** — one card per artwork line with an open/decided proof: product name, artwork inline (`ArtworkPreview`), and per-item Approve / Request-changes (reuse the existing change-notes + `ProofFileInput` reference-image block). A line in `CHANGES_REQUESTED` shows "being revised — we'll send an updated proof" (not actionable).

- [ ] **Step 3: Replace `buyerProofReview`** with: an overall banner + progress computed from the lines' latest proofs; a `BuyerProofItem` per artwork line whose latest proof is `SENT`/`CHANGES_REQUESTED`; an "Approve all remaining" button (only when ≥1 `SENT`) with a label naming the count.

- [ ] **Step 4: Run tests — PASS. Fix existing buyer proof-review tests. Commit.**

```bash
git add frontend/src/components/quote/BuyerProofItem.tsx frontend/src/pages/QuoteDetailPage.tsx frontend/src/pages/QuoteDetailPage.test.tsx
git commit -m "feat(proofs): buyer per-item review + approve-all"
```

---

## Phase 10 — Seeders & full-suite green

### Task 10.1: Update seeders for per-line proofs

**Files:**
- Modify: `database/seeders/*` (any that create proofs)

- [ ] Find seeders creating proofs (`grep -rn "Proof::" database/seeders`). Give each proof a `line_item_id` for a customized line on its quote, and set realistic states (a multi-item order with mixed `APPROVED`/`SENT`/`CHANGES_REQUESTED` to exercise the UI). Run `php artisan migrate:fresh --seed`. Commit.

```bash
git add database/seeders
git commit -m "chore(proofs): seed per-line proofs"
```

### Task 10.2: Full suites green

- [ ] Run backend: `php artisan test` — all pass. Fix stragglers.
- [ ] Run frontend: `cd frontend && npx vitest run` — all pass. Fix stragglers.
- [ ] Run `cd frontend && npx tsc --noEmit && npx vite build` — clean.
- [ ] Commit any fixes.

```bash
git commit -am "test(proofs): full suites green for per-line proofs"
```

### Task 10.3: Browser verification (staff + buyer)

- [ ] Start the stack, seed a multi-item order. As staff: stage two lines, send, confirm one email in logs, see the blocker breakdown. As buyer: approve one item, request changes on another, confirm progress + partial persistence. As staff: revise + resend, buyer approves all remaining → order reaches ARTWORK_APPROVED. Screenshot both journeys. (Uses the `preview_start` / verification workflow.)

---

## Self-review notes (author)

- **Spec coverage:** data model (0.2–0.5), `needsProof` (0.4), gate (1.1), per-line decisions (1.2), staging+send (2.1–2.2), drop-unblock (2.3), routes/approve-all (3.1), production list + print-file (4.1–4.2), batched email (5.1), composite (5.2), reminder count (5.3), resources (6.x), frontend types/store (7.x), staff UI (8.x), buyer UI (9.x), seeders/verify (10.x). All spec sections mapped.
- **Removed order-level route** covered in 3.1 (405 assertion).
- **Naming consistency:** `stageProof`, `sendProofs`, `approveAllOpenProofs` (service) / `approveAllProofs` (store) / `approveAll` (controller) — intentional layer names; keep as written.
- **Open verify points flagged inline:** QuoteState edges (1.1 step 4), artwork_disk fake name (4.2), UV track class value (4.1), QuoteReadyMail constructor style (5.1).
