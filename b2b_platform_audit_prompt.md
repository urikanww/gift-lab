# B2B Gifting Platform — Full Audit Prompt

> Usage: paste Pass 1 verbatim into the auditing session together with the two
> reference documents. Pass 2 is optional and run separately for discovery.
> Reruns of Pass 1 produce the same row IDs, so runs are diffable.

---

## PASS 1 — Checklist Audit (deterministic, diffable)

You are running a FULL audit of a B2B custom gifting platform against:

1. The engineering handoff spec (MD file)
2. The B2B build spec (Word doc)

### Non-negotiable execution rules

- This is a CHECKLIST audit. You MUST output one result row for EVERY check
  ID below. Skipping, merging, or sampling checks is a failed audit.
- If a check cannot be executed (missing access, feature absent, environment
  broken), the row still appears with status NOT-TESTABLE and the reason.
  Silence is never acceptable.
- Statuses: PASS / FAIL / PARTIAL / SPEC-GAP / NOT-TESTABLE.
- Every PASS requires evidence (URL, repro steps, screenshot ref, code path).
  A PASS without evidence must be downgraded to NOT-TESTABLE.
- Do not stop early. Do not summarize instead of completing rows. The audit
  is complete only when all check IDs have a row.
- Before writing the final report, self-verify: count your rows against the
  checklist. If any ID is missing, add it before responding.

### Checklist

#### A. B2B journey conformance

| ID | Check |
|----|-------|
| A1 | Public browse works with no account |
| A2 | Account required only at quote request, nowhere earlier |
| A3 | Quote request captures all fields the spec requires |
| A4 | Proof/FA generated and presented for sign-off before production |
| A5 | Sign-off is recorded (who, when, what artifact) |
| A6 | PO step follows sign-off, in spec order |
| A7 | Invoice generated per spec |
| A8 | Order cannot enter production without signed proof |
| A9 | Order status stages match spec exactly (received / in production / shipped) |
| A10 | No extra or missing states vs the spec's state machines (list deltas) |

#### B. Catalogue & sourcing conformance

| ID | Check |
|----|-------|
| B1 | Light-stock vs make-to-order flagged correctly per product |
| B2 | Scraped products pass completeness gate before publish |
| B3 | Auto-publish toggle exists and works |
| B4 | Per-item reason tags present |
| B5 | Freeze-on-quote snapshot: quoted price immune to source changes |
| B6 | 10% price drift threshold enforced |
| B7 | 3D models: only CC0/CC-BY ingested; test with a non-compliant licence |
| B8 | Scraped images display as-is (v1 behavior) with tech-debt log entry |

#### C. Customization / personalization UX — known problem area, test hardest

Test each on desktop AND mobile, as a first-time user.
One row per check per platform (e.g. C1-desktop, C1-mobile).

| ID | Check |
|----|-------|
| C1 | Upload accepts PNG, JPG, SVG, PDF; rejects others with actionable error |
| C2 | Oversized file → clear error, not silent failure or crash |
| C3 | Low-resolution file → warning about print quality |
| C4 | Logo can be dragged to position on product/model |
| C5 | Logo can be resized |
| C6 | Logo can be rotated |
| C7 | Alignment guides / snapping / safe-zone boundaries exist |
| C8 | Placement on 3D model maps to surface without distortion |
| C9 | Preview matches actual print position and size |
| C10 | Steps + time for a first-time user to place a logo acceptably (record both) |
| C11 | Submitted file reaches production as ready FA, no reprocessing |
| C12 | FA artifact contains: resolution, coordinates, size, print method |
| C13 | Photo upload (not just logo) works through the same path |
| C14 | Every hesitation/failure point encountered, ranked by severity |

#### D. Size-tiered customization pricing (S / M / L)

| ID | Check |
|----|-------|
| D1 | Identify the size-determination mechanism (user-select / measured bounding box / placement zone) — state which, with evidence |
| D2 | S tier prices correctly in designer, cart, quote, invoice |
| D3 | M tier prices correctly in designer, cart, quote, invoice |
| D4 | L tier prices correctly in designer, cart, quote, invoice |
| D5 | Resizing across S→M boundary updates price live |
| D6 | Resizing across M→L boundary updates price live |
| D7 | All three tiers configurable in superadmin, no hard-coded values |
| D8 | Threshold rule defined anywhere (app or spec)? If not: SPEC-GAP with the exact business decision required |
| D9 | Name personalization priced separately from logo, per spec |
| D10 | Multiple customizations on one item price additively and correctly |

#### E. Admin & configurability

| ID | Check |
|----|-------|
| E1 | Pricing/margin editable in superadmin without deploy |
| E2 | Pay-now vs quote cutoff configurable |
| E3 | Scraped-catalogue oversight controls (approve/pin/remove) work |
| E4 | Manual product management works |

### Output format (in this order, nothing omitted)

1. Row-complete results table: `ID | check | status | evidence | severity`
   (blocker / major / minor). ALL IDs present.
2. Coverage confirmation line: "X of X checks reported" — numbers must match.
3. B2B readiness verdict: READY / NOT READY, one paragraph, no hedging.
4. Top 5 customization friction points with a concrete fix each.
5. Spec gaps requiring business decisions (not dev fixes).
6. Ordered fix list: blockers first, each with an acceptance criterion.

---

## PASS 2 — Discovery Audit (run separately, after Pass 1)

You have already completed the checklist audit (IDs A1–E4). Now run a
free-hunt: adversarial exploration of the platform OUTSIDE the checklist.

Rules:

- Do not repeat any finding already covered by a checklist ID.
- Attack the platform the way a hostile first-time corporate buyer,
  a careless uploader, and a malicious user each would.
- Priority areas: designer edge cases, quote/PO race conditions, price
  manipulation via client-side state, licence-gate bypass attempts,
  mobile-only breakage.
- Report each finding as: description | repro steps | severity | suggested
  checklist ID to add for future runs.

The last field matters: every discovery finding must be convertible into a
new checklist row, so Pass 1 grows over time and stays the single source
of coverage.

---

## Known open item (blocks a clean run)

**D8 will return SPEC-GAP on every run until resolved.** The spec defines
logo pricing as $3 (S) / $5 (M) only — no Large tier, and no rule for how
size is determined (user-selected vs measured bounding box in mm vs
placement zone). Define the threshold rule and L-tier price in both spec
documents before running, or accept paying for the same finding repeatedly.
