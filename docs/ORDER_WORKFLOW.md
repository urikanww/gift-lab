# Order Workflow — from draft to delivered

**Date:** 2026-07-21
**Source:** read from code, not from intent. Every claim cites `file:line`.

This is the operational walkthrough: what happens to an order, what staff do at
each point in the app, and what staff must tell the client. It also records
where the workflow is currently **broken**, because four of those breaks change
what staff should actually do today.

---

## Read this first — four blockers

These are not polish items. Each one changes day-to-day operating procedure.

### 1. Attaching artwork when you send a draft can kill the order

Sending a DRAFT with an artwork reference takes the "slim path" — DRAFT →
PROOFING, skipping SENT and ACCEPTED (`QuoteService.php:268`). That leaves
`accepted_at` null.

If the buyer then clicks **Request changes**, the quote moves to
CHANGES_REQUESTED (`QuoteService.php:394-398`) — and **CHANGES_REQUESTED has no
forward path**. Its only legal exits are DRAFT and CANCELLED
(`QuoteState.php:35`), and no code performs the DRAFT transition. `issueProof()`
refuses any state that isn't ACCEPTED or PROOFING (`:311-313`).

The order is dead. Cancel and re-create is the only way out.

**Rule until this is fixed: send drafts with the artwork field BLANK.** Issue
the proof after the buyer accepts. On the accepted path, change requests loop
cleanly and forever.

### 2. The procurement desk never loads its own data

`ProcurementPage` only subscribes to a live broadcast — there is no initial
fetch, and no `GET` route for awaiting-reconfirm lines exists at all
(`ProcurementPage.tsx:15-18`, `procurementStore.ts:38-48`).

A blocked line is therefore visible **only to whoever had the page open at the
moment it broke**. Anyone arriving later sees *"No lines awaiting
reconfirmation."* — including staff who follow the "Go to procurement desk →"
link placed on the order precisely because a line is blocked.

**Until fixed: do not rely on the procurement desk to tell you anything.** Check
blocked lines on the order page itself.

### 3. "Accept as-is" overcharges the client

On a quantity shortfall, choosing **Accept as-is** moves the line to READY
without recording any stock movement and **without re-totalling the quote or
invoice** (`QuoteService.php:560-566`; compare the `amend` and `drop` branches,
which both re-total).

The client is invoiced for the full ordered quantity while the floor produces
fewer units.

**Until fixed: use Amend (with the real quantity) instead of Accept as-is**, so
the money follows the goods.

### 4. Cancelling a 3D order loses the filament

3D procurement decrements `qty_on_hand` with a direct column write rather than
through the stock ledger (`Model3dProcurement.php:64-65`), so no
`StockMovement` row exists. `returnConsumedStock()` only sums SALE movements and
skips lines with no variant (`QuoteService.php:471-492`).

Cancelling a 3D order after procurement **permanently loses that filament from
inventory**. CORE stock is returned correctly.

**Until fixed: adjust filament by hand after cancelling a 3D order.**

---

## The happy path

Nine states. What staff do, and what the client must be told.

### 1. DRAFT — the order arrives

Created by a buyer through the cart, or by staff. Nothing is sent yet.

**In the app:** review lines, quantities and pricing.

**Then:** *Send to buyer* with the artwork field **blank** → SENT.

> The field is not an upload. It is a plain text pointer with no validation
> (`SendQuoteRequest.php:27`), and the display only makes it a working link if
> it is a full `http(s)` URL (`QuoteDetailPage.tsx:156`). The placeholder says
> "object-store key", which is the one input shape that renders as dead grey
> text. See blocker 1 for why you should leave it blank anyway.

**Client gets:** the *only* email this application sends — subject *"Your quote
is ready to review"*, with a link to `/orders/{reference}`
(`QuoteService.php:647-662`).

**Automatic:** a DRAFT idle for 14 days is cancelled overnight
(`ExpireStaleDrafts.php:22`). Nothing else expires, ever.

### 2. SENT — waiting on the client

**In the app:** nothing to do. Only the buyer can move this forward.

**Client action:** they sign in and click *Accept quote*.

**Watch for:** there is no reminder, no expiry, no nudge. A SENT quote sits
forever. **Chase it yourself** — the app will not.

### 3. ACCEPTED — the price is agreed

**In the app:** *Issue proof*, pasting a shareable **https:// URL** to the
artwork (not a storage key). → PROOFING.

**Client gets:** an email — *"Your quote & proof are ready to review"* — but
**only for the first proof**. Revised proofs send nothing
(`QuoteService.php:317`).

**So: tell the client yourself every time you issue proof v2 or later.**

### 4. PROOFING — waiting on sign-off

**Client action:** *Approve proof*, or *Request changes*.

- **Approved** → PROOF_APPROVED. On the slim path this also back-fills
  acceptance (`QuoteService.php:361-365`).
- **Changes requested** → on the accepted path the proof is rejected and the
  quote **stays in PROOFING**; issue a new version and loop. On the slim path,
  see blocker 1.

**Client gets:** nothing on approval. **Tell them you received it.**

> The change-request note is labelled "optional" in the UI but is required by
> the API, so a blank submission silently sends the literal text *"Please
> revise."* (`QuoteDetailPage.tsx:229`). If you receive that exact phrase, the
> buyer probably wrote nothing — ask them.

### 5. PROOF_APPROVED — ready to invoice

**In the app:** *Issue invoice* with a PO reference.

> **This does more than it says.** The same transaction drives the quote through
> INVOICED to **CONFIRMED** (`QuoteService.php:425-426`) — the gate for
> production. The button says "Invoice issued" and gives no hint you have just
> committed the order. Be sure before you click.

**Client gets:** nothing. **Send the invoice yourself.**

> A *Pay now* button also appears here for every buyer, but B2C payment is
> feature-flagged off by default (`PaymentService.php:41-44`). On a B2B tenant
> it always fails, and the failure blanks the whole order page. Tell buyers not
> to use it.

### 6. CONFIRMED — committed to production

**In the app:** *Run procurement*. → PROCURING.

### 7. PROCURING — sourcing the goods

Each line is procured by strategy: CORE from stock, SCRAPED_UV re-checked
against the marketplace, MODEL_3D from filament
(`ProcurementManager.php:72-75`).

**If every line resolves and at least one is ready**, jobs are built
automatically and the quote moves to READY (`QuoteService.php:626-640`).

**If a line fails**, it goes to AWAITING_RECONFIRM and the quote waits. See
*Unhappy paths* below.

**Client gets:** nothing at any point.

### 8. READY — on the production floor

**In the app:** the floor works the queue. Downloading a print file
**automatically** marks the job in-production (`ProductionQueueController.php:99-101`)
— downloading *is* the start button, which nothing in the UI tells you.

**Cancellation is no longer possible from here** (`QuoteState.php:43-44`).

### 9. CLOSED — done

Set automatically when the last job closes (`QueueService.php:255-276`). The
public tracker flips to delivered.

**Client gets:** nothing. **Tell them it shipped.**

---

## Unhappy paths

### A line can't be procured

The line goes to AWAITING_RECONFIRM with the real figures recorded, and a
broadcast fires to the procurement channel (`ProcurementManager.php:95-108`).
Reasons: `qty_short` (not enough stock) or `price_jumped` (supplier moved more
than the tolerance, default 10%).

**Three choices** (`QuoteService.php:549-572`):

| Choice | Effect | Money |
|---|---|---|
| **Amend & re-procure** | new qty/price, enforces the margin floor, can fail again | quote **and invoice** re-totalled |
| **Accept as-is** | line jumps to READY | **nothing re-totalled — see blocker 3** |
| **Drop line** | line removed | quote and invoice reduced |

**Client-facing: the app tells them nothing, but their order page shows a raw
`AWAITING RECONFIRM` badge on the line with no explanation**
(`QuoteDetailPage.tsx:727-731` — not staff-gated). Call them before they see it,
especially for *drop* (their item silently vanishes) and *amend* (they pay a
different price).

### Cancelling

Staff only — buyers cannot cancel anything. Possible from every state except
READY, CLOSED and CANCELLED.

CORE stock is returned correctly by reading the ledger (`QuoteService.php:467-493`).
3D filament is not — see blocker 4.

**The cancellation reason is stored but never displayed anywhere.** The UI hint
claims it is "shown to staff on the quote history", but the history endpoint
filters that event out (`QuoteController.php:206`). Record the reason elsewhere
if it matters.

### Dead ends

| State | Way forward? |
|---|---|
| CHANGES_REQUESTED | **No** — cancel and re-create (blocker 1) |
| PROCURING with every line dropped | **No** — jobs are never built; cancel manually |
| READY | Cancel is unavailable; the floor must finish |

---

## What the client is never told

One email exists in the entire application (`app/Mail/` contains one file).
It fires on send, and on the first proof. That is all.

**No email is sent for:** quote accepted, proof approved, revised proofs,
invoice issued, order confirmed, entering production, shipped, delivered,
cancelled, or a line being dropped or re-priced.

The buyer sees those only if they happen to have the order page open, or check
the tracker themselves.

**Every milestone above therefore needs a staff-initiated phone call or email.**
That is not a nicety — it is the only channel that exists.

---

## Also worth knowing

- **`PATCH /quotes/{quote}/amend` has no caller.** The route, service and
  margin-floor validation are fully built, but nothing in the frontend calls it
  (`api.php:125`). Staff cannot adjust a draft's prices or quantities in the UI.
- **Buyers see internal line states** — `AWAITING_RECONFIRM`, `DROPPED`,
  `PROCURING` — through a generic humaniser.
- **Any failed write blanks the whole order page** rather than showing an inline
  error (`QuoteDetailPage.tsx:117`).
- **A buyer viewing a cancelled order still sees live Approve / Request-changes
  buttons.** Clicking fails safely but the message doesn't explain why.
- **`INVOICED` is never observable** — it becomes CONFIRMED in the same
  transaction. Buyer copy for that state claims "Payment received", which is
  false on the B2B path.
