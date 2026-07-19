# Buyer shipping addresses — design

**Date:** 2026-07-19
**Status:** Approved (pre-implementation)

## Problem

At storefront checkout the buyer cannot enter a shipping address. The Delivery
card shows the company's stored free-text address **read-only**
(`CheckoutPage.tsx`), and the backend's per-quote `ShippingAddress` upsert route
is **staff-only** (buyers get 403). Consequences:

- The buyer can't ship an order anywhere other than the single company address.
- That company address is one free-text line with **no postal code**, which the
  NinjaVan courier integration needs — so real dispatch depends on staff fixing
  the address after the fact.

Buyers also have no profile area at all — the header only exposes a "My Orders"
link and a "Log out" button.

## Goals

1. Buyer enters/confirms a structured shipping address **at checkout, before
   placing the order**, saved with the order.
2. Buyer profile menu in the header (account dropdown).
3. Buyer address book: up to **3 saved addresses per user**, managed in profile.
4. **Snapshot rule:** an order copies the address **text** at checkout. It never
   holds a foreign-key relation to a saved address. Editing or deleting a saved
   address afterwards must not change any placed order.

## Non-goals (v1)

- Company-shared address book (addresses are per-user).
- Writing a buyer address back to the company record.
- Editing an order's ship-to after it is placed (staff already can via the
  existing staff route).
- Rush/expedite fees tied to `needed_by` (unrelated; unchanged).

## Approach (decided)

- **Approach A** for the write path: the address is captured on the checkout
  page and passed into quote creation. `QuoteService.createFresh` writes the
  per-quote `ShippingAddress` in the **same DB transaction** as the quote.
  Buyers never touch the staff-only shipping-address route, and an order can
  never land without its address (atomic).
- Saved addresses are **per-user**, capped at **3**, enforced server-side.
- The checkout picker prefills the form from a saved address or the company
  default, but always submits the **current form text** — never a saved-address
  id — so the snapshot rule holds even when a saved address was chosen.

## Data model

### New table `saved_addresses` (per-user address book)

| column | type | notes |
| --- | --- | --- |
| `id` | bigint pk | |
| `user_id` | fk → users, cascade on delete | owner |
| `label` | string, nullable | e.g. "Office", "Warehouse" |
| `recipient_name` | string | |
| `phone` | string | |
| `email` | string, nullable | |
| `line1` | string | |
| `line2` | string, nullable | |
| `city` | string, nullable | |
| `state` | string, nullable | |
| `postal_code` | string | |
| `country` | string(2) | default `SG` |
| `notes` | text, nullable | |
| timestamps | | |

Field set mirrors `ShippingAddress` (+ `label`) so prefill and validation reuse
one shape. The **max-3-per-user** limit is enforced in the create controller
(count check → 422), not the DB.

### Existing `shipping_addresses` (per-quote) — unchanged

Already stores the full structured text and has `shippingAddressOrDefault()`
falling back to the company address. Checkout writes the order's ship-to here.
**No FK to `saved_addresses`** — this is what makes the order a snapshot.

## Backend

### SavedAddress CRUD (buyer)

- `SavedAddress` Eloquent model; `belongsTo(User)`.
- `SavedAddressPolicy`: view/update/delete allowed only when
  `address.user_id === auth id`. No cross-user access.
- Routes (auth middleware group):
  - `GET /saved-addresses` — list the caller's own (≤3).
  - `POST /saved-addresses` — create; **422 if the caller already has 3**.
  - `PUT /saved-addresses/{savedAddress}` — update own (policy).
  - `DELETE /saved-addresses/{savedAddress}` — delete own (policy).
- `StoreSavedAddressRequest` / `UpdateSavedAddressRequest`: same field rules as
  `UpdateShippingAddressRequest` (`recipient_name`, `phone`, `line1`,
  `postal_code` required; `line2`, `city`, `state`, `email`, `notes` optional;
  `country` defaults `SG`) plus `label` (nullable, max 60). The store request
  also guards the count (rejects when the user already has 3) as an independent
  net alongside the controller check.

### Quote creation carries the address (Approach A)

`POST /quotes` is shared by buyers **and** staff ("Staff may also raise a quote
on a company's behalf"). So:

- `StoreQuoteRequest` gains a nested `shipping_address` object,
  **required for buyers, optional for staff** (`Rule::requiredIf` on
  `! user->isStaff()`). When present it validates with the shared field rules
  (required: `recipient_name`, `phone`, `line1`, `postal_code`).
- `QuoteController@store` passes the validated `shipping_address` (or null) into
  `QuoteService::create`.
- `QuoteService::createFresh` accepts `?array $shipping`. Inside the existing
  transaction, after `Quote::create`, if `$shipping` is non-null it creates the
  `ShippingAddress` row for the quote. If null (staff omitted it), no row is
  created and `shippingAddressOrDefault()` keeps returning the company default —
  unchanged staff behaviour.
- Idempotent replay: a repeated `idempotency_key` still returns the original
  quote without creating a second address (the address write lives on the
  fresh-create path only).

## Frontend

### Checkout — interactive Delivery card

State lives in `CheckoutPage` local state (not persisted in the cart store; the
address is finalised at place time).

- **Picker** lists: each saved address (label + one-line summary), the **Company
  default**, and **+ New address**. Default selection: the buyer's first saved
  address if any, else Company default.
- Selecting an option prefills an editable structured form:
  - saved address → its fields verbatim;
  - company default → `recipient_name`/`phone` from company, free-text into
    `line1`, `postal_code` **blank** (buyer must fill).
- Client-side validation of the required fields gates the **Place order** button
  (disabled + inline errors until valid). Mirrors existing form UX.
- On place, `createQuote` is called with the **current form values as text**
  under `shipping_address`. Even when a saved address was picked, the text is
  sent — never an id.

`useCartStore().createQuote` / `useQuoteStore().createQuote` signatures extend to
accept the `shipping_address` payload; `PriceEstimate` is unaffected (delivery
still weight-based). The read-only "Ships to" block is replaced by the picker +
form; the "Need it by" line stays.

### Profile menu (header)

- `SiteHeader`: for a signed-in **buyer** (non-staff), replace the inline
  `AccountLink` + "Log out" with an **account dropdown** using the same
  disclosure pattern as `CategoriesMenu` (click-outside close, Escape restores
  focus, `aria-expanded`/`aria-haspopup`). Trigger shows the user's name/email.
  Items: **My Orders** (`/quotes`), **Addresses** (`/account/addresses`),
  **Log out**.
- Staff header is unchanged (keeps existing inline links).
- `MobileDrawer`: add an **Addresses** link in the account section for buyers.

### Address book page

- New buyer-only route `/account/addresses` → `AddressBookPage`, guarded like
  other buyer pages (redirect to `/login` when anonymous; staff may see it too
  but it is a personal book — acceptable, no special-casing).
- Lists the ≤3 saved addresses as cards with **Edit** / **Delete**; an **Add
  address** button that is **hidden once 3 exist** (server still guards).
- Add/Edit uses a structured form (label + address fields) with the same
  validation as checkout.
- Small `savedAddressStore` (zustand) consistent with `cartStore`/`quoteStore`:
  `list`, `create`, `update`, `remove`, holding the caller's addresses.

### Types

- `SavedAddress` interface (fields above).
- `ShippingAddressInput` interface for the checkout payload (the structured
  fields, no id/label).

## Snapshot rule (explicit)

The only path from a saved address to an order is a **client-side copy of text**
into the checkout form, which is sent to `createQuote` and stored on the
per-quote `ShippingAddress`. There is no server relation between
`shipping_addresses` and `saved_addresses`. Therefore editing or deleting a
saved address after checkout leaves every existing order's ship-to untouched.
This is covered by an explicit backend test.

## Error handling

- Create saved address at the limit → 422 with a clear message; UI also hides
  Add at 3.
- Cross-user access to a saved address → 403 (policy).
- Buyer places an order with an invalid/incomplete address → 422 (server) and
  blocked client-side before submit.
- Staff create without an address → allowed; company default used.

## Testing

**Backend**
- Saved-address CRUD: owner can list/create/update/delete; non-owner gets 403.
- Max-3 guard: 4th create → 422; delete then create succeeds.
- Quote create with `shipping_address` writes the per-quote `ShippingAddress`
  snapshot; buyer omitting it → 422; staff omitting it → allowed (company
  default).
- **Immutability:** create quote from an address, then edit and delete the
  saved address → the quote's `ShippingAddress` text is unchanged.
- Idempotent replay does not create a second `ShippingAddress`.

**Frontend**
- Checkout blocks Place order until the address is valid; picking a saved
  address prefills the form; submitting sends text (assert payload shape).
- Address book: add/edit/delete; Add hidden at 3.
- Header renders the account dropdown for a buyer (My Orders / Addresses / Log
  out) and not for staff.

## Files touched (indicative)

- **New:** migration `create_saved_addresses_table`, `app/Models/SavedAddress.php`,
  `app/Policies/SavedAddressPolicy.php`, `app/Http/Controllers/SavedAddressController.php`,
  `app/Http/Requests/StoreSavedAddressRequest.php`,
  `app/Http/Requests/UpdateSavedAddressRequest.php`,
  `frontend/src/pages/AddressBookPage.tsx`,
  `frontend/src/stores/savedAddressStore.ts`.
- **Changed:** `routes/api.php`, `app/Http/Requests/StoreQuoteRequest.php`,
  `app/Http/Controllers/QuoteController.php`, `app/Services/QuoteService.php`,
  `frontend/src/pages/CheckoutPage.tsx`, `frontend/src/components/SiteHeader.tsx`,
  `frontend/src/stores/cartStore.ts` / `quoteStore.ts` (createQuote signature),
  `frontend/src/types.ts`, `frontend/src/App.tsx` (route).
