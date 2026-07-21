# Next-session prompts

Copy one of these into a fresh session. Each is self-contained — a new session
starts with no memory of the work that produced the backlog.

---

## Starter prompt (use this if unsure where to begin)

```
Read docs/ORDER_WORKFLOW.md and docs/ORDER_WORKFLOW_BACKLOG.md first — they map
this project's order lifecycle and a prioritised backlog, both written from the
code with file:line citations.

I want to work through the P0 items: four defects that lose money, data or
orders. Start with P0-3 ("Accept as-is" invoices for goods not produced) since
it is actively overcharging clients.

Before writing anything, verify the defect still exists and show me the code
path that causes it. I would rather you tell me the doc is wrong than fix
something that isn't broken.

Then propose a fix and wait for me to approve it. Note that P0-3 has two halves
— the missing re-total and the missing stock movement — and they may want
different treatment.

Both suites are green at the time of writing: 584 backend (Pest), 267 frontend
(Vitest). Keep them green. Work on a branch, not master.
```

---

## Per-item prompts

### P0-1 · CHANGES_REQUESTED is unrecoverable

```
Read docs/ORDER_WORKFLOW_BACKLOG.md, item P0-1, plus the state table in
docs/ORDER_WORKFLOW.md.

A quote that reaches CHANGES_REQUESTED can never move forward — cancel and
re-create is the only exit. It is only reachable via the "slim path" (sending a
DRAFT with an artwork ref attached, which skips SENT and ACCEPTED).

Two options are on the table and I have not decided:
  (a) implement CHANGES_REQUESTED -> DRAFT as a staff "revise" action
  (b) remove the slim path entirely so the state becomes unreachable

Investigate both, tell me which you would pick and why, and flag anything the
backlog missed. Do not implement until I choose.

Related open question from the backlog: is PROOFING-without-acceptance ever
intended? The slim path lets a buyer approve artwork having never accepted the
price. If that is not intended, (b) resolves this by deletion.
```

### P0-2 · Procurement desk never loads its data

```
Read docs/ORDER_WORKFLOW_BACKLOG.md, item P0-2.

ProcurementPage only subscribes to a Reverb broadcast — there is no initial
fetch and no GET endpoint for awaiting-reconfirm lines. Blocked lines are
visible only to whoever had the page open when the broadcast fired.

Build the index endpoint and fetch-on-mount. Scope it to staff via the existing
policy pattern rather than an inline check — see QuoteController::history for
the shape.

Follow TDD. Both suites are green (584 backend, 267 frontend); keep them so.
Work on a branch.
```

### P0-4 · Cancelling a 3D order loses filament

```
Read docs/ORDER_WORKFLOW_BACKLOG.md, item P0-4.

Model3dProcurement writes qty_on_hand directly instead of going through
StockLedger, so no StockMovement row exists and returnConsumedStock() has
nothing to reverse. Cancelling a 3D order after procurement permanently loses
that filament. CORE stock is handled correctly — use it as the reference.

This is the P0 with the least clear shape: check whether filament has a variant
concept or whether one needs adding. Investigate and propose before building.

Write a failing test that proves the loss first, so the fix is demonstrably a
fix.
```

### P1-1 · Staff amend line items in DRAFT

```
Read docs/ORDER_WORKFLOW_BACKLOG.md, item P1-1.

The backend is already complete: editing unit_price, qty, delivery and notes,
with margin-floor enforcement, an amendment log and an audit row. The route
exists at PATCH /quotes/{quote}/amend and NOTHING calls it. The work is mostly
UI.

Two things before you start:

1. There is a landmine documented in the backlog — amend() rebuilds subtotal
   from only the lines present in the request, while validation requires just
   min:1. A partial submission silently understates the total. I want the
   SERVICE fixed to merge over the full line set, not the UI made responsible
   for always sending everything. Do that first, with a test.

2. Adding and removing lines are NOT built and need backend work. Before
   building, ask me whether "add a line" means picking a catalogue product or
   an ad-hoc free-text line — they are very different jobs and I have not
   decided.

Also fix P2-8 while you are here: QuoteController::amend skips the
authorize() call every sibling makes, so QuotePolicy::amend is never invoked.
Not exploitable today, but the amend UI widens that surface.
```

### P1-2 · Email accept button and buyer reminders

```
Read docs/ORDER_WORKFLOW_BACKLOG.md, item P1-2.

Two separate pieces. Start by discussing (a) with me before writing code.

(a) One-click accept from the email. Accepting a quote is a commercial
commitment, so a link that accepts outright means a forwarded or leaked email
can commit an order. Options: a signed single-purpose expiring link (the
codebase already has this pattern — see the proof thumbnail's signed route), or
deep-linking with Accept pre-focused but still behind login. Tell me the
trade-offs and let me choose.

(b) Reminders. None exist today; only DRAFT expires, after 14 days. Copy the
shape of ExpireStaleDrafts.php. Needs a last_reminded_at (or similar) so they
don't repeat daily. Ask me which states get reminders, after how long, how
often, and when to stop and escalate to staff.
```

### P1-3 · Upload proof artwork through the app

```
Read docs/ORDER_WORKFLOW_BACKLOG.md, item P1-3.

Proof artwork is a plain text field with no validation, and the display only
renders a link if the value is a full http(s) URL — so the placeholder's
suggested "object-store key" is the one input shape that renders as dead text.

The pipeline already exists for customization artwork: POST /uploads/artwork to
store, GET /uploads/artwork/preview to exchange the key for a signed URL. Wire
proofs to it.

Two things to check before assuming reuse is free:
  - does the upload endpoint accept PDF? proofs usually are
  - existing proofs hold raw strings and will break if the display stops
    handling them — keep accepting a pasted URL, or migrate

filesystems.artwork_disk defaults to 'local' and temporaryUrl() works there, so
real S3 is a separate config task, not a blocker.
```

### P1-4 · Email revised proofs

```
Read docs/ORDER_WORKFLOW_BACKLOG.md, item P1-4. Smallest item in the backlog.

issueProof() only emails when the quote ENTERS Proofing from Accepted, so proof
v2 and later notify nobody — the buyer only finds out if they happen to have
the page open.

Send on every proof version. Give revisions a distinct subject line so they do
not read as duplicates of the first email.

Follow TDD; there is an existing mail test to model on.
```

---

## Standing context worth pasting into any session

```
- Both suites green at last check: 584 backend (Pest), 267 frontend (Vitest).
- Feature tests run SQLite; production runs MySQL. This has already produced one
  test that passed for the wrong reason. Be suspicious of anything that depends
  on LIKE escaping or string case.
- Quote::transitionTo() is the single choke point for state changes and writes
  its own audit row. Anything that must happen on EVERY transition goes there.
- Nothing in recent work has been verified against a running app — the suites
  are the only evidence. If a change is observable in the browser, verify it.
- Work on a branch. Do not commit to master without asking.
```
