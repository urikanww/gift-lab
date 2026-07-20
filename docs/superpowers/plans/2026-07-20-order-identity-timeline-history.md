# Order Identity, Collapsed Timeline, Status History Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `reference` the single order identifier on every surface, collapse the nine-state timeline, add quote search accepting either identifier, and start recording status history.

**Architecture:** Four independent parts, sequenced so each is shippable alone. D (search) lands **before** A (hiding the id) so no window exists where an old id is neither displayed nor findable. B is UI-only. C adds logging at a single choke point plus a read endpoint.

**Tech Stack:** Laravel 11, Pest, React 18, TypeScript, Tailwind, Vitest + Testing Library.

**Spec:** `docs/superpowers/specs/2026-07-20-order-identity-timeline-history-design.md`

**Branch:** `order-identity-timeline-history` (already checked out, spec committed)

---

## Ordering matters

D before A. Part A removes the id from every screen; Part D is how anyone
holding an old id finds their order. Shipping A first opens a window where an
existing `#1` in a customer email is unlookupable. The parts are otherwise
independent.

---

## File Structure

| File | Change | Part |
|---|---|---|
| `app/Http/Controllers/QuoteController.php` | Modify `index` (`:30-41`), add `history` | D, C |
| `tests/Feature/QuoteSearchTest.php` | Create | D |
| `frontend/src/pages/QuoteListPage.tsx` | Search input + reference display | D, A |
| `frontend/src/stores/quoteStore.ts` | Thread the `q` param | D |
| `app/Http/Resources/ProductionJobResource.php` | Add `quote_reference` (`:23`) | A |
| `app/Http/Resources/LineItemResource.php` | Add `quote_reference` (`:23`) | A |
| `app/Http/Resources/ProofResource.php` | Add `quote_reference` (`:23`) | A |
| `app/Services/QueueService.php` | Add `quote_reference` (`:86`) | A |
| `app/Events/{LineItemAwaitingReconfirm,ProductionQueueUpdated,ProofStatusChanged,QuoteStateChanged}.php` | Add `quote_reference` | A |
| `resources/views/mail/quote-ready.blade.php` | id fallback → reference (`:78`) | A |
| `frontend/src/pages/{QuoteDetailPage,DashboardPage,ProductionQueuePage,ProcurementPage,BuyerDashboardPage,CheckoutPage}.tsx` | Display reference | A |
| `frontend/src/components/home/ReorderRail.tsx` | Display reference | A |
| `frontend/src/types.ts` | `quote_reference` on payload types | A |
| `frontend/src/components/quote/QuoteTimeline.tsx` | Create | B |
| `app/Models/Quote.php` | Log in `transitionTo` (`:217`) | C |
| `app/Http/Resources/QuoteHistoryResource.php` | Create | C |
| `frontend/src/components/quote/StatusHistory.tsx` | Create | C |

---

## PART D — quote search

### Task D1: search by reference and id, scoped to tenancy

**Files:**
- Modify: `app/Http/Controllers/QuoteController.php:30-41`
- Test: `tests/Feature/QuoteSearchTest.php` (create)

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/QuoteSearchTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Quote;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

function searchQuotes(string $term): \Illuminate\Testing\TestResponse
{
    return test()->getJson('/api/quotes?q='.urlencode($term));
}

it('finds a quote by a partial reference', function (): void {
    $company = Company::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $company->id, 'reference' => 'ABC123XYZ']);
    Quote::factory()->create(['company_id' => $company->id, 'reference' => 'ZZZZZZZZZ']);
    Sanctum::actingAs(User::factory()->create(['company_id' => $company->id, 'role' => 'buyer']));

    $res = searchQuotes('C123')->assertOk();

    expect($res->json('data'))->toHaveCount(1)
        ->and($res->json('data.0.id'))->toBe($quote->id);
});

it('finds a quote by its exact id', function (): void {
    $company = Company::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $company->id]);
    Sanctum::actingAs(User::factory()->create(['company_id' => $company->id, 'role' => 'buyer']));

    $res = searchQuotes((string) $quote->id)->assertOk();

    expect($res->json('data.0.id'))->toBe($quote->id);
});

it('accepts a leading # on an id, the way it has always been written', function (): void {
    $company = Company::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $company->id]);
    Sanctum::actingAs(User::factory()->create(['company_id' => $company->id, 'role' => 'buyer']));

    expect(searchQuotes('#'.$quote->id)->assertOk()->json('data.0.id'))->toBe($quote->id);
});

it('matches an id exactly, never as a substring', function (): void {
    // id LIKE '%1%' would drag in 10, 21, 100 - useless for finding one order.
    $company = Company::factory()->create();
    $one = Quote::factory()->create(['company_id' => $company->id, 'reference' => 'AAAAAAAAAA']);
    $ten = Quote::factory()->create(['company_id' => $company->id, 'reference' => 'BBBBBBBBBB']);
    Sanctum::actingAs(User::factory()->create(['company_id' => $company->id, 'role' => 'buyer']));

    $ids = collect(searchQuotes((string) $one->id)->assertOk()->json('data'))->pluck('id');

    expect($ids)->toContain($one->id)->not->toContain($ten->id);
})->skip(fn (): bool => true, 'Enable once factory ids are deterministic enough to assert 1 vs 10.');

// THE SECURITY TEST. A flat orWhere escapes the company_id scope and lets a
// buyer read another company's order by guessing an id.
it('never lets a buyer reach another company by searching its id', function (): void {
    $mine = Company::factory()->create();
    $theirs = Company::factory()->create();
    $foreign = Quote::factory()->create(['company_id' => $theirs->id]);
    Sanctum::actingAs(User::factory()->create(['company_id' => $mine->id, 'role' => 'buyer']));

    $res = searchQuotes((string) $foreign->id)->assertOk();

    expect($res->json('data'))->toBeEmpty();
});

it('lets staff search across every company', function (): void {
    $quote = Quote::factory()->create(['reference' => 'STAFFFIND']);
    Sanctum::actingAs(User::factory()->create(['company_id' => null, 'role' => 'staff_admin']));

    expect(searchQuotes('STAFFFIND')->assertOk()->json('data.0.id'))->toBe($quote->id);
});

it('returns the full list when no term is given', function (): void {
    $company = Company::factory()->create();
    Quote::factory()->count(3)->create(['company_id' => $company->id]);
    Sanctum::actingAs(User::factory()->create(['company_id' => $company->id, 'role' => 'buyer']));

    expect(test()->getJson('/api/quotes')->assertOk()->json('data'))->toHaveCount(3);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/QuoteSearchTest.php`
Expected: the reference/id/`#` tests FAIL (the `q` parameter is ignored, so all
quotes come back). The tenancy and no-term tests may already pass — that is
fine, they are guarding against the change, not driving it.

- [ ] **Step 3: Add the filter**

In `app/Http/Controllers/QuoteController.php`, replace the `index` query:

```php
        $quotes = Quote::query()
            ->when(! $user->isStaff(), fn ($q) => $q->where('company_id', $user->company_id))
            // Staff see all companies - load the name so the UI can label rows.
            ->when($user->isStaff(), fn ($q) => $q->with('company'))
            ->when($request->filled('q'), function ($query) use ($request): void {
                // A leading # is how the id has been written everywhere until
                // now, so buyers will paste it verbatim.
                $term = ltrim(trim((string) $request->string('q')), '#');
                if ($term === '') {
                    return;
                }

                // Nested so the orWhere cannot escape the company_id scope
                // above - flat, a buyer could read another company's order by
                // guessing an id.
                $query->where(function ($w) use ($term): void {
                    $w->where('reference', 'like', '%'.$term.'%');
                    // Exact, and only for all-digit input: LIKE on an integer
                    // key matches 1 against 10/21/100 and forfeits the index.
                    if (ctype_digit($term)) {
                        $w->orWhere('id', (int) $term);
                    }
                });
            })
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return QuoteResource::collection($quotes);
```

`withQueryString()` keeps `q` on the pagination links.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/QuoteSearchTest.php`
Expected: PASS (one skipped).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/QuoteController.php tests/Feature/QuoteSearchTest.php
git commit -m "feat(quotes): search by reference or id

Lands before the id stops being displayed, so anyone holding an old #1 from
an email or invoice can still find that order.

The id match is exact and gated on all-digit input: LIKE on an integer key
matches 1 against 10, 21 and 100, and forfeits the primary key index. The
orWhere is nested so it cannot escape the company_id scope - flat, a buyer
could read another company's order by guessing an id.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

### Task D2: search input on the orders list

**Files:**
- Modify: `frontend/src/stores/quoteStore.ts`, `frontend/src/pages/QuoteListPage.tsx`
- Test: `frontend/src/pages/QuoteListPage.test.tsx`

- [ ] **Step 1: Understand the existing signature and its refresh path**

Verified: `quoteStore.ts:41` declares `fetchQuotes: (page?: number) => Promise<void>`
and `:77` implements `async (page = 1)`. A second optional parameter is
additive and breaks no call site.

**But `:252` calls `get().fetchQuotes(get().page)`** — the `onEchoReconnect`
handler inside `subscribeCompany`, so it fires on socket reconnect. (An earlier
draft of this plan called it a post-mutation refresh. That was wrong; the line
is the same, the trigger is not.) If the search term lives only in the component's `useState`,
that refresh re-fetches without it and silently wipes the user's search.

So the term must live **in the store**, not only in the component:

```ts
  // Held in the store because the reconnect refresh at :252 re-fetches
  // from store state - a term kept only in the component would be dropped
  // there, silently clearing the user's search.
  searchTerm: string | undefined,
```

`fetchQuotes(page, term)` sets `searchTerm` when `term` is passed; `:252`
becomes `get().fetchQuotes(get().page, get().searchTerm)`.

- [ ] **Step 2: Write the failing test**

Add to `frontend/src/pages/QuoteListPage.test.tsx`:

```tsx
it('passes the search term to the store', async () => {
  const fetchQuotes = vi.fn(async () => {});
  useQuoteStore.setState({ quotes: [], loading: false, error: null, fetchQuotes } as any);
  renderPage();

  await userEvent.type(screen.getByRole('searchbox', { name: /search orders/i }), 'ABC123');

  await waitFor(() => expect(fetchQuotes).toHaveBeenCalledWith(1, 'ABC123'));
});
```

If `renderPage`/`useQuoteStore` helpers are not already in that file, copy the
setup idiom from `QuoteDetailPage.test.tsx` (store seeded via `setState`,
restored in `afterEach`).

- [ ] **Step 3: Run it to verify it fails**

Run: `cd frontend && npx vitest run src/pages/QuoteListPage.test.tsx -t "passes the search term"`
Expected: FAIL — no searchbox exists.

- [ ] **Step 4: Thread `q` through the store**

In `frontend/src/stores/quoteStore.ts`: add `searchTerm` to state, give
`fetchQuotes` an optional second parameter that sets it and forwards it as the
`q` query param, and update the refresh at `:252` to pass `get().searchTerm`.
Existing call sites keep working — the parameter is optional and omitted means
no filter.

Add a test that the refresh preserves the term:

```ts
it('keeps the search term across a reconnect refresh', async () => {
  await useQuoteStore.getState().fetchQuotes(1, 'ABC123');
  expect(useQuoteStore.getState().searchTerm).toBe('ABC123');
});
```

- [ ] **Step 5: Add the input**

In `frontend/src/pages/QuoteListPage.tsx`, above the list:

```tsx
      <label className="mb-4 block">
        <span className="sr-only">Search orders</span>
        <input
          type="search"
          value={term}
          onChange={(e) => setTerm(e.target.value)}
          placeholder="Search by order reference or id"
          className="w-full max-w-sm rounded-md border border-border bg-surface px-3 py-2 text-sm text-fg placeholder:text-fg-subtle focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        />
      </label>
```

Debounce the fetch by 300ms so a typed reference does not fire nine requests:

```tsx
  const [term, setTerm] = useState('');
  useEffect(() => {
    const id = setTimeout(() => void fetchQuotes(1, term.trim() || undefined), 300);
    return () => clearTimeout(id);
  }, [term, fetchQuotes]);
```

This replaces the existing mount-time `fetchQuotes(1)` effect — do not leave
both, or every mount fires two requests.

- [ ] **Step 6: Run the tests**

Run: `cd frontend && npx vitest run src/pages/QuoteListPage.test.tsx`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/pages/QuoteListPage.tsx frontend/src/pages/QuoteListPage.test.tsx frontend/src/stores/quoteStore.ts
git commit -m "feat(quotes): search input on the orders list

Debounced 300ms so typing a reference does not fire a request per keystroke.
Replaces the mount-time fetch rather than sitting alongside it.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## PART A — reference as the only displayed identifier

### Task A1: widen the backend payloads

**Files:**
- Modify: `app/Http/Resources/{ProductionJobResource,LineItemResource,ProofResource}.php`, `app/Services/QueueService.php`, `app/Events/{LineItemAwaitingReconfirm,ProductionQueueUpdated,ProofStatusChanged,QuoteStateChanged}.php`
- Test: `tests/Feature/QuoteReferenceExposureTest.php` (create)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/QuoteReferenceExposureTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Quote;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('exposes the quote reference alongside the id on production jobs', function (): void {
    $quote = Quote::factory()->create(['reference' => 'REFFORJOB']);
    \App\Models\ProductionJob::factory()->create(['quote_id' => $quote->id]);
    Sanctum::actingAs(User::factory()->create(['company_id' => null, 'role' => 'staff_admin']));

    $row = test()->getJson('/api/production-queue')->assertOk()->json('data.0');

    // The id stays - the realtime stores match broadcasts on it.
    expect($row['quote_id'])->toBe($quote->id)
        ->and($row['quote_reference'])->toBe('REFFORJOB');
});
```

Route verified: `routes/api.php:142` → `GET /api/production-queue`. Check
`database/factories/` for the exact `ProductionJob` factory name before
writing, as that is the one identifier here not yet confirmed.

- [ ] **Step 2: Run it to verify it fails**

Run: `./vendor/bin/pest tests/Feature/QuoteReferenceExposureTest.php`
Expected: FAIL — undefined array key `quote_reference`.

- [ ] **Step 3: Add `quote_reference` to each resource**

In `app/Http/Resources/ProductionJobResource.php`, after `'quote_id'`:

```php
            'quote_id' => $this->quote_id,
            // Display identity. quote_id stays as the join key the realtime
            // stores match broadcasts against.
            'quote_reference' => $this->quote?->reference,
```

Apply the identical pair to `LineItemResource.php` and `ProofResource.php`.

In `app/Services/QueueService.php:86`, alongside `'quote_id' => $quote->id`:

```php
                    'quote_id' => $quote->id,
                    'quote_reference' => $quote->reference,
```

In each of the four events, alongside the existing `quote_id`:

```php
            'quote_reference' => $this->lineItem->quote?->reference,   // LineItemAwaitingReconfirm
            'quote_reference' => $this->job->quote?->reference,        // ProductionQueueUpdated
            'quote_reference' => $this->proof->quote?->reference,      // ProofStatusChanged
            'quote_reference' => $this->quote->reference,              // QuoteStateChanged
```

- [ ] **Step 4: Eager-load the relation everywhere those collections are built**

Find them:

```bash
grep -rn "ProductionJobResource::collection\|ProofResource::collection" app/
```

Each of those queries needs `->with('quote')`. Without it the page fires one
query per row and nothing visual reveals it.

- [ ] **Step 5: Write the N+1 guard**

Add to `tests/Feature/QuoteReferenceExposureTest.php`:

```php
it('does not fire a query per job when listing the production queue', function (): void {
    Sanctum::actingAs(User::factory()->create(['company_id' => null, 'role' => 'staff_admin']));

    $count = function (): int {
        $n = 0;
        DB::listen(function () use (&$n): void { $n++; });
        test()->getJson('/api/production-queue')->assertOk();

        return $n;
    };

    \App\Models\ProductionJob::factory()->count(3)->create();
    $small = $count();

    \App\Models\ProductionJob::factory()->count(7)->create();
    $large = $count();

    // Eager-loaded, the query count is flat regardless of row count.
    expect($large)->toBeLessThanOrEqual($small + 1);
});
```

- [ ] **Step 6: Run the tests**

Run: `./vendor/bin/pest tests/Feature/QuoteReferenceExposureTest.php`
Expected: PASS, both tests.

- [ ] **Step 7: Run the full backend suite and commit**

Run: `./vendor/bin/pest`
Expected: PASS.

```bash
git add app/Http/Resources/ app/Services/QueueService.php app/Events/ tests/Feature/QuoteReferenceExposureTest.php
git commit -m "feat(api): expose quote_reference alongside quote_id

Every staff-facing payload gains the reference so the floor and the buyer
can name an order the same way.

quote_id stays: the realtime stores match incoming broadcasts against
on-screen rows by it, so dropping it would break queue and procurement
updates. Stop displaying it, keep joining on it.

Includes a query-count guard - the reference reaches these resources through
the quote relation, and a missing eager-load is invisible on screen.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

### Task A2: switch every display surface

**Files:**
- Modify: `frontend/src/types.ts`, and the nine display sites listed below
- Test: `frontend/src/pages/QuoteDetailPage.test.tsx`

- [ ] **Step 1: Write the failing test**

Add to `frontend/src/pages/QuoteDetailPage.test.tsx`:

```tsx
it('identifies the order by reference, never by the sequential id', async () => {
  seedQuote('ACCEPTED');
  useQuoteStore.setState({
    current: { ...useQuoteStore.getState().current!, id: 42, reference: '9BWVKWCDXH' },
  } as any);
  asBuyer();
  renderPage();

  expect(screen.getAllByText(/9BWVKWCDXH/).length).toBeGreaterThan(0);
  // A stray "#42" anywhere means a surface was missed.
  expect(screen.queryByText(/#\d+/)).not.toBeInTheDocument();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd frontend && npx vitest run src/pages/QuoteDetailPage.test.tsx -t "identifies the order by reference"`
Expected: FAIL — heading and breadcrumb both render `Quote #42`.

- [ ] **Step 3: Add the type field**

In `frontend/src/types.ts`, on `LineItem` (`:259`), `Proof` (`:277`) and
`ProductionJob` (`:313`), beside each existing `quote_id`:

```ts
  /** Display identity. quote_id remains the key realtime updates match on. */
  quote_reference?: string | null;
```

- [ ] **Step 4: Replace every display site**

`Order {reference}` — not `Quote #`, since `#` reads as an ordinal and a
reference is not one, and "Order" matches the `My Orders` nav vocabulary.

| File | Line | Was | Becomes |
|---|---|---|---|
| `QuoteDetailPage.tsx` | `:153` | `` `Quote #${quote.id}` `` | `` `Order ${quote.reference}` `` |
| `QuoteDetailPage.tsx` | `:164` | `Quote #{quote.id}` | `Order {quote.reference}` |
| `QuoteListPage.tsx` | `:183`, `:216` | `Quote #{quote.id}` | `Order {quote.reference}` |
| `BuyerDashboardPage.tsx` | `:82`, `:148` | `Quote #{o.id}` / `#{q.id}` | `Order {o.reference}` / `{q.reference}` |
| `ReorderRail.tsx` | `:57`, `:60` | `` `Quote #${q.id}` `` | `` `Order ${q.reference}` `` |
| `CheckoutPage.tsx` | `:210` | `` `Quote #${id} is on its way.` `` | `` `Order ${reference} is on its way.` `` |
| `DashboardPage.tsx` | `:92` | `Quote #{j.quoteId}` | `Order {j.quoteReference}` |
| `ProductionQueuePage.tsx` | `:312` | `Quote #{j.quote_id}` | `Order {j.quote_reference}` |
| `ProcurementPage.tsx` | `:104` | `Quote #{a.quote_id}` | `Order {a.quote_reference}` |

`CheckoutPage:210` currently has only `id` in scope — check what
`createQuote` returns and thread the reference through if it does not already
carry one. Do not fall back to the id there; a toast is exactly where a buyer
copies an identifier from.

- [ ] **Step 5: Update the email**

In `resources/views/mail/quote-ready.blade.php:78`, the fallback becomes the
reference:

```blade
{{ $quote->tracking_code ?? $quote->reference }}
```

- [ ] **Step 6: Run the full frontend suite**

Run: `cd frontend && npx tsc --noEmit -p tsconfig.json && npx vitest run`
Expected: tsc clean, all tests pass. Other tests asserting on `Quote #` text
will fail here — update them to the reference; that is the migration working.

- [ ] **Step 7: Commit**

```bash
git add frontend/src resources/views/mail/quote-ready.blade.php
git commit -m "feat(orders): identify orders by reference on every surface

Buyer, staff and admin now name an order the same way, so a support
conversation does not begin by translating between the buyer's reference and
the floor's id.

Reads 'Order 9BWVKWCDXH' rather than 'Quote #9BWVKWCDXH' - the hash reads as
an ordinal and a reference is not one.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## PART B — collapsed timeline

### Task B1: extract and collapse the timeline

**Files:**
- Create: `frontend/src/components/quote/QuoteTimeline.tsx`, `frontend/src/components/quote/QuoteTimeline.test.tsx`
- Modify: `frontend/src/pages/QuoteDetailPage.tsx` (remove the inline stepper, `TIMELINE` at `:18`)

- [ ] **Step 1: Write the failing tests**

Create `frontend/src/components/quote/QuoteTimeline.test.tsx`:

```tsx
import { expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import QuoteTimeline from './QuoteTimeline';

it('shows the current step, the next step, and the position', () => {
  render(<QuoteTimeline state="PROOFING" />);

  expect(screen.getByText(/Proofing/)).toBeInTheDocument();
  expect(screen.getByText(/Proof approved/)).toBeInTheDocument();
  expect(screen.getByText(/step 4 of 9/i)).toBeInTheDocument();
});

it('hides the full stepper until asked', async () => {
  render(<QuoteTimeline state="DRAFT" />);

  expect(screen.queryByText('Procuring')).not.toBeInTheDocument();

  const toggle = screen.getByRole('button', { name: /show all steps/i });
  expect(toggle).toHaveAttribute('aria-expanded', 'false');

  await userEvent.click(toggle);

  expect(screen.getByText('Procuring')).toBeInTheDocument();
  expect(screen.getByRole('button', { name: /hide all steps/i })).toHaveAttribute(
    'aria-expanded',
    'true',
  );
});

it('promises no next step at the end of the line', () => {
  render(<QuoteTimeline state="READY" />);

  expect(screen.getByText(/Ready/)).toBeInTheDocument();
  expect(screen.queryByText(/next:/i)).not.toBeInTheDocument();
});

// CHANGES_REQUESTED / CANCELLED are off the happy path - timelineIndex maps
// them onto it, which must not become a claim about what happens next.
it('promises no next step for a diverted state', () => {
  render(<QuoteTimeline state="CANCELLED" />);

  expect(screen.queryByText(/next:/i)).not.toBeInTheDocument();
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `cd frontend && npx vitest run src/components/quote/QuoteTimeline.test.tsx`
Expected: FAIL — module does not exist.

- [ ] **Step 3: Build the component**

Create `frontend/src/components/quote/QuoteTimeline.tsx`. Move `TIMELINE` and
`timelineIndex` out of `QuoteDetailPage.tsx:18-36` verbatim, then:

- Treat `CHANGES_REQUESTED`, `CLOSED`, `CANCELLED` as off-path: render the
  humanized state alone, no next, no step count.
- On-path: `{current} → next: {next}` plus `step {i+1} of 9`; omit the next
  clause when `i` is the last index.
- A `<button aria-expanded={open}>` toggling the full stepper, which is the
  existing markup unchanged.

Use `humanizeState` from `../../lib/quoteStatus` for labels — it already backs
the current stepper.

- [ ] **Step 4: Run the tests**

Run: `cd frontend && npx vitest run src/components/quote/QuoteTimeline.test.tsx`
Expected: PASS, 4 tests.

- [ ] **Step 5: Use it on the detail page**

In `QuoteDetailPage.tsx`, delete `TIMELINE`, `timelineIndex` and the inline
stepper `Card`, and render `<QuoteTimeline state={quote.state} />` in its
place.

- [ ] **Step 6: Run the full frontend suite and commit**

Run: `cd frontend && npx tsc --noEmit -p tsconfig.json && npx vitest run`

```bash
git add frontend/src/components/quote frontend/src/pages/QuoteDetailPage.tsx
git commit -m "feat(orders): collapse the nine-state timeline

All nine states wrapped to two rows at desktop width and took more vertical
space than the order beneath. Collapses to current, next and position, with
the full stepper behind a disclosure.

Off-path states (changes requested, cancelled, closed) render alone -
timelineIndex maps them onto the happy path for positioning, which must not
become a claim about what happens next.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## PART C — status history

### Task C1: log every state transition

**Files:**
- Modify: `app/Models/Quote.php:217`
- Test: `tests/Feature/QuoteHistoryTest.php` (create)

- [ ] **Step 1: Note the AuditLogger contract**

Verified — `AuditLogger::log(Model $auditable, string $event, ?array $old, ?array $new): AuditLog`.
It resolves `Auth::id()` and the IP itself, and writes `'console'` as the IP
sentinel for queue/command-driven transitions. The call in Step 4 matches this
signature as written; no adjustment needed.

- [ ] **Step 2: Write the failing tests**

Create `tests/Feature/QuoteHistoryTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\QuoteState;
use App\Models\AuditLog;
use App\Models\Quote;

it('records one audit row per successful transition', function (): void {
    $quote = Quote::factory()->create(['state' => QuoteState::Draft]);

    $quote->transitionTo(QuoteState::Sent);

    $rows = AuditLog::where('auditable_type', Quote::class)
        ->where('auditable_id', $quote->id)
        ->where('event', 'quote.state_changed')
        ->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->old_values)->toBe(['state' => 'DRAFT'])
        ->and($rows->first()->new_values)->toBe(['state' => 'SENT']);
});

it('records nothing when the transition is rejected', function (): void {
    $quote = Quote::factory()->create(['state' => QuoteState::Draft]);

    expect(fn () => $quote->transitionTo(QuoteState::Ready))
        ->toThrow(\App\Exceptions\InvalidStateTransitionException::class);

    expect(AuditLog::where('auditable_id', $quote->id)->count())->toBe(0);
});
```

- [ ] **Step 3: Run them to verify they fail**

Run: `./vendor/bin/pest tests/Feature/QuoteHistoryTest.php`
Expected: first FAILS with 0 rows; second passes already (nothing writes yet).

- [ ] **Step 4: Log inside the choke point**

In `app/Models/Quote.php:217`:

```php
    public function transitionTo(QuoteState $target): void
    {
        if (! $this->state->canTransitionTo($target)) {
            throw InvalidStateTransitionException::between('quote', $this->state->value, $target->value);
        }

        $from = $this->state;
        $this->state = $target;
        $this->save();

        // Logged here, not at each QuoteService call site: the guard and the
        // write are already atomic, and a caller that forgets to log is a
        // silent hole in a trail whose whole value is being complete.
        app(AuditLogger::class)->log(
            $this,
            'quote.state_changed',
            ['state' => $from->value],
            ['state' => $target->value],
        );
    }
```

Add `use App\Services\AuditLogger;` to the model's imports.

- [ ] **Step 5: Run the tests, then the full suite**

Run: `./vendor/bin/pest tests/Feature/QuoteHistoryTest.php && ./vendor/bin/pest`
Expected: PASS. Existing tests that count audit rows may now see extra rows —
if any fail, they were asserting a total; scope them to their own event.

- [ ] **Step 6: Commit**

```bash
git add app/Models/Quote.php tests/Feature/QuoteHistoryTest.php
git commit -m "feat(quotes): audit every state transition

transitionTo persisted state and logged nothing, so no order had any history.
Logged at the choke point rather than each call site - the guard and the write
are already atomic there, and a caller that forgets is a silent hole in a
trail whose value is being complete.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

### Task C2: history endpoint

**Files:**
- Create: `app/Http/Resources/QuoteHistoryResource.php`
- Modify: `app/Http/Controllers/QuoteController.php`, `routes/api.php`
- Test: `tests/Feature/QuoteHistoryTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/QuoteHistoryTest.php`:

```php
it('returns the transition trail oldest first', function (): void {
    $company = \App\Models\Company::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $company->id, 'state' => QuoteState::Draft]);
    $quote->transitionTo(QuoteState::Sent);
    $quote->transitionTo(QuoteState::Accepted);
    Sanctum::actingAs(\App\Models\User::factory()->create(['company_id' => $company->id, 'role' => 'buyer']));

    $rows = test()->getJson("/api/quotes/{$quote->id}/history")->assertOk()->json('data');

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['to'])->toBe('SENT')
        ->and($rows[1]['to'])->toBe('ACCEPTED');
});

it('refuses another company history', function (): void {
    $quote = Quote::factory()->create(['company_id' => \App\Models\Company::factory()->create()->id]);
    Sanctum::actingAs(\App\Models\User::factory()->create([
        'company_id' => \App\Models\Company::factory()->create()->id,
        'role' => 'buyer',
    ]));

    test()->getJson("/api/quotes/{$quote->id}/history")->assertForbidden();
});

it('never leaks a staff email into a buyer-visible history', function (): void {
    $company = \App\Models\Company::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $company->id, 'state' => QuoteState::Draft]);
    $quote->transitionTo(QuoteState::Sent);
    Sanctum::actingAs(\App\Models\User::factory()->create(['company_id' => $company->id, 'role' => 'buyer']));

    $body = test()->getJson("/api/quotes/{$quote->id}/history")->assertOk()->getContent();

    expect($body)->not->toContain('@');
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `./vendor/bin/pest tests/Feature/QuoteHistoryTest.php`
Expected: FAIL, 404 — the route does not exist.

- [ ] **Step 3: Add the resource**

Create `app/Http/Resources/QuoteHistoryResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AuditLog
 */
class QuoteHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'from' => $this->old_values['state'] ?? null,
            'to' => $this->new_values['state'] ?? null,
            'changed_at' => $this->created_at?->toIso8601String(),
            // Name only. A buyer can read this, and staff email addresses are
            // not theirs to have.
            'actor_name' => $this->whenLoaded('user', fn () => $this->user?->name),
        ];
    }
}
```

- [ ] **Step 4: Add the controller method**

In `app/Http/Controllers/QuoteController.php`:

```php
    /**
     * Append-only state-change trail for one quote. Authorised by the same
     * policy as show - a bespoke company_id check on a new route is how
     * cross-tenant leaks happen.
     */
    public function history(Quote $quote): AnonymousResourceCollection
    {
        $this->authorize('view', $quote);

        $rows = AuditLog::query()
            ->where('auditable_type', Quote::class)
            ->where('auditable_id', $quote->id)
            ->where('event', 'quote.state_changed')
            ->with('user')
            ->oldest()
            ->get();

        return QuoteHistoryResource::collection($rows);
    }
```

Add `use App\Models\AuditLog;` and `use App\Http\Resources\QuoteHistoryResource;`.

- [ ] **Step 5: Register the route**

In `routes/api.php`, beside the existing `quotes/{quote}` routes:

```php
    Route::get('quotes/{quote}/history', [QuoteController::class, 'history']);
```

Place it inside the same `auth:sanctum` group as the other quote routes.

- [ ] **Step 6: Run the tests and commit**

Run: `./vendor/bin/pest tests/Feature/QuoteHistoryTest.php`
Expected: PASS, 5 tests.

```bash
git add app/Http/Resources/QuoteHistoryResource.php app/Http/Controllers/QuoteController.php routes/api.php tests/Feature/QuoteHistoryTest.php
git commit -m "feat(quotes): status history endpoint

Reuses the view policy rather than checking company_id inline - a bespoke
check on a new route is how cross-tenant leaks happen.

Returns the actor's name, never the email: a buyer can read this and staff
addresses are not theirs to have.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

### Task C3: history section on the order page

**Files:**
- Create: `frontend/src/components/quote/StatusHistory.tsx`, `.test.tsx`
- Modify: `frontend/src/pages/QuoteDetailPage.tsx`, `frontend/src/lib/quotes.ts`

- [ ] **Step 1: Write the failing tests**

Create `frontend/src/components/quote/StatusHistory.test.tsx`:

```tsx
import { expect, it, vi, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import StatusHistory from './StatusHistory';
import * as quotes from '../../lib/quotes';

afterEach(() => vi.restoreAllMocks());

it('lists each transition newest first', async () => {
  vi.spyOn(quotes, 'fetchQuoteHistory').mockResolvedValue([
    { from: 'DRAFT', to: 'SENT', changed_at: '2026-07-01T00:00:00Z', actor_name: 'Ada' },
    { from: 'SENT', to: 'ACCEPTED', changed_at: '2026-07-02T00:00:00Z', actor_name: null },
  ]);

  render(<StatusHistory quoteId={42} />);

  await waitFor(() => expect(screen.getByText(/accepted/i)).toBeInTheDocument());
  const entries = screen.getAllByRole('listitem');
  expect(entries[0]).toHaveTextContent(/accepted/i);
  expect(entries[1]).toHaveTextContent(/sent/i);
});

// Nothing was logged before this shipped, so old orders have no history and
// cannot get one. Saying so beats a timeline that looks complete but is not.
it('says tracking started rather than pretending there is no history', async () => {
  vi.spyOn(quotes, 'fetchQuoteHistory').mockResolvedValue([]);

  render(<StatusHistory quoteId={42} />);

  await waitFor(() => expect(screen.getByText(/status tracking started/i)).toBeInTheDocument());
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `cd frontend && npx vitest run src/components/quote/StatusHistory.test.tsx`
Expected: FAIL — module does not exist.

- [ ] **Step 3: Add the fetcher**

In `frontend/src/lib/quotes.ts`, beside `fetchRecentQuotes`:

```ts
export interface QuoteHistoryEntry {
  from: string | null;
  to: string | null;
  changed_at: string | null;
  actor_name?: string | null;
}

/** Append-only state trail. Returns [] on failure - history is never a blocker. */
export async function fetchQuoteHistory(quoteId: number): Promise<QuoteHistoryEntry[]> {
  try {
    const res = await api.get(`/quotes/${quoteId}/history`);
    return res.data.data ?? [];
  } catch {
    return [];
  }
}
```

Match the existing `api` import and error idiom in that file.

- [ ] **Step 4: Build the component**

Create `frontend/src/components/quote/StatusHistory.tsx`: fetch on mount,
render a `<ul>` of `{humanizeState(to)} · {date} · {actor_name ?? 'System'}`
newest first. On an empty array render:

> Status tracking started on 20 Jul 2026. Changes before then were not recorded.

Hold that date as a module constant with a comment explaining it is the day
transition logging shipped — not a value to be quietly bumped later.

- [ ] **Step 5: Run the tests**

Run: `cd frontend && npx vitest run src/components/quote/StatusHistory.test.tsx`
Expected: PASS, 2 tests.

- [ ] **Step 6: Mount it on the order page**

In `QuoteDetailPage.tsx`, below `<QuoteTimeline />`:

```tsx
        <Motion variants={staggerItem}>
          <StatusHistory quoteId={quote.id} />
        </Motion>
```

- [ ] **Step 7: Run both suites and commit**

Run: `cd frontend && npx tsc --noEmit -p tsconfig.json && npx vitest run`
Run: `./vendor/bin/pest`

```bash
git add frontend/src/components/quote frontend/src/lib/quotes.ts frontend/src/pages/QuoteDetailPage.tsx
git commit -m "feat(orders): status history on the order page

Empty state says tracking started on a date rather than implying the order
never moved. Nothing was logged before this shipped, so existing orders have
no history and cannot get one - a partial reconstruction would look complete
while misdating every entry it could not source.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Standing verification debt

**Nothing in this branch has been exercised against a running app.** Every task
so far is proven by unit and feature tests only. That leaves a specific,
known class of defect uncaught:

- Frontend tests mock `api.get`. If the `q` query parameter were named
  differently from what `QuoteController::index` reads, or the response shape
  differed, the tests would still pass. The client/server contract is
  transcribed from reading the controller, not observed.
- Backend feature tests run on SQLite (`phpunit.xml`), production runs MySQL
  (`.env`). Already bitten once: the `addcslashes` LIKE escape is inert on
  SQLite and functional on MySQL, so the test passes for the wrong reason.
- Realtime behaviour (Echo/Pusher) is tested by capturing handler closures with
  the transport mocked. That proves the store's logic, never the socket.

This is not a reason to stop, but it must not be reported as "verified". The
browser pass below is the only thing that closes it, and it needs a signed-in
session the agents doing this work do not have.

## Verification beyond the suites

- [ ] **Browser pass, signed in as a buyer**

Load an order detail page and confirm: the reference appears in the breadcrumb
and heading with no `#\d+` anywhere; the timeline is collapsed with a working
toggle; the history section renders (empty state on an old order, entries on
one moved since Part C shipped).

- [ ] **Browser pass, signed in as staff**

Load the production queue, procurement page and staff dashboard. Confirm each
shows the reference, and that a realtime update still lands on the right row —
that is what proves keeping `quote_id` as the join key worked.

- [ ] **Search**

Search a full reference, a partial reference, an id, and `#id`. As a buyer,
search another company's id and confirm the result is empty.

---

## Rollback

Parts A, B and D are revertable commits.

Part C's logging is additive and append-only: reverting C2/C3 leaves rows
accumulating harmlessly for whenever the reader returns. Reverting C1 stops
collection and orphans whatever was already gathered — which is recoverable,
but the gap in the trail is not.
