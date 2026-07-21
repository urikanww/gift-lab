# Order workflow — assessment and backlog

**Date:** 2026-07-21
**Companion to:** `docs/ORDER_WORKFLOW.md` (what the workflow does today)
**Purpose:** a work queue that can be picked up cold in a new session. Every
item states what already exists, what is missing, and what still needs a
decision.

---

## Assessment

The **state machine is genuinely good.** Twelve transitions, one guarded choke
point, every move audited, per-line procurement wrapped in transactions, stock
returned by reading the ledger rather than trusting a counter. Someone thought
carefully about correctness.

What is missing is everything *around* it.

**The system models the order but does not run the business.** It tracks state
changes precisely and tells almost nobody about them. One email exists in the
entire application. There are no reminders, no expiries except a 14-day draft
sweep, and no way for staff to edit an order through the UI even though the
backend fully supports it. Staff are expected to notice things by having the
right page open at the right moment.

Three patterns recur, and they are worth naming because most items below are
instances of one of them:

1. **Built but unreachable.** The amend endpoint, its validation, its margin
   floor and its audit log are complete and correct — nothing calls them. The
   procurement desk has no data source. This is the cheapest category to fix and
   the highest value per hour.
2. **Silent side effects.** *Issue invoice* also confirms the order. Downloading
   a print file also starts the job. Attaching artwork on send also skips two
   states. Each is defensible; none is disclosed.
3. **The client is outside the system.** They get one email and are otherwise
   expected to poll a web page. Every operational gap traces back to this.

My priority order differs from a feature list: **fix what silently loses money
or data first** (P0), **then close the communication gap** (P1), because that is
where staff time is actually going today.

---

## P0 — defects that lose money, data, or orders

These are in `docs/ORDER_WORKFLOW.md` with full detail. Summarised here so this
file stands alone as a queue.

### P0-1 · `CHANGES_REQUESTED` is unrecoverable

Sending a draft with an artwork ref takes DRAFT → PROOFING, leaving
`accepted_at` null. The buyer's first *Request changes* then moves the **quote**
to CHANGES_REQUESTED (`QuoteService.php:394-398`), which has no forward path —
its only legal exits are DRAFT and CANCELLED (`QuoteState.php:35`), and nothing
performs the DRAFT transition.

**Decide:** implement `CHANGES_REQUESTED → DRAFT` (a "revise" action returning
the order to staff), or remove the slim path entirely so the state is
unreachable. The first is more useful; the second is smaller.

### P0-2 · Procurement desk never loads its data

No initial fetch, and no `GET` endpoint for awaiting-reconfirm lines exists
(`ProcurementPage.tsx:15-18`, `procurementStore.ts:38-48`). Blocked lines are
visible only to whoever had the page open when the broadcast fired.

**Needs:** an index endpoint + a fetch on mount. Straightforward.

### P0-3 · "Accept as-is" invoices for goods not produced

The `approve` branch moves the line to READY without re-totalling the quote or
invoice (`QuoteService.php:560-566`), unlike `amend` and `drop` which both do.
The client pays for the ordered quantity while the floor makes fewer.

**Also:** that branch records no stock movement, so inventory is never
decremented for those units either.

### P0-4 · Cancelling a 3D order loses filament

`Model3dProcurement.php:64-65` writes `qty_on_hand` directly instead of through
`StockLedger`, so `returnConsumedStock()` finds no movement to reverse
(`QuoteService.php:471-492`). CORE stock is correct.

**Fix:** route 3D consumption through the ledger. Check whether a filament
"variant" concept exists or needs adding — this is the one P0 with unclear
shape.

---

## P1 — your four items

### P1-1 · Staff amend line items in DRAFT

**This is mostly built already.** The backend is complete and correct:

| Capability | State |
|---|---|
| Edit `unit_price` per line | ✅ `AmendQuoteRequest.php:33` |
| Edit `qty` per line | ✅ `:34` |
| Edit delivery fee | ✅ `:30` |
| Edit notes | ✅ `:31` |
| Margin-floor enforcement over landed cost | ✅ `:39-65` |
| Amendment log (who / from / to / when) | ✅ `QuoteService.php:214-221` |
| Audit row | ✅ `:246` |
| DRAFT-only guard, transactional | ✅ `:207, :211` |
| Route | ✅ `api.php:125` — **no caller** |

**Missing:**

- **The entire UI.** Nothing in `frontend/src` calls `PATCH /quotes/{quote}/amend`.
- **Adding a new line.** `lines.*.id` is `required` + `exists`, and the service
  does `findOrFail` — there is no create path. Needs backend work.
- **Removing a line.** No delete path. `qty` is `min:1`, so it cannot be zeroed
  either.

> ### ⚠️ Landmine for whoever builds this
>
> `amend()` recomputes `subtotal` as the sum of **only the lines present in the
> request** (`QuoteService.php:216, 231-233`), but validation requires only
> `min:1` lines. **Submitting a subset silently drops the other lines from the
> subtotal**, understating the total while leaving those lines on the order.
>
> The UI must send every line, every time — or the service should be changed to
> merge amendments over the full set rather than rebuild from the payload. I'd
> do the latter; relying on a client to always send everything is how this
> breaks later.

**Decide:** does "add a line" mean picking a catalogue product (needs a product
picker + pricing lookup), or a free-text ad-hoc line (needs a nullable
`product_id` and a schema check)? These are very different jobs.

### P1-2 · Accept button in the email, and buyer reminders

**Two separate pieces.**

**(a) Accept from the email.** Today the CTA deep-links to `/orders/{reference}`
and the buyer must sign in first (`QuoteReadyMail.php:50`).

A true one-click accept needs a **signed, single-purpose, expiring link** that
authorises exactly one action. The codebase already has the pattern: the proof
thumbnail uses a 14-day signed URL on a signature-authenticated route
(`QuoteService.php:656`, `api.php:99-101`) precisely because email clients can't
send cookies. Follow it — do not invent a token scheme.

**Decide, and this is a real one:** accepting a quote is a commercial
commitment. Is a link from an email sufficient authorisation, or must the buyer
sign in? A leaked or forwarded email would let a third party accept an order.
Middle ground: the link deep-links to the order **with the Accept action
pre-focused**, still behind login — one click after auth rather than zero.

**(b) Reminders.** None exist. Only DRAFT expires (14 days, nightly). A SENT
quote or an unanswered proof sits forever with no nudge to either side.

Needs a scheduled command plus a `last_reminded_at` (or similar) so reminders
don't repeat daily. `ExpireStaleDrafts.php` is the shape to copy.

**Decide:** which states get reminders (SENT and PROOFING at minimum), after how
long, how often, and how many times before staff are told to intervene instead.

### P1-3 · Upload proof artwork through the app

**The pipeline already exists** — it just isn't wired to proofs. Customization
artwork uses `POST /uploads/artwork` to store and
`GET /uploads/artwork/preview` to exchange the stored key for a short-lived
signed URL (`UploadController.php`).

Proofs use a plain text field with no validation
(`SendQuoteRequest.php:27`, `StoreProofRequest.php:27`), and the display only
renders a link when the value is a full `http(s)` URL
(`QuoteDetailPage.tsx:156`) — so the placeholder's suggested "object-store key"
is the one input that renders as dead grey text.

**Needs:**
- File input on both the *Issue proof* and *Send with proof* controls
- Store via the existing upload endpoint; keep the returned key
- Resolve through the preview endpoint on display, as customization already does
- Keep accepting a pasted URL, or migrate existing rows — **existing proofs hold
  raw strings and will break if the display stops handling them**

**Note on S3:** `filesystems.artwork_disk` defaults to `local`
(`config/filesystems.php:32`). `temporaryUrl()` works on local, so this is not
blocking — but if you want real S3, that is a config and credentials task
separate from the UI work.

**Decide:** accepted file types and size cap. Proofs are typically PDF; the
existing artwork endpoint is image-oriented. Check whether it accepts PDF before
assuming reuse is free.

### P1-4 · Email revised proofs

**Smallest item here.** `issueProof()` only emails when the quote *enters*
PROOFING from ACCEPTED — `$enteredProofing` is false for v2 and later
(`QuoteService.php:317-319`), so revised proofs notify nobody.

**Needs:** send on every proof version. The mail already handles the
proof-present case and builds a thumbnail.

**Decide:** should the subject differ for a revision ("Your revised proof is
ready")? Recommended — an identical subject line looks like a duplicate and gets
ignored.

---

## P2 — things I would add, in the order I would do them

### P2-1 · Notify the client at every milestone

The single highest-value change in this document.

Today the app emails on send and first proof. **Nothing** for: accepted, proof
approved, revised proof, invoice issued, confirmed, in production, shipped,
delivered, cancelled, or a line dropped/re-priced. Staff currently carry all of
this by hand.

**Decide:** which milestones warrant an email, and who receives it. Note
`resolveBuyerRecipient()` (`QuoteService.php:669-688`) picks the creator or the
earliest buyer user — it **never** uses the company `billing_email`. Invoicing
notifications almost certainly should.

### P2-2 · Tell the client when their order changes

When staff drop or re-price a line, the client's order page shows a raw
`AWAITING RECONFIRM` or the line silently vanishes, with no explanation
(`QuoteDetailPage.tsx:727-731`, not staff-gated). They may be invoiced a
different amount with no notice.

**Needs:** buyer-safe copy for line states, and a notification when a line is
amended or dropped. Arguably the client should *approve* a material change
rather than merely be told — worth deciding.

### P2-3 · Disclose the silent side effects

Three actions do more than they say:

- *Issue invoice* → also CONFIRMED, the production gate (`QuoteService.php:425-426`)
- Downloading a print file → also starts the job (`ProductionQueueController.php:99-101`)
- Attaching artwork on send → also skips SENT and ACCEPTED (`:268`)

Copy changes and a confirm step on the first. Cheap; prevents real mistakes.

### P2-4 · Un-stick `PROCURING` with all lines dropped

If staff drop every line, `anyReady` is false so jobs are never built and the
quote sits in PROCURING forever (`QuoteService.php:626-640`). The comment shows
this is deliberate, but nothing then closes or cancels it.

**Decide:** auto-cancel, or surface a "nothing left to produce" prompt.

### P2-5 · Fix the buyer-facing false statements

- `BUYER_STATUS_NOTE['INVOICED']` says "Payment received" — false on B2B, where
  the invoice is created `UNPAID` (`QuoteService.php:417`)
- *Pay now* renders for every buyer but B2C payment is feature-flagged off by
  default, so on a B2B tenant it always fails (`PaymentService.php:41-44`) —
  and the failure blanks the whole page (P2-6)
- The change-request note is labelled "optional" but is required by the API; a
  blank submission silently sends the literal text *"Please revise."*
  (`QuoteDetailPage.tsx:229`)

### P2-6 · Inline errors instead of blanking the page

Any failed write sets `error`, and the page returns a full-page `ErrorState`
(`QuoteDetailPage.tsx:117`). A rejected *Issue proof* replaces the entire order
view.

### P2-7 · Surface the cancellation reason

It is captured (`QuoteService.php:453`) but the history endpoint filters that
event out (`QuoteController.php:206`), and I found no code path that renders it
— despite the UI hint claiming it is "shown to staff on the quote history".

### P2-8 · Close the authorisation inconsistency

`QuoteController::amend` and `::issueInvoice` skip `$this->authorize(...)` that
every sibling calls (`:215-225`, `:245`), so `QuotePolicy::amend` is never
invoked. Not currently exploitable — the form requests are staff-only — but the
defense-in-depth pattern the file's own comments describe is broken here. Worth
fixing before the amend UI ships and widens the surface.

### P2-9 · Gate buyer proof controls on quote state

A buyer viewing a *cancelled* order still sees live Approve / Request-changes
buttons (`QuoteDetailPage.tsx:176` checks only `!isStaff && latestOpenProof`).
Clicking fails safely but the message doesn't say why.

### P2-10 · Hide internal line states from buyers

`AWAITING_RECONFIRM`, `DROPPED`, `PROCURING` are shown to buyers through a
generic humaniser.

---

## Open questions — I cannot answer these from the code

1. **Should an email link be able to accept a quote outright**, or must the
   buyer authenticate? (P1-2a — commercial commitment vs. convenience.)
2. **Does "add a line" mean a catalogue product or an ad-hoc free-text line?**
   (P1-1 — very different jobs.)
3. **Which milestones deserve an email, and to whom?** Buyer contact, or company
   billing address for invoicing? (P2-1)
4. **Should a client have to approve a material change** — a dropped line, a
   re-priced line — or merely be told? (P2-2)
5. **Reminder cadence:** which states, after how long, how many times? (P1-2b)
6. **Should `CHANGES_REQUESTED` become recoverable, or should the slim path be
   removed** so it is unreachable? (P0-1)
7. **Should buyers be able to cancel** their own order pre-production? Today
   only staff can, from any state before READY.
8. **Is `PROOFING` without acceptance ever intended?** The slim path means a
   buyer can approve artwork having never accepted the price. If not intended,
   P0-1 resolves by deletion.
9. **Proof file types and size cap** — is PDF required? (P1-3)

---

## Notes for whoever picks this up

- **`docs/ORDER_WORKFLOW.md` is the current-state map.** Read it first; it has
  the full state table and `file:line` citations.
- The backend test suite is **584 passing**; frontend **267**. Both green at the
  time of writing.
- **Feature tests run SQLite, production runs MySQL.** This has already produced
  one test that passed for the wrong reason (a `LIKE` escape that is inert on
  SQLite). Reference lookup is also case-sensitive in tests and
  case-insensitive in MySQL. Neither is a live bug; both are documented at the
  call sites.
- `Quote::transitionTo()` is the single choke point for state changes and writes
  its own audit row — put anything that must happen on *every* transition there,
  not at call sites.
- Nine of the twelve `transitionTo` call sites live in `QuoteService`; the other
  three are in `QueueService`. There are no others.
