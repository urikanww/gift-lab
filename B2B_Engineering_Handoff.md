# Custom Gifting Platform — Engineering Handoff (B2B v1)

**Audience:** CTO + build team
**Companion doc:** `B2B_Build_Spec.docx` (business-flow spec with flow diagrams). This file is the engineering-facing version: entities, states, integrations, and phased build order. Where the two differ, the .docx prose is authoritative on *intent*; this file is authoritative on *structure*.

> **Stack is not prescribed.** Language, framework, database, and hosting are the CTO's call. This spec defines domain structure, states, and integration contracts only — implement in whatever stack the team prefers.

---

## 1. What we're building (one paragraph)

A self-serve platform that turns in-house UV and 3D printing into an online B2B business. Companies browse a catalogue with no account, design a customised product on screen, request a quote, approve a formal proof, issue a PO, and the job flows into a single shared production queue. Two printer tracks (UV = decorate a sourced blank; 3D = fabricate from a licensed model file) feed the same queue. Catalogue variety comes from stocked core blanks, a scraped-UV-blank admin gate, and licence-gated 3D models pulled via API.

**Launch scope: B2B only.** B2C ("pay now" via Stripe) is deferred but feeds the same queue when added — build shared components accordingly.

---

## 2. Core principles that constrain the build

1. **Two hard gates block production.** No job is produced without (a) a recorded proof approval, and (b) confirmation the physical blank is on the floor (UV) or filament is available (3D). Enforce these as state transitions, not UI conventions.
2. **Readiness, not order time, drives the queue.** A job is queued by *when it became ready*, not when it was placed.
3. **Scraped data is never authoritative.** Displayed stock/price is an estimate. The authoritative read is the procurement-time re-check. Design so a scraper failure degrades only the scraped catalogue and never blocks the core flow.
4. **Product classes are isolated tracks.** Core/variant, scraped-UV, and 3D-model products share the order/quote/proof/queue spine but have different procurement logic. Keep procurement pluggable per class.
5. **Pricing is fully dynamic.** No hardcoded margins or fees. All pricing config lives in the superadmin dashboard and is read at quote time.

---

## 3. Domain entities

Fields listed are the minimum; add audit columns (`created_at`, `updated_at`, `created_by`) throughout. All money fields carry currency + amount.

### Product
- `id`, `name`, `description`, `class` (`CORE` | `SCRAPED_UV` | `MODEL_3D`)
- `base_cost`, `dimensions`, `weight`, `print_method` (`UV` | `FDM` | `RESIN`)
- `publish_state` (see §5.3), `stock_mode` (`STOCKED` | `MAKE_TO_ORDER`)
- `image_url`, `source_url` (scraped), `source_product_id` (scraped)
- `stock_estimate` (scraped/indicative), `is_printable` (bool, gated)
- For `MODEL_3D`: `license` (`CC0` | `CC_BY` | `OWNED` | `BLOCKED`), `creator_credit`, `model_file_ref`

### Variant  (core products)
- `id`, `product_id`, `attributes` (e.g. `{color, size, material}`), `stock_on_hand`, `reorder_threshold`, `price_delta`

### Quote
- `id`, `company_id`, `state` (see §5.1), `currency`
- `line_items[]`, `subtotal`, `delivery`, `total`
- `price_snapshot_at` (freeze timestamp — see §6.4), `amended_by`, `amendment_log[]`

### LineItem
- `id`, `quote_id`, `product_id`/`variant_id`, `qty`, `unit_price`, `customization` (`{logo_size, name_text, artwork_ref}`)
- `line_state` (see §5.2), `procured_qty`, `procured_price`

### Proof
- `id`, `quote_id`, `artwork_version_ref`, `state` (`SENT` | `CHANGES_REQUESTED` | `APPROVED`)
- `approved_by`, `approved_at` — **immutable once APPROVED**; artwork change → new Proof, not edit
- Approved artwork ref **is** the production print file (avoid re-processing — see §7 note)

### PurchaseOrder / Invoice
- `id`, `quote_id`, `po_ref`, `invoice_ref`, `terms`, `payment_state`

### Job  (unit of work on the floor)
- `id`, `quote_id`, `line_items[]`, `track` (`UV` | `3D`)
- `ready_at` (drives queue order), `state` (see §5.4), `artwork_ref`, `product`, `qty`, `print_method`

### Model3D
- `id`, `source` (`THINGIVERSE` | `CULTS3D` | `OWNED`), `source_id`, `license`, `creator_credit`, `file_ref`, `publish_state`

### Filament  (3D inventory)
- `id`, `material`, `color`, `qty_on_hand`, `reorder_threshold`

### SupplierReorder
- `id`, `sku`/`filament_id`, `qty`, `state` (`DRAFT` | `APPROVED` | `ORDERED` | `RECEIVED`), `approved_by`

---

## 4. Product classes → procurement strategy

| Class | Source | Stock | Procurement at order |
|---|---|---|---|
| `CORE` | Wholesale blank-goods, bulk | `stock_on_hand` per variant | Decrement stock; draft bulk reorder if below threshold |
| `SCRAPED_UV` | Shopee/Lazada, admin-gated | Indicative estimate only | Buy per order at marketplace retail; **re-check qty + price** |
| `MODEL_3D` | Thingiverse/Cults3D API + owned | Filament only (no blank) | Print in-house; check filament stock, reorder if low |

Implement procurement as a strategy per class behind one interface (`procure(lineItem) -> ProcurementResult`).

---

## 5. State machines

### 5.1 Quote
```
DRAFT → SENT → (CHANGES_REQUESTED → DRAFT)* → ACCEPTED → PROOFING
      → PROOF_APPROVED → PO_ISSUED → CONFIRMED → PROCURING → READY → CLOSED
CONFIRMED/PROCURING → CANCELLED (allowed)
```

### 5.2 LineItem (during procurement)
```
PENDING → PROCURING →
   OK           → PURCHASED → INBOUND → RECEIVED → READY
   QTY_SHORT    → AWAITING_RECONFIRM → (AMENDED → PROCURING | DROPPED | CANCELLED)
   PRICE_JUMPED → AWAITING_RECONFIRM → (APPROVED → PURCHASED | DROPPED)
```
A Job enters the queue only when **all** line items reach `READY` (or are `DROPPED`). One failed line does not kill the others.

### 5.3 Product publish (scraped + 3D)
```
PENDING → [completeness check] →
   complete + auto-publish ON  → PUBLISHED
   complete + auto-publish OFF → READY_TO_APPROVE → PUBLISHED
   incomplete                  → CANNOT_PUBLISH (reason tags)
PUBLISHED → (enters quote) → FROZEN_SNAPSHOT
PUBLISHED → (drift > threshold | source dead) → CANNOT_PUBLISH (needs_re-review)
```

### 5.4 Job
```
READY → IN_PRODUCTION → SHIPPED → CLOSED
```

---

## 6. Key modules

### 6.1 Public catalogue + designer (no account)
- Full browse, product pages, on-screen designer (two modes: logo upload, name/text personalisation, combinable), live price estimate — all public.
- Capture **production-grade artwork** at designer stage: print-resolution file, placement, size, method. Not just a preview.
- Account/login triggered **only** at Request Quote.

### 6.2 Quote engine
- Draft = blank base cost + margin + customization fee + per-unit print cost + delivery, from pricing config. Bulk logic at/above configured qty threshold.
- Stock-aware lead time per line by class.
- **Admin can amend any field before send**; log every amendment (who/what/when). Enforce a config-driven **margin floor** so an amendment can't price below landed cost.
- v1 may route all quotes through human review (skip auto-send).

### 6.3 Proof + sign-off
- Formal proof distinct from designer preview. Approval recorded immutably (who/what-version/when).
- Any artwork/product change after approval → approval resets (new Proof bound to new artwork version).
- Optional: "re-use previous approval" only if artwork + product byte-identical to a prior approved proof.

### 6.4 Scraped-UV admin gate
- Ingest to own DB (app never reads live from source). Daily re-scrape.
- **Completeness gate fields:** price (manual if scrape failed), dimensions+weight, printable (method + fits max size), stock estimate. Image shown as scraped for v1 (tech debt).
- **Toggles:** global auto-publish; per-item override.
- **Cannot-publish reason tags:** `missing_price`, `missing_dimensions`, `not_printable`, `stock_unreadable`, `source_dead`.
- **Freeze-on-quote:** snapshot price/spec into the quote; background sync never mutates a frozen snapshot.
- **Drift:** price change > **10%** (configurable) → flip to `CANNOT_PUBLISH / needs_re-review`, auto-pull from public. Best-effort; the Stage-5 re-check is the real guarantee.

### 6.5 3D model track
- Pull via **Thingiverse public API** (primary — free; returns name, images, creator, files, licence) and **Cults3D GraphQL API** (secondary — metadata + commercial-use flag).
- **Licence gate reads the API licence field**; only `CC0` / `CC_BY` (commercial-OK on Cults) publish. `CC_BY` stores + displays creator credit. NC/unknown/untagged → blocked. Owned/commissioned models bypass with a rights flag.
- No per-order licence purchase (manual review is slow and would break quoted lead times).
- Filament inventory by material/colour; bulk reorder when low. Print to order → shared queue.
- **Check each source's developer-API terms** (rate limits, attribution, redistribution) — separate from the per-model CC licence.

### 6.6 Production queue (shared, build first)
- Single queue for UV + 3D + (future) B2C. FCFS by `ready_at`.
- Short jobs may slot into gaps of long runs; no customer-type priority.
- Job carries print-ready file, placement, method, qty.
- Honest status stages surfaced to customer: `IN_PRODUCTION → SHIPPED`.

### 6.7 Superadmin dashboard
- Pricing/margins (incl. margin floor), auto-publish toggles, catalogue oversight (approve/pin/remove scraped + 3D items), pay-now-vs-quote cutoff config, reorder thresholds.

---

## 7. Cross-cutting notes

- **Print file reuse:** the approved proof artwork should *be* the production file (correct resolution/placement/method) so the floor prints without re-processing. Avoid a "pretty preview + separate re-processing" split — it reintroduces manual work and risks printing something other than what was signed.
- **Audit trail:** price amendments, proof approvals, and stock re-check outcomes all need who/when logging — this is dispute protection for B2B/PO orders.
- **No marketplace checkout automation.** Procurement of scraped-UV blanks is a human/admin purchase or a contracted-supplier order — never a bot driving a consumer marketplace checkout (ToS violation, account-ban risk, fragile).

---

## 8. Open decisions (resolve with business; mark blocker status)

| Decision | Blocker for build start? |
|---|---|
| Pricing/margins + margin floor numbers | **No** — build dynamic; plug numbers later |
| Proof production owner + SLA | No for build; **yes for launch** (bottleneck risk) |
| Admin-gate + procurement ops owner + SLA | No for build; **yes for launch** |
| Which ~8–15 core blanks; domestic vs imported | Partial — needed to seed catalogue, not to build engine |
| Pay-now vs quote cutoff rule | No — config-driven |
| 3D: owned hero models commissioned? creator memberships? | No for build; affects launch catalogue depth |

---

## 9. Suggested build order (phased)

**Phase 1 — spine (take a real order):**
1. Shared production queue + the two gates (Proof Approved, Ready for Production).
2. Public browse + designer + price estimate (no account).
3. Core blanks with variant trees + "request a specific item" field.
4. Quote → proof + immutable approval → PO/invoice (human-reviewed quote OK).
5. Procurement + per-line-item stock re-check.

**Phase 2 — catalogue breadth (isolated from Phase 1):**
6. Scraped-UV admin gate (ingest → completeness → auto-publish toggle → daily sync/drift).
7. 3D track: API pull + licence gate + filament inventory + print-to-order.

**Deferred (post-v1):** auto-quote pricing engine; demand-intelligence layer; cleared-image pipeline (replace scraped images); B2C "pay now" (Stripe) flow feeding the same queue.

**Sequencing note:** Phase 1 leads with the shared spine and the *simpler* complete path so a real B2B order can flow end-to-end before the fragile scraper/3D pieces are added. Named buyers ordering known/core items are served entirely by Phase 1 — the scraped and 3D tracks in Phase 2 must not be able to block Phase 1 flows.
