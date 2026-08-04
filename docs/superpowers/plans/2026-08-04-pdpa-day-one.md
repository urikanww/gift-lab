# PDPA Day-One Essentials Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Publish a Privacy Policy page with a DPO contact, and record a server-enforced, version-stamped consent at registration and a recipient-consent acknowledgement at checkout.

**Architecture:** Additive migration for four nullable columns (two on `users`, two on `quotes`); a `config/privacy.php` version constant every consent stamps. Backend enforces consent in the two FormRequests (`RegisterRequest`, `StoreQuoteRequest`) and stamps timestamps in `AuthController::register` and `QuoteController::store`. Frontend adds a public `/privacy` page, a footer link, and a required checkbox on the register and checkout forms.

**Tech Stack:** Laravel 11 (Pest), React + TypeScript (Vitest), Zustand stores, react-router.

**Spec:** `docs/superpowers/specs/2026-08-04-pdpa-day-one-essentials-design.md`

**Branch:** `feat/pdpa-day-one` (already created).

**Standing context:**
- Both suites green at branch point. Keep them green.
- Feature tests run SQLite; production runs MySQL. `Blueprint::after()` is a no-op on SQLite — harmless.
- Adding required consent fields **breaks existing buyer POST `/api/register` and `/api/quotes` tests**. Tasks 2 and 3 fix them; Task 7 sweeps for stragglers.

---

## File Structure

**Backend**
- `database/migrations/2026_08_04_000001_add_pdpa_consent_columns.php` — new (four columns)
- `config/privacy.php` — new (policy version constant)
- `app/Models/User.php` — add `consented_at` datetime cast
- `app/Models/Quote.php` — add `recipient_consent_ack_at` datetime cast
- `app/Http/Requests/RegisterRequest.php` — add `consent` rule
- `app/Http/Controllers/AuthController.php` — stamp consent on register
- `app/Http/Requests/StoreQuoteRequest.php` — add `recipient_consent` rule
- `app/Http/Controllers/QuoteController.php` — stamp recipient consent on store

**Frontend**
- `frontend/src/pages/PrivacyPolicyPage.tsx` — new (static policy page)
- `frontend/src/App.tsx` — add `/privacy` route
- `frontend/src/components/SiteFooter.tsx` — add `/privacy` link
- `frontend/src/pages/RegisterPage.tsx` — consent checkbox
- `frontend/src/stores/authStore.ts` — add `consent` to `RegisterPayload`
- `frontend/src/pages/CheckoutPage.tsx` — recipient-consent checkbox
- `frontend/src/stores/quoteStore.ts` — add `recipientConsent` param to `createQuote`

**Tests**
- `tests/Feature/PdpaConsentTest.php` — new (schema + register + checkout consent)
- `tests/Feature/RegistrationTest.php` — updated (add `consent`)
- `tests/Feature/CheckoutShippingTest.php` — updated (add `recipient_consent`)
- `frontend/src/pages/PrivacyPolicyPage.test.tsx` — new
- `frontend/src/pages/RegisterPage.test.tsx` — consent-gate test (create if absent)
- `frontend/src/pages/CheckoutPage.test.tsx` — consent-gate test (create if absent)
- `frontend/src/components/SiteFooter.test.tsx` — updated (privacy link)

---

## Task 1: Data layer — migration, config, model casts

**Files:**
- Create: `database/migrations/2026_08_04_000001_add_pdpa_consent_columns.php`
- Create: `config/privacy.php`
- Modify: `app/Models/User.php:48-53` (casts array)
- Modify: `app/Models/Quote.php` (casts array)
- Test: `tests/Feature/PdpaConsentTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PdpaConsentTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('has the pdpa consent columns and a policy version', function (): void {
    expect(Schema::hasColumn('users', 'consented_at'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'consent_policy_version'))->toBeTrue()
        ->and(Schema::hasColumn('quotes', 'recipient_consent_ack_at'))->toBeTrue()
        ->and(Schema::hasColumn('quotes', 'recipient_consent_version'))->toBeTrue()
        ->and(config('privacy.version'))->not->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PdpaConsentTest`
Expected: FAIL — columns absent / `config('privacy.version')` is null.

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_08_04_000001_add_pdpa_consent_columns.php`:

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
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('consented_at')->nullable()->after('password');
            $table->string('consent_policy_version', 32)->nullable()->after('consented_at');
        });

        Schema::table('quotes', function (Blueprint $table): void {
            $table->timestamp('recipient_consent_ack_at')->nullable();
            $table->string('recipient_consent_version', 32)->nullable()->after('recipient_consent_ack_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['consented_at', 'consent_policy_version']);
        });

        Schema::table('quotes', function (Blueprint $table): void {
            $table->dropColumn(['recipient_consent_ack_at', 'recipient_consent_version']);
        });
    }
};
```

- [ ] **Step 4: Create the config**

Create `config/privacy.php`:

```php
<?php

declare(strict_types=1);

return [
    // Bump when the Privacy Policy materially changes. Every consent records the
    // value in force at the time, so a future change can trigger re-consent.
    'version' => '2026-08-04',
];
```

- [ ] **Step 5: Add the datetime casts**

In `app/Models/User.php`, add to the `casts()` array (after `'email_verified_at' => 'datetime',`):

```php
            'consented_at' => 'datetime',
```

In `app/Models/Quote.php`, add to its `casts()` array:

```php
            'recipient_consent_ack_at' => 'datetime',
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=PdpaConsentTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_08_04_000001_add_pdpa_consent_columns.php config/privacy.php app/Models/User.php app/Models/Quote.php tests/Feature/PdpaConsentTest.php
git commit -m "feat(pdpa): consent columns + policy version constant"
```

---

## Task 2: Registration consent (backend)

**Files:**
- Modify: `app/Http/Requests/RegisterRequest.php:37-45` (rules)
- Modify: `app/Http/Controllers/AuthController.php:61-71` (stamp on created user)
- Test: `tests/Feature/PdpaConsentTest.php` (append)
- Modify: `tests/Feature/RegistrationTest.php:14-21` (add consent to happy path)

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/PdpaConsentTest.php`:

```php
use App\Models\User;

it('rejects registration without consent', function (): void {
    $this->postJson('/api/register', [
        'name' => 'Jane Tan',
        'email' => 'noconsent@acme.example',
        'password' => 'super-secret-1',
        'password_confirmation' => 'super-secret-1',
        'company_name' => 'Acme Pte Ltd',
    ])->assertStatus(422)->assertJsonValidationErrors('consent');
});

it('stamps consented_at and the policy version on register', function (): void {
    $this->withHeader('Referer', 'http://localhost')->postJson('/api/register', [
        'name' => 'Jane Tan',
        'email' => 'consented@acme.example',
        'password' => 'super-secret-1',
        'password_confirmation' => 'super-secret-1',
        'company_name' => 'Acme Pte Ltd',
        'consent' => true,
    ])->assertCreated();

    $user = User::where('email', 'consented@acme.example')->firstOrFail();
    expect($user->consented_at)->not->toBeNull()
        ->and($user->consent_policy_version)->toBe(config('privacy.version'));
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=PdpaConsentTest`
Expected: FAIL — no `consent` validation error (registration succeeds without it); `consented_at` null.

- [ ] **Step 3: Add the validation rule**

In `app/Http/Requests/RegisterRequest.php`, add to the `rules()` array (after the `company_address` line):

```php
            // PDPA s.13-14: explicit, recorded consent at the point of collection.
            'consent' => ['required', 'accepted'],
```

- [ ] **Step 4: Stamp the consent on the created user**

In `app/Http/Controllers/AuthController.php`, inside `register()`, replace the `$user = User::create([...]);` block (lines 61-67) so the created user is stamped before `return $user;`. Add immediately after the `User::create(...)` call, still inside the transaction closure:

```php
            $user->forceFill([
                'consented_at' => now(),
                'consent_policy_version' => config('privacy.version'),
            ])->save();
```

(`forceFill` is used because these are system-set, not client-supplied, and are absent from `$fillable`.)

- [ ] **Step 5: Fix the existing registration happy-path test**

In `tests/Feature/RegistrationTest.php`, the first test (`registers a new corporate buyer with their company`) now 422s without consent. Add `'consent' => true,` to its payload (after `'company_phone' => '+65 6123 4567',` on line 20):

```php
        'company_phone' => '+65 6123 4567',
        'consent' => true,
```

The other three RegistrationTest cases assert 422/403 for other reasons and still pass (a missing `consent` only adds another validation error alongside the asserted one), so leave them unchanged.

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=PdpaConsentTest && php artisan test --filter=RegistrationTest`
Expected: PASS (all).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Requests/RegisterRequest.php app/Http/Controllers/AuthController.php tests/Feature/PdpaConsentTest.php tests/Feature/RegistrationTest.php
git commit -m "feat(pdpa): require + record consent at registration"
```

---

## Task 3: Checkout consent (backend)

**Files:**
- Modify: `app/Http/Requests/StoreQuoteRequest.php:51-52` (rules — add recipient_consent)
- Modify: `app/Http/Controllers/QuoteController.php:208-215` (stamp after create)
- Test: `tests/Feature/PdpaConsentTest.php` (append)
- Modify: `tests/Feature/CheckoutShippingTest.php` (add recipient_consent to buyer POSTs)

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/PdpaConsentTest.php`:

```php
use App\Models\Company;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Variant;
use Laravel\Sanctum\Sanctum;

function pdpaLineItems(): array
{
    $product = Product::factory()->create(['publish_state' => 'PUBLISHED']);
    Variant::factory()->create(['product_id' => $product->id]);

    return [['product_id' => $product->id, 'variant_id' => null, 'qty' => 1]];
}

function pdpaShipping(): array
{
    return [
        'recipient_name' => 'Rachel Tan',
        'phone' => '+6591234567',
        'line1' => '1 Marina Blvd',
        'postal_code' => '018989',
    ];
}

it('rejects a buyer checkout without recipient consent', function (): void {
    seedPricing();
    $company = Company::factory()->create();
    Sanctum::actingAs(User::factory()->create(['company_id' => $company->id, 'role' => 'buyer']));

    $this->postJson('/api/quotes', [
        'company_id' => $company->id,
        'line_items' => pdpaLineItems(),
        'shipping_address' => pdpaShipping(),
    ])->assertStatus(422)->assertJsonValidationErrors('recipient_consent');
});

it('stamps recipient consent on a buyer checkout', function (): void {
    seedPricing();
    $company = Company::factory()->create();
    Sanctum::actingAs(User::factory()->create(['company_id' => $company->id, 'role' => 'buyer']));

    $id = $this->postJson('/api/quotes', [
        'company_id' => $company->id,
        'line_items' => pdpaLineItems(),
        'shipping_address' => pdpaShipping(),
        'recipient_consent' => true,
    ])->assertCreated()->json('data.id');

    $quote = Quote::find($id);
    expect($quote->recipient_consent_ack_at)->not->toBeNull()
        ->and($quote->recipient_consent_version)->toBe(config('privacy.version'));
});

it('lets staff create a quote without recipient consent', function (): void {
    seedPricing();
    $company = Company::factory()->create(['address' => '10 Anson Rd']);
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $id = $this->postJson('/api/quotes', [
        'company_id' => $company->id,
        'line_items' => pdpaLineItems(),
    ])->assertCreated()->json('data.id');

    expect(Quote::find($id)->recipient_consent_ack_at)->toBeNull();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=PdpaConsentTest`
Expected: FAIL — buyer checkout succeeds without `recipient_consent`; ack column null.

- [ ] **Step 3: Add the validation rule**

In `app/Http/Requests/StoreQuoteRequest.php`, add to the `rules()` array (immediately after the `'company_id' => [...]` line at :52). `Rule` is already imported:

```php
            // PDPA: buyer confirms they may share the recipient's delivery
            // details. Required for buyers (mirrors the shipping_address
            // requiredIf); staff raising a quote on a company's behalf are exempt.
            'recipient_consent' => [Rule::requiredIf(! ($this->user()?->isStaff() ?? false)), 'accepted'],
```

- [ ] **Step 4: Stamp the consent after create**

In `app/Http/Controllers/QuoteController.php`, inside `store()`, after the `$quote = $this->quotes->create(...)` block (after line 215) and before the `return`:

```php
        // PDPA: record the buyer's recipient-consent acknowledgement against the
        // order it was made for. Staff-raised quotes never send it (requiredIf).
        if ($request->boolean('recipient_consent')) {
            $quote->forceFill([
                'recipient_consent_ack_at' => now(),
                'recipient_consent_version' => config('privacy.version'),
            ])->save();
        }
```

- [ ] **Step 5: Fix the existing checkout tests**

In `tests/Feature/CheckoutShippingTest.php`, the two **buyer** POSTs that expect `assertCreated()` now 422 without consent. Add `'recipient_consent' => true,` to each:

- `snapshots the shipping address onto the quote` (the array at lines 49-52) — add after the `shipping_address` line.
- `keeps the order address unchanged when a saved address is later edited` (the array at lines 68-71) — add after the `shipping_address` line.

Leave `rejects a buyer checkout with no shipping address` unchanged: it already asserts 422 on `shipping_address`, and a missing `recipient_consent` only adds a second validation error — the assertion still holds. Leave the staff test unchanged (staff are exempt).

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=PdpaConsentTest && php artisan test --filter=CheckoutShippingTest`
Expected: PASS (all).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Requests/StoreQuoteRequest.php app/Http/Controllers/QuoteController.php tests/Feature/PdpaConsentTest.php tests/Feature/CheckoutShippingTest.php
git commit -m "feat(pdpa): require + record recipient consent at checkout"
```

---

## Task 4: Privacy Policy page + route + footer link (frontend)

**Files:**
- Create: `frontend/src/pages/PrivacyPolicyPage.tsx`
- Create: `frontend/src/pages/PrivacyPolicyPage.test.tsx`
- Modify: `frontend/src/App.tsx:53` (lazy import) and `:154` (route)
- Modify: `frontend/src/components/SiteFooter.tsx:12-17` (SHOP_LINKS neighbour — add a legal link)
- Modify: `frontend/src/components/SiteFooter.test.tsx`

- [ ] **Step 1: Write the failing page test**

Create `frontend/src/pages/PrivacyPolicyPage.test.tsx`:

```tsx
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import PrivacyPolicyPage from './PrivacyPolicyPage';

describe('PrivacyPolicyPage', () => {
  it('renders the policy heading and a DPO contact', () => {
    render(
      <MemoryRouter>
        <PrivacyPolicyPage />
      </MemoryRouter>,
    );
    expect(screen.getByRole('heading', { name: /privacy policy/i })).toBeInTheDocument();
    expect(screen.getByText(/data protection officer/i)).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd frontend && npx vitest run src/pages/PrivacyPolicyPage.test.tsx`
Expected: FAIL — module not found.

- [ ] **Step 3: Create the page**

Create `frontend/src/pages/PrivacyPolicyPage.tsx`. Placeholders in `[SQUARE BRACKETS]` are for the owner + legal to fill before launch:

```tsx
/**
 * Public Privacy Policy (PDPA ss.11-12). Static content — no data fetch. The
 * bracketed values are placeholders for the business/legal team to complete
 * before launch; the page structure and the DPO contact block are the fixed
 * requirement.
 */
export default function PrivacyPolicyPage() {
  return (
    <article className="mx-auto max-w-content px-4 py-10 sm:px-6">
      <h1 className="font-display text-3xl text-fg">Privacy Policy</h1>
      <p className="mt-2 text-sm text-fg-muted">Last updated: [DATE]</p>

      <div className="mt-8 flex flex-col gap-6 text-sm leading-relaxed text-fg-muted">
        <section>
          <h2 className="mb-2 font-display text-xl text-fg">Who we are</h2>
          <p>
            This policy explains how [COMPANY LEGAL NAME] ("we") collects, uses,
            discloses, and protects personal data in line with Singapore's
            Personal Data Protection Act (PDPA).
          </p>
        </section>

        <section>
          <h2 className="mb-2 font-display text-xl text-fg">What we collect</h2>
          <p>
            Account details you provide at registration (name, work email,
            phone, company), and the delivery details you enter at checkout for
            each order recipient (name, phone, address).
          </p>
        </section>

        <section>
          <h2 className="mb-2 font-display text-xl text-fg">Why we collect it</h2>
          <p>
            To create and manage your account, prepare and fulfil your orders,
            arrange delivery through our courier partner, and communicate with
            you about your orders.
          </p>
        </section>

        <section>
          <h2 className="mb-2 font-display text-xl text-fg">Who we share it with</h2>
          <p>
            Delivery details are shared with our courier partner solely to
            deliver your order. We do not sell personal data.
          </p>
        </section>

        <section>
          <h2 className="mb-2 font-display text-xl text-fg">How long we keep it</h2>
          <p>
            We retain personal data only as long as needed for the purposes above
            or as required by law. [RETENTION SUMMARY — to be confirmed with legal.]
          </p>
        </section>

        <section>
          <h2 className="mb-2 font-display text-xl text-fg">Your rights</h2>
          <p>
            You may request access to, or correction of, your personal data, and
            you may withdraw consent, by contacting our Data Protection Officer.
          </p>
        </section>

        <section>
          <h2 className="mb-2 font-display text-xl text-fg">Data Protection Officer</h2>
          <p>
            [DPO NAME / ROLE]
            <br />
            Email: [DPO EMAIL]
            <br />
            [COMPANY LEGAL NAME], [REGISTERED ADDRESS]
          </p>
        </section>
      </div>
    </article>
  );
}
```

- [ ] **Step 4: Wire the route**

In `frontend/src/App.tsx`, add the lazy import beside the other public pages (after line 53's `NotFoundPage` import):

```tsx
const PrivacyPolicyPage = lazy(() => import('./pages/PrivacyPolicyPage'));
```

And add the route inside the public `/` Layout block (after the `register` route, line 154):

```tsx
            <Route path="privacy" element={<PrivacyPolicyPage />} />
```

- [ ] **Step 5: Add the footer link + update its test**

In `frontend/src/components/SiteFooter.tsx`, add a Legal column. After the `SHOP_LINKS` constant (line 17), add:

```tsx
const LEGAL_LINKS = [{ label: 'Privacy Policy', to: '/privacy' }];
```

Then add it to `LINK_COLUMNS` (lines 35-38):

```tsx
  const LINK_COLUMNS = [
    { heading: 'Shop', links: SHOP_LINKS },
    { heading: 'Account', links: accountLinks },
    { heading: 'Legal', links: LEGAL_LINKS },
  ];
```

In `frontend/src/components/SiteFooter.test.tsx`, add a test (mirror the existing render/wrapper the file already uses):

```tsx
it('links to the privacy policy', () => {
  renderFooter(); // use the file's existing render helper / wrapper
  expect(screen.getByRole('link', { name: /privacy policy/i })).toHaveAttribute('href', '/privacy');
});
```

If the file has no `renderFooter` helper, render `<SiteFooter />` inside the same `MemoryRouter`/provider wrapper the other tests in that file use.

- [ ] **Step 6: Run tests to verify they pass**

Run: `cd frontend && npx vitest run src/pages/PrivacyPolicyPage.test.tsx src/components/SiteFooter.test.tsx`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/pages/PrivacyPolicyPage.tsx frontend/src/pages/PrivacyPolicyPage.test.tsx frontend/src/App.tsx frontend/src/components/SiteFooter.tsx frontend/src/components/SiteFooter.test.tsx
git commit -m "feat(pdpa): privacy policy page, route, and footer link"
```

---

## Task 5: Registration consent checkbox (frontend)

**Files:**
- Modify: `frontend/src/stores/authStore.ts:10-14` (RegisterPayload type)
- Modify: `frontend/src/pages/RegisterPage.tsx` (checkbox + gate)
- Test: `frontend/src/pages/RegisterPage.test.tsx` (create if absent)

- [ ] **Step 1: Write the failing test**

Create (or add to) `frontend/src/pages/RegisterPage.test.tsx`:

```tsx
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import RegisterPage from './RegisterPage';

describe('RegisterPage consent', () => {
  it('disables submit until the consent checkbox is ticked', async () => {
    render(
      <MemoryRouter>
        <RegisterPage />
      </MemoryRouter>,
    );
    const submit = screen.getByRole('button', { name: /create account/i });
    expect(submit).toBeDisabled();

    await userEvent.click(screen.getByRole('checkbox', { name: /privacy policy/i }));
    expect(submit).toBeEnabled();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd frontend && npx vitest run src/pages/RegisterPage.test.tsx`
Expected: FAIL — no consent checkbox; submit not disabled.

- [ ] **Step 3: Extend the RegisterPayload type**

In `frontend/src/stores/authStore.ts`, add to the `RegisterPayload` interface (after `company_address?: string;`, line 14):

```ts
  consent: boolean;
```

- [ ] **Step 4: Add the checkbox and gate submit**

In `frontend/src/pages/RegisterPage.tsx`:

Add state beside the others (after line 35's `submitting` state):

```tsx
  const [consent, setConsent] = useState(false);
```

Add `consent` to the `register(...)` payload in `submit` (after `company_phone: companyPhone || undefined,`, line 51):

```tsx
      consent,
```

Add the checkbox inside the form, immediately before the submit `<Button>` (before line 155):

```tsx
              <label className="flex items-start gap-2 text-sm text-fg-muted">
                <input
                  type="checkbox"
                  checked={consent}
                  onChange={(e) => setConsent(e.target.checked)}
                  disabled={submitting}
                  className="mt-0.5 h-4 w-4 shrink-0 rounded border-border-strong text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                />
                <span>
                  I agree to the{' '}
                  <Link to="/privacy" className="font-semibold text-brand-700 hover:underline">
                    Privacy Policy
                  </Link>
                  .
                </span>
              </label>
```

Gate the submit button by adding `disabled={submitting || !consent}` to the `<Button type="submit" ...>` (line 155):

```tsx
              <Button type="submit" fullWidth size="lg" loading={submitting} disabled={submitting || !consent}>
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd frontend && npx vitest run src/pages/RegisterPage.test.tsx`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/stores/authStore.ts frontend/src/pages/RegisterPage.tsx frontend/src/pages/RegisterPage.test.tsx
git commit -m "feat(pdpa): consent checkbox on registration form"
```

---

## Task 6: Checkout recipient-consent checkbox (frontend)

**Files:**
- Modify: `frontend/src/stores/quoteStore.ts:106-113` (createQuote signature) and `:250-270` (body)
- Modify: `frontend/src/pages/CheckoutPage.tsx` (checkbox + gate + pass flag)
- Test: `frontend/src/pages/CheckoutPage.test.tsx` (create if absent)

- [ ] **Step 1: Write the failing test**

Create (or add to) `frontend/src/pages/CheckoutPage.test.tsx`. The page early-returns an empty-cart state unless the cart store has lines, so seed one line and a signed-in user before asserting:

```tsx
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it } from 'vitest';
import CheckoutPage from './CheckoutPage';
import { useCartStore } from '../stores/cartStore';
import { useAuthStore } from '../stores/authStore';

describe('CheckoutPage recipient consent', () => {
  beforeEach(() => {
    useAuthStore.setState({
      user: { id: 1, company_id: 1, company: { name: 'Acme', address: '1 Road' } } as never,
      status: 'ready',
    });
    useCartStore.setState({
      lines: [{ key: 'k1', qty: 1, product: { id: 1, name: 'Mug' }, variant: null, customization: null }] as never,
      neededBy: '',
      estimate: null,
      estimating: false,
      estimateError: null,
    } as never);
  });

  it('keeps Place order disabled until both acknowledgements are ticked', async () => {
    render(
      <MemoryRouter>
        <CheckoutPage />
      </MemoryRouter>,
    );
    const place = screen.getByRole('button', { name: /place order/i });
    expect(place).toBeDisabled();

    await userEvent.click(screen.getByRole('checkbox', { name: /quote request/i }));
    await userEvent.click(screen.getByRole('checkbox', { name: /recipient'?s consent/i }));
    expect(place).toBeEnabled();
  });
});
```

Note: if the existing store shapes differ, adjust the `setState` seeds to match `cartStore`/`authStore` — the assertion (two checkboxes gate the button) is the fixed requirement.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd frontend && npx vitest run src/pages/CheckoutPage.test.tsx`
Expected: FAIL — only one checkbox; button enabled after one tick.

- [ ] **Step 3: Extend createQuote in the store**

In `frontend/src/stores/quoteStore.ts`, add a param to the `createQuote` type (after `shippingAddress?: ShippingAddressInput | null,`, line 112):

```ts
    recipientConsent?: boolean,
```

Update the implementation signature (line 250):

```ts
  createQuote: async (companyId, lines, notes, neededBy = null, idempotencyKey = null, shippingAddress = null, recipientConsent = false) => {
```

Add the field to the POST body (after `shipping_address: shippingAddress,`, line 263):

```ts
        // PDPA: buyer's acknowledgement they may share the recipient's details.
        recipient_consent: recipientConsent,
```

- [ ] **Step 4: Add the checkbox, gate the button, pass the flag**

In `frontend/src/pages/CheckoutPage.tsx`:

Add state beside `agreed` (after line 98):

```tsx
  const [recipientConsent, setRecipientConsent] = useState(false);
```

Add a guard in `placeOrder` after the `!agreed` guard (after line 193):

```tsx
    if (!recipientConsent) {
      setSubmitError("Please confirm you have the recipient's consent to share their delivery details.");
      return;
    }
```

Pass the flag to `createQuote` (add as the 7th argument in the call at lines 196-203, after `toShippingInput(shipping),`):

```tsx
      recipientConsent,
```

Add the second checkbox immediately after the existing `agreed` `<label>` block (after line 452), before the `<Button ... Place order>`:

```tsx
                  <label className="mb-3 flex items-start gap-2 text-2xs leading-snug text-fg-muted">
                    <input
                      type="checkbox"
                      checked={recipientConsent}
                      onChange={(e) => setRecipientConsent(e.target.checked)}
                      className="mt-0.5 h-4 w-4 shrink-0 rounded border-border-strong text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    />
                    <span>
                      I confirm I have this recipient&rsquo;s consent to share their delivery details, per
                      the{' '}
                      <a href="/privacy" className="font-medium text-primary underline">
                        Privacy Policy
                      </a>
                      .
                    </span>
                  </label>
```

Update the Place-order button's `disabled` (line 458) to require both:

```tsx
                    disabled={submitting || !agreed || !recipientConsent}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd frontend && npx vitest run src/pages/CheckoutPage.test.tsx`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/stores/quoteStore.ts frontend/src/pages/CheckoutPage.tsx frontend/src/pages/CheckoutPage.test.tsx
git commit -m "feat(pdpa): recipient-consent checkbox at checkout"
```

---

## Task 7: Full-suite green sweep

**Files:** any remaining test that POSTs `/api/register` or `/api/quotes` as a buyer without the new consent fields.

- [ ] **Step 1: Find remaining buyer POST call sites**

Run:
```bash
grep -rn "postJson('/api/quotes'" tests app/Harness
grep -rn "postJson('/api/register'" tests
```

For each **buyer** quote POST that expects success (`assertCreated`/`assertOk`) and includes a `shipping_address`, add `'recipient_consent' => true,` to the payload. For each register POST expecting success, add `'consent' => true,`. Leave staff quote POSTs and assert-422 cases unchanged (staff are exempt; a 422 case only gains an extra error). Also update the Harness agent `tests/Harness/Agents/StaffAgent.php` only if it drives a **buyer** checkout.

- [ ] **Step 2: Run the full backend suite**

Run: `php artisan test`
Expected: PASS — same count as branch point plus the new PdpaConsentTest cases. Fix any red buyer POSTs per Step 1.

- [ ] **Step 3: Run the full frontend suite + typecheck**

Run: `cd frontend && npx vitest run && npx tsc --noEmit`
Expected: PASS, `tsc` clean.

- [ ] **Step 4: Commit any test fixes**

```bash
git add -A
git commit -m "test(pdpa): thread consent through existing buyer POST fixtures"
```

---

## Self-Review

- **Spec coverage:** Privacy page + DPO (Task 4) ✓; footer link (Task 4) ✓; register consent + server enforcement + version stamp (Tasks 1,2,5) ✓; checkout recipient consent + server enforcement + version stamp (Tasks 1,3,6) ✓; grandfathering = nullable columns, no backfill (Task 1) ✓; out-of-scope items excluded ✓.
- **Placeholder scan:** the only bracketed placeholders are the intended business/legal content on the Privacy page (`[COMPANY LEGAL NAME]`, `[DPO EMAIL]`, etc.), flagged as owner fill-ins in the spec — not plan gaps.
- **Type consistency:** `consent` (register, boolean) and `recipient_consent` / `recipientConsent` (checkout) used consistently across request rules, controllers, stores, and components; column names match the migration throughout.
- **Blast-radius:** existing-test breakage from the new required fields is handled in Tasks 2, 3, and 7.
