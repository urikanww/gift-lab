# Gift Lab — API & Realtime Reference (B2B v1)

Base URL: `https://api.giftlab.example/api`
Auth: **Sanctum stateful cookies** (SPA). Call `GET /sanctum/csrf-cookie` once
before the first mutating request. All money is **SGD**; timestamps are **UTC**
ISO-8601 (the SPA renders SGT / user-local).

Roles: `buyer` (own company only), `staff_admin`, `superadmin` (staff = the
latter two). Rate limits: login 6/min, public 60/min, authenticated 120/min.

---

## Authentication

### `POST /login`  · public · throttle 6/min
```json
{ "email": "buyer@acme.com", "password": "secret", "remember": false }
```
`200` → `{ "user": { "id", "name", "email", "role", "company_id" } }`
`422` → uniform invalid-credentials error (no user enumeration).

### `POST /logout` · auth
`204`. Invalidates session, rotates CSRF token.

### `GET /user` · auth
`200` → current user object.

---

## Public catalogue (no account) · throttle 60/min

### `GET /catalogue?q=&class=&page=`
Only `PUBLISHED` products. `200` → paginated `ProductResource`.

### `GET /catalogue/{product}`
`200` → `ProductResource` (with `variants`). `404` if not published.

### `POST /price-estimate`
Live designer estimate (event-driven; **never polled**). Indicative only.
```json
{ "line_items": [ { "product_id": 1, "variant_id": 5, "qty": 50, "has_customization": true } ] }
```
`200`:
```json
{ "currency": "SGD",
  "lines": [ { "unit_price": 13.5, "line_total": 683.0 } ],
  "subtotal": 708.0, "delivery": 30.0, "total": 738.0 }
```

---

## Quotes · auth

| Method | Path | Role | Purpose |
|---|---|---|---|
| GET | `/quotes` | buyer(own)/staff | List (buyer scoped to company) |
| POST | `/quotes` | buyer/staff | Create DRAFT from cart |
| GET | `/quotes/{quote}` | owner/staff | Detail (line items + proofs) |
| PATCH | `/quotes/{quote}/amend` | staff | Amend line price/qty (margin-floor enforced) |
| POST | `/quotes/{quote}/send` | staff | DRAFT → SENT |
| POST | `/quotes/{quote}/accept` | owner/staff | SENT → ACCEPTED |
| POST | `/quotes/{quote}/proofs` | staff | Issue proof (→ PROOFING) |
| POST | `/quotes/{quote}/purchase-order` | staff | PROOF_APPROVED → PO_ISSUED → CONFIRMED |
| POST | `/quotes/{quote}/procure` | staff | CONFIRMED → PROCURING → (queue when ready) |

### `POST /quotes`
```json
{ "company_id": 1, "notes": null,
  "line_items": [
    { "product_id": 1, "variant_id": 5, "qty": 50,
      "customization": { "logo_size": "M", "name_text": "Acme", "artwork_ref": "s3://…" } }
  ] }
```
`201` → `QuoteResource` (state `DRAFT`). Buyer `company_id` must equal their own (else `422`).

### `PATCH /quotes/{quote}/amend`
```json
{ "delivery": 30.0, "lines": [ { "id": 12, "unit_price": 14.0, "qty": 50 } ] }
```
`422` if any `unit_price` below margin floor over landed cost.

### `POST /quotes/{quote}/purchase-order`
```json
{ "po_ref": "PO-2026-001", "invoice_ref": null, "terms": "NET30" }
```
`201` → `{ "purchase_order": {…}, "quote": QuoteResource }`.

---

## Proofs · auth

### `POST /quotes/{quote}/proofs` · staff
```json
{ "artwork_version_ref": "s3://giftlab/proofs/uuid.pdf", "notes": null }
```
`201` → `ProofResource` (`SENT`, incrementing `version`).

### `POST /proofs/{proof}/decide` · owner-buyer/staff
```json
{ "decision": "approve" }               // or
{ "decision": "request_changes", "notes": "Move the logo up." }
```
Approve → proof `APPROVED` (immutable, records who/when) + quote `PROOF_APPROVED`.
Request changes → proof `CHANGES_REQUESTED`; quote stays `PROOFING` for a new version.

---

## Procurement · staff

### `POST /line-items/{lineItem}/reconfirm`
Resolve a line in `AWAITING_RECONFIRM` (qty short / price jump).
```json
{ "action": "amend", "qty": 40, "unit_price": 16.0 }   // amend → re-procure
{ "action": "approve" }                                 // accept as-is → ready
{ "action": "drop" }                                    // drop line (others unaffected)
```
`200` → `LineItemResource`.

---

## Production queue · staff

### `GET /production-queue`
Shared FCFS-by-`ready_at` queue (UV + 3D, no customer priority).
`200` → `ProductionJobResource[]`.

### `POST /production-jobs/{job}/advance`
```json
{ "state": "IN_PRODUCTION" }   // or SHIPPED, CLOSED
```
`200` → `ProductionJobResource`.

---

## Realtime — Laravel Reverb (websockets only, no polling)

Client: Laravel Echo (`broadcaster: 'reverb'`), auth via `/broadcasting/auth`
(Sanctum cookie). Private-channel authorization is in `routes/channels.php` and
mirrors `QuotePolicy` — realtime access never exceeds HTTP access.

| Channel | Who | Event (`broadcastAs`) | Payload |
|---|---|---|---|
| `private-company.{id}` | buyer of company / staff | `.quote.state-changed` | `quote_id, state, previous_state, total, currency` |
| `private-company.{id}` | buyer of company / staff | `.proof.status-changed` | `proof_id, quote_id, version, state, artwork_version_ref` |
| `private-staff.queue` | staff | `.production-queue.updated` | `job_id, quote_id, track, state, ready_at, qty, action` |
| `private-staff.procurement` | staff | `.line-item.awaiting-reconfirm` | `line_item_id, quote_id, reason, ordered_qty, procured_qty, unit_price, procured_price` |

All broadcast events implement `ShouldBroadcastNow` (synchronous — no queue lag
on realtime).

---

## State machines (authoritative)

- **Quote**: `DRAFT→SENT→(CHANGES_REQUESTED→DRAFT)*→ACCEPTED→PROOFING→PROOF_APPROVED→PO_ISSUED→CONFIRMED→PROCURING→READY→CLOSED`; `CONFIRMED|PROCURING→CANCELLED`.
- **LineItem**: `PENDING→PROCURING→{PURCHASED→INBOUND→RECEIVED→READY | AWAITING_RECONFIRM→(AMENDED→PROCURING | approve→…→READY | DROPPED)}`.
- **Proof**: `SENT→{APPROVED(terminal) | CHANGES_REQUESTED}`.
- **Job**: `READY→IN_PRODUCTION→SHIPPED→CLOSED`.

Two hard production gates: (1) recorded proof approval, (2) all lines READY (blank
on floor / filament available) before a job enters the queue.
