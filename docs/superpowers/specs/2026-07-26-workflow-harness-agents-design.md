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
happy path and the known-broken paths, proving the flow and catching the breaks.

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
| `reconfirm(lineItem, choice, data)` | `POST /line-items/{lineItem}/reconfirm` | choice ∈ amend / accept-as-is / drop |
| `pullQueue()` | `GET /production-queue` | |
| `downloadPrintFile(job)` | `GET /production-jobs/{job}/print-file` | download auto-advances job to in-production |
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

| Code | Invariant |
|---|---|
| `ILLEGAL_TRANSITION` | Every state change is in the source state's `nextStates()`. |
| `PER_LINE_FILE_COUNT` | Print-file count for a job equals its line-item count (per-line-item proofs model). |
| `INVOICE_MATCHES_PRODUCED` | Invoiced quantity equals the quantity sent to the floor. |
| `LEDGER_BALANCED_AFTER_CANCEL` | After cancel, stock/filament returned equals stock/filament consumed (via `StockLedger`). |
| `NO_STUCK_ORDER` | An order in a non-terminal state always has at least one reachable forward transition given its data. |

The validator never mutates app state.

---

## 5. Scenarios

Each scenario is one Pest test under `tests/Harness/Scenarios/`, using the
shared `beforeEach` seeding pattern from the existing suite (`seedPricing()`,
company/buyer/staff/product/variant factories).

1. **HappyPath** (`HappyPathTest.php`) — price-first route:
   draft → send (artwork blank) → buyer accept → stage+send proof → buyer
   approve → issue invoice → procure → READY → download print file → advance to
   CLOSED. Asserts: reaches CLOSED, `violations()` is empty.

2. **ArtworkSlimPathDeadEnd** (`ArtworkSlimPathDeadEndTest.php`) — Blocker 1:
   send DRAFT **with** an artwork reference (slim path DRAFT→PROOFING), buyer
   requests changes → CHANGES_REQUESTED. Asserts the harness detects the dead
   end: no forward transition performs the recovery today, so the validator
   records `NO_STUCK_ORDER`. This is a *known-broken* path; the scenario proves
   the harness catches it. (If the app is later fixed, this scenario's expected
   outcome flips to "recovers" — noted inline.)

3. **AcceptAsIsOvercharge** (`AcceptAsIsOverchargeTest.php`) — Blocker 3:
   drive to PROCURING with a quantity shortfall on a line, ProductionAgent picks
   **accept-as-is**. Validator records `INVOICE_MATCHES_PRODUCED` violation
   (client invoiced full qty, floor produces fewer).

4. **Cancel3dFilamentLoss** (`Cancel3dFilamentLossTest.php`) — Blocker 4:
   a MODEL_3D order procured (filament consumed), then StaffAgent cancels.
   Validator records `LEDGER_BALANCED_AFTER_CANCEL` violation (filament not
   returned). Contrast a CORE line in the same or a sibling assertion, which
   returns correctly.

5. **SilentBuyerChase** (`SilentBuyerChaseTest.php`) — buyer `goSilent()` after
   SENT; run the existing chase mechanism (`ChaseUnansweredOrders`) and assert a
   chase is produced for the un-actioned order. Reuses the behaviour proven by
   `tests/Feature/ChaseUnansweredOrdersTest.php`, driven through the harness
   actors.

Scenarios 2–4 target today's known-broken paths; their assertions are on the
*violation being caught*, which is the harness's core value. If a blocker is
fixed, only that scenario's expected outcome changes — the agents do not.

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
