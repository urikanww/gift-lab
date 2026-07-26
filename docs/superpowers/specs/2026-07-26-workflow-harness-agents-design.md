# Multi-Agent Workflow Test Harness — Design

**Date:** 2026-07-26
**Status:** Approved for planning
**Scope:** A role-based, HTTP-driven test harness that runs and validates the
real staff + production order workflow end-to-end, and surfaces the known
workflow blockers as caught violations.

---

## 1. Purpose

Gift Lab's order spine is a nine-plus-state quote machine
(`app/Enums/QuoteState.php`) driven through `/api/...` endpoints by three human
roles: internal staff, the buyer, and production/procurement staff. The flow has
several documented breaks (`docs/ORDER_WORKFLOW.md`, "four blockers").

Today those flows are covered by scattered per-endpoint Feature tests. There is
no single harness that plays the roles against each other, drives an order from
DRAFT to CLOSED as the real actors would, and asserts workflow-level invariants
across the whole journey.

This harness provides that. Four cooperating **agents** each play one role by
calling the real API, and a **validator** agent acts as a test oracle that
checks invariants after every hop. Scenarios wire the agents together to run the
happy path and the once-broken paths, proving the flow end-to-end.

**Note on the four blockers.** `docs/ORDER_WORKFLOW.md` (2026-07-21) is stale:
verified against current code, blockers 1 and 3 are already fixed (the
`CHANGES_REQUESTED` state now has forward edges; the reconfirm `approve` branch
now re-totals via `retotalAfterReconfirm`). Blocker 4 (3D filament return on
cancel) is unconfirmed — `returnConsumedStock` reads the stock ledger but skips
`variant === null` lines, which a 3D line may be. Therefore the blocker
scenarios are written as **regression tests**: they drive the once-broken path
and assert the invariant now HOLDS (locking the fix; failing loudly if it ever
regresses). Blocker 4 is built the same way — if the path is still broken, the
validator catches the violation; if fixed, it locks the fix.

**Non-goals:** no LLM/AI, no new runtime code shipped into the app, no changes
to the order state machine or services. This is test-only infrastructure.

---

## 2. Approach

**Chosen: HTTP-driven role agents + Pest scenarios (Approach A).**

Each agent is a plain PHP class that drives the app through Laravel's test HTTP
client (`postJson`/`getJson`) authenticated as the relevant role via
`Sanctum::actingAs()` — exactly how the existing Feature suite drives the app
(`tests/Feature/QuoteFlowTest.php`). This exercises the real routes, Form
Requests, policies/permissions, guarded state transitions, and broadcast events.

Rejected alternatives:

- **Service-layer agents** (call `QuoteService` directly): faster but bypasses
  the API, permissions, and validation — does not test the staff flow as
  performed, only internals.
- **Artisan runner against a seeded DB**: useful for staging demos but not
  CI-gated and mutates real data. Out of scope for this iteration.

The harness lives under `tests/Harness/` and runs as part of `vendor/bin/pest`.

---

## 3. Architecture

```
tests/Harness/
  Agents/
    StaffAgent.php          plays internal staff (permission: quotes.edit)
    BuyerAgent.php          plays the customer
    ProductionAgent.php     plays procurement + production floor
    ValidatorAgent.php      test oracle — asserts invariants, records violations
  Support/
    HarnessContext.php      shared per-scenario state (actors, quote ref, log)
    Violation.php           value object: {code, message, state, context}
  Scenarios/
    HappyPathTest.php
    ArtworkSlimPathDeadEndTest.php
    AcceptAsIsOverchargeTest.php
    Cancel3dFilamentLossTest.php
    SilentBuyerChaseTest.php
```

### 3.1 Agents

Each agent is constructed with a `HarnessContext` (holds the authenticated
actors and the quote under test). Agent methods perform one real staff/buyer/
production action, return the decoded response, and update context. Agents do not
assert — assertion is the validator's job — but they DO surface HTTP failures so
a scenario can distinguish an expected block from an unexpected 500.

**StaffAgent** — internal staff, `Sanctum::actingAs(staffAdmin)`.

| Method | Endpoint | Notes |
|---|---|---|
| `createDraft(company, lineItems, opts)` | `POST /quotes` | returns quote ref/id |
| `send(opts)` | `POST /quotes/{quote}/send` | `opts.artwork` optional — the slim-path trigger |
| `stageProof(lineItem, url)` | `POST /quotes/{quote}/lines/{lineItem}/proofs` | per-line proof |
| `sendProofs()` | `POST /quotes/{quote}/proofs/send` | |
| `issueInvoice(poRef)` | `POST /quotes/{quote}/invoice` | drives INVOICED→CONFIRMED |
| `procure()` | `POST /quotes/{quote}/procure` | |
| `cancel(reason)` | `POST /quotes/{quote}/cancel` | staff-only |

**BuyerAgent** — customer, `Sanctum::actingAs(buyer)`. Scriptable disposition.

| Method | Endpoint | Notes |
|---|---|---|
| `accept()` | `POST /quotes/{quote}/accept` | |
| `approveProof(proof)` | `POST /proofs/{proof}/decide` | decision = approve |
| `requestChanges(proof, note)` | `POST /proofs/{proof}/decide` | decision = changes |
| `approveAll()` | `POST /quotes/{quote}/proofs/approve-all` | |
| `goSilent()` | — | no-op; models a non-responding buyer |

**ProductionAgent** — procurement + floor, permissions `procurement.manage` /
`production.manage`.

| Method | Endpoint | Notes |
|---|---|---|
| `reconfirm(lineItem, action, data)` | `POST /line-items/{lineItem}/reconfirm` | `action` ∈ `amend` / `approve` (accept-as-is) / `drop`; `amend` also sends `qty` + `unit_price` |
| `pullQueue()` | `GET /production-queue` | |
| `downloadPrintFile(job, ref)` | `GET /production-jobs/{job}/print-file?ref=...` | streams the file; does **not** start the job (download-starts-job was removed — starting is now explicit) |
| `startJob(job)` | `POST /production-jobs/{job}/advance` (`state`) | explicit start into production |
| `advanceNext(job)` | `POST /production-jobs/{job}/advance-next` | drives toward CLOSED |

**ValidatorAgent** — reads DB state (not HTTP) and `QuoteState::canTransitionTo`.
Exposes:

- `assertLegalTransition(from, to)` — the target must be in `from->nextStates()`.
- `check(invariantName)` — run one named invariant, record a `Violation` if it
  fails rather than throwing (so a scenario can assert on the collected set).
- `violations(): Violation[]` — everything caught this scenario.

### 3.2 Feedback loop

The proof cycle is the one real feedback loop. `BuyerAgent::requestChanges` →
`StaffAgent::stageProof` + `sendProofs` (revised proof) → buyer decides again.
The scenario bounds this to `MAX_PROOF_ROUNDS` (default 3). If the loop does not
resolve within the bound, the validator records a `PROOF_LOOP_UNRESOLVED`
violation. This mirrors the real PROOFING loop and prevents an infinite scenario.

---

## 4. Invariants (ValidatorAgent)

Checked after the relevant hop in each scenario:

Implemented as named `ValidatorAgent::check()` methods (generic, data-only):

| Code | Invariant |
|---|---|
| `ILLEGAL_TRANSITION` | A given state change is in the source state's `nextStates()`. |
| `INVOICE_MATCHES_PRODUCED` | For every non-dropped line, billed `qty` equals `procured_qty` when a produced quantity is set. |
| `LEDGER_BALANCED_AFTER_CANCEL` | For every line with a variant, net `StockMovement` delta (SALE + RETURN) is zero after cancel. |

Two further invariants from the flow are **scenario-level** assertions rather than
generic validator methods, because they need job/track or path context the
scenario already holds:

- **Per-line print file present** — the happy path asserts each built job carries
  a non-empty `artwork_refs`, and reaching CLOSED proves the files streamed.
- **No stuck order** — the changes-requested scenario asserts the order actually
  advances past `CHANGES_REQUESTED` (recovers), which is the concrete form of
  "not stuck".

Blocker 4's filament check is also scenario-level (Task in the plan reads
`Model3dProcurement` to assert filament `qty_on_hand` is restored), because a
direct-column decrement leaves no `StockMovement` for the generic ledger check
to see. The validator never mutates app state.

---

## 5. Scenarios

Each scenario is one Pest test under `tests/Harness/Scenarios/`, using the
shared `beforeEach` seeding pattern from the existing suite (`seedPricing()`,
company/buyer/staff/product/variant factories).

1. **HappyPath** (`HappyPathTest.php`) — price-first route:
   draft → send (artwork blank) → buyer accept → stage+send proof → buyer
   approve → issue invoice → procure → READY → start job → advance to CLOSED.
   Asserts: reaches CLOSED, `violations()` is empty.

2. **ChangesRequestedRecovers** (`ChangesRequestedRecoversTest.php`) — Blocker 1
   regression: drive to PROOFING, buyer requests changes → CHANGES_REQUESTED,
   then StaffAgent issues a revised proof and buyer approves. Asserts the order
   recovers (reaches ARTWORK_APPROVED/PROOF_APPROVED) and `violations()` is
   empty — proving `CHANGES_REQUESTED` is no longer a dead end.

3. **AcceptAsIsRetotals** (`AcceptAsIsRetotalsTest.php`) — Blocker 3 regression:
   force a line into AWAITING_RECONFIRM (set `block_on_qty_short=1` in
   `PricingConfig`, then procure with stock < qty), ProductionAgent picks
   **accept-as-is** (`action: approve`). Asserts `INVOICE_MATCHES_PRODUCED`
   holds — line `qty` is set to `procured_qty` and the quote/invoice re-total,
   so the buyer is not overcharged.

4. **Cancel3dFilamentReturn** (`Cancel3dFilamentReturnTest.php`) — Blocker 4,
   status unconfirmed: a MODEL_3D order procured (filament consumed), then
   StaffAgent cancels. Run `check('LEDGER_BALANCED_AFTER_CANCEL')`. If the path
   is fixed, the invariant holds and the test locks it; if filament is still
   lost, the validator records the violation and the test documents it (asserting
   the observed reality, with an inline note). The build task reads
   `Model3dProcurement` + `returnConsumedStock` to settle which.

5. **SilentBuyerChase** (`SilentBuyerChaseTest.php`) — buyer `goSilent()` after
   SENT; run the existing chase command (`quotes:chase`) and assert a chase is
   produced for the un-actioned order (`reminders_sent` increments). Reuses the
   behaviour proven by `tests/Feature/ChaseUnansweredOrdersTest.php`, driven
   through the harness actors.

Scenarios 2–4 are regression tests over paths the `ORDER_WORKFLOW.md` doc calls
broken but which current code has fixed (1, 3) or leaves unconfirmed (4). They
lock the current behaviour: same agents drive the path; only the expected
outcome reflects reality.

---

## 6. Failure handling, optimization, scalability

- **Bounded loops:** the proof feedback loop is capped at `MAX_PROOF_ROUNDS`;
  exceeding the cap is itself a recorded violation, never an infinite run.
- **Expected vs unexpected failure:** agents surface HTTP status so a scenario
  distinguishes an expected 422 block from an unexpected 500. An unexpected 500
  fails the test hard.
- **Isolation / parallelism:** each scenario is self-contained (fresh DB via the
  standard `RefreshDatabase` used across the suite) and carries no shared state,
  so scenarios run under Pest parallel with no ordering constraints.
- **Extensibility:** a new role action is one agent method; a new scenario is one
  file. No orchestration rewiring — scenarios compose agent methods directly.
- **Single source of truth:** the validator reads `QuoteState::nextStates()` for
  legality, so it stays correct automatically if the state machine changes.

---

## 7. Testing the harness itself

The scenarios *are* the tests. Additionally:

- A tiny unit test for `ValidatorAgent::assertLegalTransition` against
  `QuoteState` to guard the oracle logic.
- A `Violation` value-object unit test (equality, code/message).

All run under `vendor/bin/pest`; the harness adds no new tooling or CI config.

---

## 8. Open questions

None blocking. Exact request payload shapes (proof decision keys, reconfirm
`choice` values) will be read from the existing controllers/Form Requests during
implementation rather than guessed.
