# Gift-Lab — Full App Walkthrough (Live Browser + Sandbox Third-Parties)

**Purpose:** drive the ENTIRE app end-to-end as real buyers and internal staff, in the live Chrome browser preview, against sandbox third-parties (NinjaVan sandbox, Stripe test, real SMTP), to prove the UI/UX is right and no backend logic is broken. Every finding goes into a running feedback log.

**Audience:** the executing agent in a fresh session. Load this file, then follow it top to bottom.

---

## 0. Operating protocol (READ FIRST)

1. **Browser preview is mandatory.** Use the in-app Browser tools (`preview_start`, `navigate`, `read_page`, `computer`, screenshots). Every step you perform yourself must be *shown* in the live preview — take a screenshot at each meaningful screen and after each state change.
2. **Host discipline — critical.** Always use **`http://localhost:5173`**, never `127.0.0.1`. The Sanctum auth cookie is bound to the hostname; `localhost` (SPA) ↔ `localhost:8000` (API) must match or login silently "fails" (this exact mismatch was a false alarm in a prior session). If login looks broken, check the host FIRST before logging a bug.
3. **PAUSE protocol for human-only actions.** Some steps you cannot/should not do: reading an email inbox and clicking a link, entering real card details, approving in an external dashboard, solving a CAPTCHA. When you reach one:
   - STOP. Print a clearly-marked block: `🙋 ACTION NEEDED — <exactly what the human must do, where, and what to click>`.
   - Say what you expect to happen after, and what you'll check.
   - **Do not proceed, do not fake it, do not guess the outcome.** Wait for the human to reply "done" (or paste what they saw). Only then continue.
4. **Feedback log.** Keep `docs/flow-audit-2026-07-28/WALKTHROUGH-FEEDBACK.md` open and append every issue as you go, using the table format in §7. Screenshot every bug/friction. Don't batch at the end — log in the moment.
5. **Honesty over green.** If a step fails, capture the real error (console, network, `storage/logs/laravel.log`) and log it. Never paper over a failure to keep moving. Distinguish a real product bug from a test-environment artifact and say which.
6. **Data policy.** This is a real run DB with sandbox third-parties. You may create orders/products freely. Do NOT delete the seeded demo data. Use a buyer account whose email the human can actually open.
7. **Two-session realtime checks.** Where the plan says "confirm realtime," open a second browser tab/session so you can watch a push land without a refresh.

---

## 1. Environment prep (do once; verify each line before testing)

### 1a. Backend `.env` (test profile)
```
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

# Real DB (MySQL preferred; SQLite works for a light run)
DB_CONNECTION=mysql   # or sqlite
# ... db creds ...

# Emails must REALLY send so the human can click links:
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com  MAIL_PORT=587  MAIL_ENCRYPTION=tls
MAIL_USERNAME=<gmail>  MAIL_PASSWORD=<gmail app password>
MAIL_FROM_ADDRESS="no-reply@giftlab.local"

# Queue: sync so mail sends inline during the walkthrough (no worker needed).
QUEUE_CONNECTION=sync
CACHE_STORE=database   # NOT array (scheduler onOneServer needs a real store)

# Artwork storage: local is fine for testing (no Spaces round-trip).
FILESYSTEM_DISK=local
ARTWORK_DISK=local

# Realtime (optional; if off, note that some panels need a manual reload)
BROADCAST_CONNECTION=reverb   # or `log`/`null` to skip Reverb

# --- Sandbox third-parties ---
NINJAVAN_CLIENT_ID=<sandbox id>
NINJAVAN_CLIENT_SECRET=<sandbox secret>
NINJAVAN_BASE_URL=https://api-sandbox.ninjavan.co/sg
NINJAVAN_WEBHOOK_SECRET=<any strong string; you'll sign with it below>

# Stripe (only if testing B2C pay-now; else leave blank = fixture gateway)
STRIPE_SECRET=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
```
> ⚠️ If the local `.env` still holds the owner's LIVE secrets, replace them with sandbox/test values for this run. Never dispatch a real parcel or charge a real card.

### 1b. Migrate + seed
```bash
php artisan migrate:fresh --seed          # staff + pricing + courier config + filament
php artisan db:seed --class=DemoCompanySeeder          # a B2B buyer + company
php artisan db:seed --class=DemoProofOrderSeeder       # orders across proof states
php artisan db:seed --class=DemoBuyerUploadedOrderSeeder
```
- **Staff login (local):** `superadmin@giftlab.local` / `ChangeMe!123` (superadmin), `ops@giftlab.local` / `ChangeMe!123` (staff_admin). Override with `ADMIN_SEED_PASSWORD` if desired.
- **Demo buyer:** `buyer@nexgen.com.sg` (company NexGen Pte Ltd). For email-click steps, **register a fresh buyer with an inbox the human controls** (see B1).

### 1c. Catalogue has products (REQUIRED — the seeder ships NO products)
The storefront is empty until products exist. Two options:
- **Preferred (also tests the admin flow):** create a CORE product through the staff admin UI in **Flow S2**, publish it, then shop it as the buyer.
- Or run a discovery command if tokens are configured: `php artisan catalogue:discover-3d` / `catalogue:pull-uv` (hits real sources).
Do S2 early so buyer flows have something orderable.

### 1d. Run the servers (use `preview_start`, never `Bash artisan serve`)
- `preview_start {name: "api"}` → :8000
- `preview_start {name: "frontend"}` → :5173  ← **open the app here**
- `preview_start {name: "reverb"}` → :8080 (only if `BROADCAST_CONNECTION=reverb`)
- Smoke test: `navigate http://localhost:8000/api/catalogue` returns JSON; `http://localhost:5173` loads the storefront.

### 1e. Third-party webhook simulation (sandbox can't reach localhost)
NinjaVan/Stripe won't call your local box, so you POST their webhooks yourself.

**NinjaVan status webhook** (HMAC-SHA256 hex of the raw body with `NINJAVAN_WEBHOOK_SECRET`, header `X-Ninja-Hmac`):
```bash
SECRET="<NINJAVAN_WEBHOOK_SECRET>"
# tracking_number = the job's consignment_ref (from the shipment booking response / DB)
BODY='{"tracking_number":"<CONSIGNMENT_REF>","status":"Delivered","timestamp":"2026-07-30T10:00:00+08:00"}'
SIG=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "$SECRET" | awk '{print $2}')
curl -si http://localhost:8000/api/ninjavan/webhook \
  -H "Content-Type: application/json" -H "X-Ninja-Hmac: $SIG" --data "$BODY"
```
Useful `status` values: `Out for Delivery`, `Delivered`, `Returned to Sender`. Expect HTTP 200 `{"received":true}`. Re-POST the identical body to prove idempotency (no duplicate email/alert).

**Stripe (only if testing pay-now):** run `stripe listen --forward-to localhost:8000/api/stripe/webhook` (Stripe CLI) so `checkout.session.completed` reaches the app; pay with test card `4242 4242 4242 4242`, any future expiry/CVC.

---

## 2. Buyer journeys (B) — perform in the browser, screenshot each

| ID | Flow | What to verify (UI/UX + backend) | Pause? |
|----|------|----------------------------------|--------|
| **B1** | Register a buyer | `/register` — form validation, throttle, lands signed-in. Use an inbox the human controls. | — |
| **B2** | Browse catalogue + PDP | Products render; **no un-orderable/variant-less product shows** (LT1); price + options; no "Out of stock" scares on made-to-order. | — |
| **B3** | Product designer | Customize: logo size band, text, upload artwork; **live estimate updates**; text-fee is IN the estimate (M9); need-by field feasibility. 3D item: STL face + decal. | — |
| **B4** | Cart | Qty stepper (MOQ clamp), remove, live estimate, "Unavailable — remove" flag if a product is unpublished mid-session (M10). | — |
| **B5** | Checkout | Address (company default / saved / new), need-by, agree checkbox, submit → **confirmation modal with order ref + Track button + QR**. Stale past need-by is auto-cleared, not a 422 (L4). | — |
| **B6** | Buyer dashboard | Stat tiles; **plain-language status** ("Preparing your order", not "Procuring") (L1); "Awaiting you" surfaces accept/approve steps (M5). | — |
| **B7** | Accept the quote | 🙋 human opens the **"quote ready" email**, clicks through → order page → **Accept price**. Confirm the next-step guidance is clear. | **YES** |
| **B8** | Proof sign-off | 🙋 human opens the **"proof ready" email** → review proof image → **Approve** (or Request changes: a blank/whitespace note must be rejected, L19). Buyer never sees unsent DRAFT proofs (M12). | **YES** |
| **B9** | Pay-now (optional) | Only if B2C pay-now enabled. Stripe test card; 3DS may need the human. Confirm PO/paid. | maybe |
| **B10** | Track order | Public tracker: honest stage, shipments (carrier + consignment + live status), **names+qty only** (no PII/price); a returned parcel is NOT shown as "shipped/done" (L14). | — |
| **B11** | Reorder | From a past order → fresh draft, re-priced; product images shown; a since-unpublished line is skipped silently-safely (L21). Rapid double-click doesn't mint duplicates (L22). | — |

---

## 3. Staff / internal journeys (S)

| ID | Flow | What to verify | Pause? |
|----|------|----------------|--------|
| **S1** | Staff login → dashboard | Queues (proofs pending, changes-requested, procurement, catalogue, reorders) + **"Delivered · unpaid"** tile (LT14). | — |
| **S2** | Admin: create + publish a product | Create CORE blank → **publish requires products.approve** (a products.edit-only staff is blocked, L25). Published product appears on the storefront and is orderable (has a variant). | — |
| **S3** | Quote build → send | Open the buyer's DRAFT → edit lines / price (note any block on pricing below cost) → **Send to buyer**. Buyer-notification panel shows the email fired. | — |
| **S4** | Stage + send proofs | Per-line proof staging → **Send proofs** → buyer emailed once for the round (M13 resend targets the right line's artwork). | — |
| **S5** | Commit → invoice | After buyer approves → **Commit** → invoice issued → Confirmed. Step counter does NOT skip a phantom "Invoiced" step (M4). Invoice copy is honest (not "payment received" while unpaid, H4). | — |
| **S6** | Reconcile payment | Record **PARTIAL** → must enter the collected amount → shows "X collected / Y still owed" (H3/M21). Then **Mark paid**. | — |
| **S7** | Procurement | Run procure → confirm-stock gate → Ready. Advisory shortfall doesn't hard-block. | — |
| **S8** | Production + ship | Production queue: Start production → **Create NinjaVan shipment** (sandbox books a consignment) → confirm modal (push-to-NinjaVan is a popup) → Mark shipped. Order page shows the shipment (LT13). | — |
| **S9** | Delivery (webhook) | POST a signed **Delivered** webhook (§1e) → job Closed, order Closed, **buyer "delivered" email fires** (one per order, M19). Re-POST identical → idempotent, no second email (L10). | — |
| **S10** | Returned parcel | POST **Returned to Sender** → order page flags it → resolve: **reship** / **close** / **cancel & credit**. On a **multi-parcel** order, cancel-credit refunds/restocks ONLY that parcel; the box shows **Returned**, order stays live (M15). | — |
| **S11** | Delivered-but-unpaid | A B2B order can reach Closed while UNPAID → **order page banner "payment outstanding"** + it appears in the dashboard "Delivered · unpaid" list (LT14). | — |
| **S12** | Admin panels | Users (create staff, set permissions; can't reset a superadmin's password, H1); Courier config (pickup address, timeslot); Pricing config; Blank-recommendations + Gift-ideas (permission-gated, M20); CSV import + auto-publish are **superadmin-only** (L26/L27). | — |

---

## 4. Edge / negative probes (regression guard — should all behave)

- Un-orderable product never reaches the storefront (LT1).
- Whitespace-only proof change-note → 422 (L19).
- Stale persisted-cart need-by cleared on checkout entry (L4).
- Session expiry mid-checkout → after re-login, land back on **/checkout** not /account (L3).
- Cancel a **part-paid** order → credit note = **only what was collected** (H3).
- Multi-parcel order, one parcel returned → only that parcel refunded/restocked (M15).
- Reorder of a now-unpublished product → line skipped (L21).
- Staff without `products.approve` can't publish (L25); without superadmin can't import/auto-publish (L26/L27).
- Duplicate courier/payment webhook → no double email/alert (L7/L10).

---

## 5. Cross-cutting UX checks (apply throughout)

- **Responsive:** `resize_window` mobile (375) + tablet — designer, cart, checkout, order page, production queue.
- **Dark mode:** toggle; check contrast on badges, banners, tables.
- **States:** loading skeletons, empty states, error states (kill the API mid-flow and confirm a friendly message, not a raw stack/CSRF text — L9/LT9).
- **Realtime:** second session — a staff state change pushes to the buyer/track view with no refresh (needs Reverb).
- **Copy honesty:** buyers never see internal jargon or raw state machine step counts; numbers reconcile (a **"Personalisation" line** so items + fee = subtotal, LT16).
- **Accessibility basics:** keyboard-nav the designer + checkout; focus rings; `aria-label`s on icon buttons.

---

## 6. Third-party sandbox notes

- **NinjaVan sandbox** answers 2xx and echoes a tracking number — it will happily "accept" bookings, so a shipment marks SHIPPED even though no real parcel exists (that's fine for the walkthrough). Delivery/return status only moves when YOU post the webhook (§1e).
- **Stripe test mode:** pay-now is OFF by default (`pay_now_cutoff.b2c_enabled=false`). To exercise B9, a superadmin flips it in Pricing config first. Card `4242…`; declines: `4000 0000 0000 0002`.
- **Mail:** with `QUEUE_CONNECTION=sync`, emails send inline as each action fires — the human should see them arrive within seconds.

---

## 7. Feedback log format (`WALKTHROUGH-FEEDBACK.md`)

| ID | Flow (B#/S#) | Severity | Type | What happened | Repro steps | Screenshot | Suggested fix |
|----|--------------|----------|------|---------------|-------------|------------|---------------|
| F1 | B3 | 🟠 Med | UX | … | … | shot-01.png | … |

Severity: 🔴 blocker / 🟠 UX-hurts / 🟡 polish. Type: UI / UX / copy / a11y / backend-bug / perf. Tag **backend-bug** for anything where the API returns wrong data or a 500 — those are the "no backend logic bug" goal.

At the end: a summary — flows passed clean, issues by severity, and the top 5 to fix first.
