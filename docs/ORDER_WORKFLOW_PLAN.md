# Order workflow — target design and delivery plan

**Date:** 2026-07-21
**Companion to:** `docs/ORDER_WORKFLOW.md` (current state) and
`docs/ORDER_WORKFLOW_BACKLOG.md` (assessment and queue)
**Purpose:** the agreed target workflow, the decisions behind it, and the wave
order it will be built in. This supersedes the backlog's P0/P1/P2 ordering.

---

## What changed, and why

The backlog was written from the code. This document is written from the
business, after working through it with the owner. Three of those conversations
changed the shape of the work materially:

1. **Most goods are purchased after the order is placed.** Stock levels held in
   the system are therefore not the truth. The automatic stock check is
   second-guessing a human who has already phoned the supplier.
2. **Staff edit orders as a matter of course** — confirming stock at source,
   catching marketplace price moves, reducing delivery when goods fold and
   stack. Line editing is not a convenience feature; it is where margin is made.
3. **3D print failures make filament consumption untraceable.** Tracking it
   would produce a number that drifts and lies.

The consequence of (1) and (3) together is the largest single change here: the
automatic procurement checks stop blocking orders, and a staff confirmation
becomes the real production gate.

---

## Decisions

Recorded so they are not relitigated. Each was an open question in
`ORDER_WORKFLOW_BACKLOG.md` or arose during design.

| # | Question | Decision |
|---|---|---|
| 1 | Add / remove lines in the edit screen? | **Yes, both.** Catalogue picker. Free-text ad-hoc lines out of scope for now. |
| 2 | Is any product genuinely stock-counted? | **No.** All automatic checks become advisory across the board. |
| 3 | Must the client approve a material change? | **No.** Staff contact the client manually. Automatic notice is built but **off by default**. |
| 4 | Proof file types and size cap | **Images and PDF, 3MB both.** |
| 5 | Who may confirm stock and start production? | **Any staff.** Name and timestamp recorded. |
| 6 | Where do replies to automatic email go? | **A monitored support address**, configurable in env. Not no-reply. |
| 7 | Who sees the activity timeline? | **Staff only.** Clients keep their existing simpler status view. |
| 8 | May clients cancel their own order? | **No.** Staff only, as today. |
| 9 | Should artwork approval be possible before price agreement? | **Yes — both routes supported.** See "Two routes" below. |
| 10 | Invoice document generation | **Parked.** Flow first. The invoice reference and amount continue to be recorded; the document stays in the accounting system. |
| 11 | Filament stock tracking | **Won't do.** Closed, not deferred. |

### Consequences of decisions 2 and 11

Leaving the automatic checks in place while nobody maintains the underlying
figures is the worst of both worlds: no useful data, and orders blocked by
shortages that do not exist. Hence advisory.

This makes the production gate **load-bearing**. It is the only remaining
safety net before goods are made. Recording who confirmed is the mitigation.

### Consequence of decision 10

The accounts-contact problem parks with it. `companies.billing_email` is
populated from the registering user's own address (`AuthController.php:55`) and
has no edit screen, so it is unreliable data — but nothing automatic will be
sent to it until invoicing is unparked.

---

## Two routes

The workflow supports artwork approval before **or** after price agreement.

Today the artwork-first path exists by accident: sending a draft with an
artwork reference takes a shortcut (`QuoteService.php:268`) that skips SENT and
ACCEPTED. It has two defects that make it unusable as a supported route:

- **It dead-ends.** A change request on that path moves the quote to
  CHANGES_REQUESTED (`QuoteService.php:394-398`), whose only legal exits are
  DRAFT and CANCELLED (`QuoteState.php:35`) — and no code performs the DRAFT
  transition. The order must be cancelled and rebuilt.
- **It conflates two approvals.** Approving the artwork back-fills acceptance
  (`QuoteService.php:361-365`), so a client can be committed to a price they
  were never shown.

Both are fixed in Wave 2. **Price agreement and artwork approval become two
distinct acts on both routes; neither ever stands in for the other.**

---

## The target workflow

⚙️ automatic · 🆕 new · **bold** = staff action

### 1. Order arrives
From the cart, or entered by staff.

### 2. 🆕 Check and adjust the order
The screen staff will live in. Confirm stock at source, update prices against
the marketplace, reduce delivery where goods fold and stack, adjust quantities,
🆕 add or remove lines.

Margin floor enforced live. 🆕 Rejections appear **at the field**, in plain
language, with what to do — the order stays on screen. Every edit recorded
against the staff member.

### 3. Send it — either route
- **Price first:** send without artwork → client agrees price → artwork issued
  for approval.
- **Artwork first:** 🆕 upload artwork and send both → client approves artwork →
  🆕 client agrees price as a separate step.

🆕 Artwork uploaded in-app (images and PDF, 3MB). 🆕 Shown in the client's email.

⚙️ Order email sent. ⚙️ Chased days 3, 7, 12. ⚙️ Day 14: chasing stops, flagged
to staff. (Aligns with the existing 14-day stale-draft sweep,
`ExpireStaleDrafts.php:22`.)

### 4. Approvals
Client **agrees the price** and **approves the artwork** as two separate acts.

⚙️ Staff notified of each. ⚙️ Every proof version emailed, revisions marked as
such — today only the first proof notifies (`QuoteService.php:317`).
⚙️ Unanswered proofs chased days 2, 5, 9, then flagged to staff.

Change requests loop on both routes. 🆕 No dead end.

### 5. Commit the order
🆕 Renamed from "issue invoice" to say what it does. The same transaction drives
the quote through INVOICED to CONFIRMED (`QuoteService.php:425-426`) — the
production gate — with no current indication that it does so.

🆕 Confirmation step stating the commitment explicitly.
🆕 Pay now hidden where payment is not enabled (`PaymentService.php:41-44`).

### 6. Sourcing
🆕 Automatic checks are **advisory**. Nothing is blocked by a stock figure
nobody maintains.

Where a line is flagged, staff may still **Amend**, **Accept as-is** 🆕 *(now
corrects the bill)*, or **Drop line**.

🆕 One screen listing every order awaiting a decision, **which loads its own
data** — today it only shows problems to whoever had the page open when the
broadcast fired (`ProcurementPage.tsx:15-18`).

⚙️ Client notification on material change: **built, off by default** (decision 3).

### 7. 🆕 Confirm stock and start production
The real gate. Lines and quantities listed, ticked through, confirmed. Name and
timestamp recorded.

⚙️ Client told production has started.

### 8. Production
🆕 Explicit **Start job** button. Today, downloading the print file marks the job
in production (`ProductionQueueController.php:99-101`) — the download *is* the
start button, and nothing says so.

### 9. Complete
⚙️ Closes when the last job closes (`QueueService.php:255-276`). ⚙️ Client told
it shipped, and again when delivered.

### Throughout
🆕 The order page shows every automatic message sent, everything scheduled with
its date, and what is expected next. Staff only.

### Cancelling
Staff only, before production. 🆕 The reason is shown on the history — today it
is captured (`QuoteService.php:453`) but filtered out of the history endpoint
(`QuoteController.php:206`) despite the UI hint claiming otherwise.
⚙️ Client notified.

---

## Delivery waves

Five waves. Each ends with both suites green and is independently shippable.
Baseline at the time of writing: **584 backend (Pest), 267 frontend (Vitest)**.

### Wave 1 — Order editing screen

Edit price, qty, delivery, notes. Add and remove lines. Live margin floor.
Inline field errors.

**Must ship inside this wave — the subtotal defect.** `amend()` recomputes
`subtotal` from only the lines present in the request (`QuoteService.php:216`,
`:231-233`) while validation requires only `min:1` lines. Submitting a subset
leaves the omitted lines on the order but drops them from the total. Unreachable
today because nothing calls the endpoint (`api.php:125`); certain to fire once
staff use the screen daily.

Fix by merging amendments over the full line set rather than rebuilding from the
payload.

Also here:
- Add-line and remove-line paths. `lines.*.id` is currently `required` +
  `exists` and the service does `findOrFail`; `qty` is `min:1`. Both need
  backend work.
- `QuoteController::amend` skips the `$this->authorize()` its siblings call
  (`:215-225`), so `QuotePolicy::amend` never runs. Close it before the surface
  widens.

*Largest wave. Add/remove is genuinely new; the rest exists and needs wiring.*

### Wave 2 — Uploads and both routes

Ships as one piece. Making upload easy while the dead end exists walks staff
into it more often.

- Proof and artwork upload in-app, images and PDF, 3MB. Reuse
  `POST /uploads/artwork` + `GET /uploads/artwork/preview`
  (`UploadController.php`); the existing request allows images to 10MB
  (`ArtworkUploadRequest.php:33`) and needs PDF added and the cap tightened.
- **Existing proofs hold raw strings** — display must keep handling a pasted URL
  or those rows break.
- Artwork rendered in the send email.
- CHANGES_REQUESTED gains a forward path.
- Price agreement and artwork approval separated on both routes.

### Wave 3 — Sourcing and the production gate

- Automatic checks become advisory.
- Production gate: line list, confirmation, actor and timestamp.
- Awaiting-decision index endpoint + fetch on mount (`procurementStore.ts:38-48`).
- **Accept-as-is re-total.** `reconfirmLine()`'s `approve` branch
  (`QuoteService.php:560-566`) leaves `$totalDelta` at `0.0`, so
  `retotalAfterReconfirm()` never fires and the client is billed the ordered
  quantity. Set `qty` to `procured_qty` and let the existing retotal run.
  Reject `approve` where `procured_qty < 1` — staff should drop the line.
- Explicit Start job button.
- Commit-order rename and confirmation step.
- Pay now hidden where payment is disabled.
- Wholly-dropped orders no longer stick in PROCURING (`QuoteService.php:626-640`).

> **Note on stock movements.** The original P0-3 also observed that `approve`
> records no stock movement, because every strategy returns its shortfall before
> consuming (`CoreProcurement.php:47-54`, `Model3dProcurement.php:54-62`). Given
> decisions 2 and 11, ledger accuracy is no longer a goal and this half is
> **not** being fixed. Recorded so a later reader does not mistake it for an
> oversight.

### Wave 4 — Notifications

Order sent, accepted, artwork approved, every proof version, production started,
shipped, delivered, cancelled. Reminders per the schedule above.

Settings per message: on/off, timing, wording. Replies to the configurable
support address.

Material-change notice built, default off.

*Largest build from scratch — one mail class exists in the entire application.*

### Wave 5 — Activity timeline

What was sent, what is scheduled, what is next. Staff only. Last, because it has
nothing to describe until Wave 4 exists.

Also here: surface the cancellation reason.

---

## Risks carried

**The production gate is the only safety net.** Once checks are advisory,
nothing else catches a stock mistake before goods are made. Recorded
attribution is the mitigation, not a guarantee.

**Wave 4 can overrun.** Notifications plus scheduling plus a settings screen is
a system, not a feature. Waves 1–3 stand alone if it slips.

**Two verification passes exist per order** — staff at step 2, the system at
step 6. The machine's opinion is based on older information than the human's.
Advisory is the minimum; removing the check entirely for non-stocked products
remains reasonable and is deliberately deferred rather than rejected.

---

## Notes for whoever picks this up

- `Quote::transitionTo()` is the single choke point for state changes and writes
  its own audit row. Anything required on *every* transition belongs there.
- Nine of the twelve `transitionTo` call sites are in `QuoteService`; the other
  three are in `QueueService`.
- Feature tests run SQLite, production runs MySQL. This has already produced one
  test that passed for the wrong reason.
- Jobs are built from `$lines->sum('qty')` (`QueueService.php:92`), not
  `procured_qty` — relevant to the Wave 3 re-total.
