# PDPA Day-One Essentials — Design Spec

**Date:** 2026-08-04
**Status:** Approved (brainstorming), pending spec review
**Scope owner decision:** Day-one essentials only. Retention purge, right-to-erasure,
data-portability export, and PII-at-rest encryption are explicitly **out of scope**
for this build — they are point-2 follow-ups.

---

## Problem

The platform collects personal data with no PDPA footing:

- **No Privacy Policy.** No `/privacy` route, page, or footer link exists. PDPA
  ss.11–12 require a published policy and a designated DPO contact. Non-compliant
  from day one.
- **No consent capture.** `RegisterPage` collects name / work-email / phone /
  company with no consent notice; `AuthController::register` (`app/Http/Controllers/AuthController.php:49`)
  persists the user with no recorded consent basis and there is no `consented_at`
  column. At checkout, `StoreQuoteRequest` (`app/Http/Requests/StoreQuoteRequest.php:92-102`)
  collects a **third party's** PII (the gift recipient's name / phone / email /
  address) with zero recorded basis.

This spec closes the published-policy gap and records an explicit consent basis at
both points where PII is collected.

---

## Goals

1. A public Privacy Policy page with a DPO contact, linked in the footer.
2. Explicit, server-enforced consent recorded at **registration**.
3. Explicit, server-enforced acknowledgement recorded at **checkout** for the
   recipient's details.
4. Each consent records the **policy version** agreed, so a future material change
   can trigger re-consent.

## Non-goals (out of scope)

- Retention / auto-anonymise cron for old ship-to PII.
- Right-to-erasure / correction path.
- Right-to-access / data-portability export.
- PII-at-rest encryption.
- Re-consent prompt for existing users (they are grandfathered — see below).
- Fixing the separately-flagged dead footer About/Help links (LT6).

---

## Design

### 1. Privacy Policy page

- New page `frontend/src/pages/PrivacyPolicyPage.tsx`, lazy-loaded like the other
  public pages.
- Route `path="privacy"` added under the public `/` `Layout` block in
  `frontend/src/App.tsx` (alongside `login` / `register`), so it is reachable
  without auth.
- Content is a drafted PDPA-appropriate template covering: what data is collected,
  the purposes, retention posture, disclosure/third parties (courier), the
  complaint channel, and a **DPO contact block**. Business/legal specifics are
  clearly-marked placeholders to be filled before launch:
  - `[COMPANY LEGAL NAME]`
  - `[DPO NAME / ROLE]` and `[DPO EMAIL]`
  - `[REGISTERED ADDRESS]`
- Link to `/privacy` added to `frontend/src/components/SiteFooter.tsx`.

The page is static content — no data fetch, no store, no API. It can be understood
and tested in isolation: it renders the policy and nothing else.

### 2. Policy version constant

- A single source of truth for the current policy version string, e.g.
  `config/privacy.php` → `'version' => '2026-08-04'`, read via
  `config('privacy.version')`.
- Every consent record stamps this value. Bumping it later is how a material
  policy change is marked (the re-consent prompt that consumes it is a follow-up,
  not built here).

### 3. Consent data model (migrations)

One migration, two tables:

- `users`:
  - `consented_at` — nullable timestamp.
  - `consent_policy_version` — nullable string.
- `quotes`:
  - `recipient_consent_ack_at` — nullable timestamp.
  - `recipient_consent_version` — nullable string.

Both `users` columns are nullable because **existing users are grandfathered** —
they registered before consent capture existed, so `consented_at` stays null for
them. This is accepted for the essentials pass; a re-consent prompt is a follow-up.
The `quotes` columns are nullable because staff-raised quotes (no storefront
checkout) never carry a recipient acknowledgement.

### 4. Registration consent

- **Frontend** (`RegisterPage.tsx`): an unticked checkbox —
  *"I agree to the [Privacy Policy](/privacy)"* — that blocks submit until ticked.
  Renders as a normal inline field with the existing per-field validation styling.
- **Backend** (`RegisterRequest.php`): add rule `consent => ['required', 'accepted']`.
  The rule is the guard — a direct API call omitting consent is rejected with 422,
  not just the UI.
- **Persistence** (`AuthController::register`): inside the existing
  `DB::transaction`, stamp `consented_at = now()` and
  `consent_policy_version = config('privacy.version')` on the created `User`.

### 5. Checkout consent

- **Frontend** (`CheckoutPage.tsx`): an acknowledgement checkbox —
  *"I confirm I have this recipient's consent to share their delivery details"* —
  linking to `/privacy`, required to place the order.
- **Backend** (`StoreQuoteRequest.php`): add
  `recipient_consent => [Rule::requiredIf(! ($this->user()?->isStaff() ?? false)), 'accepted']`.
  This mirrors the existing `shipping_address` requiredIf on
  `StoreQuoteRequest.php:92` — required for buyers, not for staff raising a quote
  on a company's behalf.
- **Persistence:** where `QuoteService` creates the quote + copies the ship-to
  address, stamp `recipient_consent_ack_at = now()` and
  `recipient_consent_version = config('privacy.version')` on the quote — only on
  the buyer checkout path (when a `shipping_address` / acknowledgement was
  submitted).

---

## Data flow

```
Register:  RegisterPage (checkbox) → RegisterRequest (required|accepted)
           → AuthController::register → users.consented_at + version

Checkout:  CheckoutPage (checkbox) → StoreQuoteRequest (requiredIf buyer, accepted)
           → QuoteService create → quotes.recipient_consent_ack_at + version

Policy:    SiteFooter link + register/checkout checkbox links → /privacy (public page)
```

## Error handling

- Missing/false consent at register → 422 from `RegisterRequest`, surfaced inline
  by the existing per-field validation copy path.
- Missing/false acknowledgement at buyer checkout → 422 from `StoreQuoteRequest`,
  surfaced inline on the checkout form.
- Staff-raised quotes omit the acknowledgement legitimately (requiredIf) — no error.

## Testing (TDD)

**Backend (Pest):**
- `register` rejects (422) when `consent` is absent or false.
- `register` success stamps `consented_at` (not null) and the configured version.
- `StoreQuoteRequest` / checkout rejects (422) for a buyer when
  `recipient_consent` is absent or false.
- Buyer checkout success stamps `recipient_consent_ack_at` + version on the quote.
- Staff-raised quote succeeds without `recipient_consent` and leaves the ack null.

**Frontend (Vitest):**
- Register submit is blocked until the consent checkbox is ticked.
- Checkout submit is blocked until the acknowledgement checkbox is ticked.
- `SiteFooter` renders a link to `/privacy`.
- `PrivacyPolicyPage` renders (heading + DPO contact block present).

Both suites are green at branch point; keep them green. Feature tests run SQLite,
production runs MySQL — nothing here depends on `LIKE`/case behaviour, so no
cross-engine risk.

---

## Files touched

**Backend**
- `database/migrations/<new>_add_consent_columns.php` — new
- `config/privacy.php` — new
- `app/Http/Requests/RegisterRequest.php`
- `app/Http/Requests/StoreQuoteRequest.php`
- `app/Http/Controllers/AuthController.php`
- `app/Services/QuoteService.php` (or the checkout create path it owns)

**Frontend**
- `frontend/src/pages/PrivacyPolicyPage.tsx` — new
- `frontend/src/App.tsx`
- `frontend/src/components/SiteFooter.tsx`
- `frontend/src/pages/RegisterPage.tsx`
- `frontend/src/pages/CheckoutPage.tsx`

## Open items for the owner (non-blocking — placeholders in the draft)

- Company legal name, DPO name/role, DPO email, registered address.
- Legal review of the drafted policy copy before launch.
