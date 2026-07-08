# Pass 2 Discovery Audit - 2026-07-04

Adversarial free-hunt outside the Pass 1 checklist (IDs A1–E4). No checklist
finding repeated. Attacked live against the running stack (API :8000, SPA :5173,
MySQL) as a hostile buyer, a careless uploader, and a malicious/compromised staff
actor. Each finding is convertible to a new checklist ID for future runs.

Format: description | repro | severity | suggested checklist ID.

---

## What held up (attempted, no finding)

Recorded so future runs don't re-spend effort:

- **Mass-assignment** - POST `/quotes` with `state:READY, total:1` → ignored; `Quote::create` uses explicit fields, state forced DRAFT, total recomputed. Safe.
- **Cross-company quote** - buyer (company 1) POST with `company_id:2` → 422 (validator + tenancy). Safe.
- **IDOR proof** - buyer POST `/proofs/1/decide` (not theirs) → 404 (route-model scoping). Safe.
- **Quote a blocked/unpublished product** - POST `/quotes` product_id of a CANNOT_PUBLISH 3D item → 422; **price-estimate of the same blocked item → 422 too** (no pricing leak). Licence/publish gate holds on both paths.
- **Bulk stock oversell** - CORE procurement uses `lockForUpdate` on the variant row; concurrent decrement is serialized.

---

## Findings

### F1 - Logo-size surcharge bypass via unvalidated `logo_size`
**Description.** The quote endpoint validates `customization.logo_size` only as `string|max:20` (`StoreQuoteRequest.php:40`) - not `in:S,M,L` (the estimate endpoint *is* restricted, `PriceEstimateRequest.php:31`). `PricingService::quoteTotals` looks the band up with `$bySize[$size] ?? 0` (`PricingService.php:103`), so any unknown value silently yields a **zero** size surcharge while the line still counts as customized (flat fee + setup still charged, so it doesn't look free).
**Repro.** As buyer, POST `/api/quotes` two identical lines (product 448, qty 100), one `logo_size:"L"`, one `logo_size:"XL"`. Live result: L subtotal **4308.00**, XL subtotal **4218.00** - exactly SGD 90 less (0.90/unit × 100 dodged). Scales with qty; at the 100000 max that's SGD 90,000 per line. (Note: `"L "` with trailing space is trimmed back to `L`, so only fully-unknown tokens work - and they do.)
**Severity.** major (client-controlled underpricing, silent).
**Suggested checklist ID.** D11 - "Quote `logo_size` is server-validated to the exact tier set; an out-of-set value is rejected, not silently priced at zero surcharge."

### F2 - `artwork_ref` accepts arbitrary/unvalidated storage paths (traversal + cross-tenant reference)
**Description.** `customization.artwork_ref` is validated only as `string|max:2048` and stored verbatim on the line, then flows unchanged into the proof and production job (the ref *is* the print file, by design). It is never checked to be (a) a path the requesting user actually uploaded, (b) inside the artwork/ prefix, or (c) free of traversal. A buyer can point their line's FA at any storage path - a traversal string, or another tenant's artwork ref harvested elsewhere.
**Repro.** As buyer, POST `/api/quotes` with `customization.artwork_ref:"../../../../etc/passwd"` → **201**, stored as `..\/..\/..\/..\/etc\/passwd` on line 11. When staff builds the proof/production file from that ref, the path is resolved server-side.
**Severity.** major (path traversal into the FA/print pipeline; cross-tenant file reference; the exact file the floor prints is attacker-chosen).
**Suggested checklist ID.** C15 - "`artwork_ref` on a quote line must resolve to an upload owned by the requester, under the artwork/ prefix, with no traversal; foreign/invalid refs are rejected."

### F3 - Quote line accepts a `variant_id` from a different product (no product↔variant linkage check)
**Description.** `StoreQuoteRequest` validates `variant_id` `exists:variants,id` but never that the variant belongs to the line's `product_id`. `QuoteService::create` resolves the variant by id alone (`QuoteService.php:75`) and `PricingService::landedCost` adds `variant->price_delta` to the *product's* base cost. Two consequences: (1) landed cost is computed from a foreign variant's delta - a negative-delta variant on any product would directly underprice the line; (2) `CoreProcurement` decrements *that foreign variant's* `stock_on_hand` (`CoreProcurement.php:37-46`), so fulfilling product A silently draws down product B's inventory, and the frozen snapshot records the wrong variant.
**Repro.** As buyer, POST `/api/quotes` line `{product_id:448, variant_id:9}` (variant 9 belongs to product 5) → **201** (quote 10, line 9). Seeded deltas are 0/positive so no price delta today, but the linkage is unchecked and procurement targets the wrong stock.
**Severity.** major (inventory integrity; latent underpricing if any negative-delta variant exists).
**Suggested checklist ID.** B9 - "A quote line's `variant_id` must belong to its `product_id`; a foreign or mismatched variant is rejected before pricing/procurement."

### F4 - Margin floor not enforced on the reconfirmation amend path
**Description.** The pre-send amend enforces the config margin floor (`AmendQuoteRequest.php:64` → `PricingService::isAboveMarginFloor`). The reconfirmation amend - the path used exactly when a line PRICE_JUMPED or went QTY_SHORT - sets `unit_price` directly with no floor check (`QuoteService::reconfirmLine` :382-388), and its request allows `unit_price` `min:0` (`ReconfirmLineItemRequest.php:29`). So the one place a re-quote actually happens has no floor.
**Repro.** Drove quote 13 line 12 to AWAITING_RECONFIRM (qty 10000 vs 450 on hand → QTY_SHORT), then staff POST `/api/line-items/12/reconfirm` `{action:"amend", qty:400, unit_price:0.01}` → **200**, line READY at unit_price 0.01 (landed cost 30, 12% floor ≈ 33.60). Accepted, job created.
**Severity.** major (financial control the spec calls out as a safeguard is bypassable on the highest-risk path; a fat-finger or a compromised ops account prices below cost).
**Suggested checklist ID.** D12 - "Reconfirmation amend enforces the same margin floor as pre-send amend; a unit price below landed-cost+floor is rejected."

### F5 - Quote/PO total not recomputed after a reconfirmation amend (invoice detaches from reality)
**Description.** `reconfirmLine` mutates a line's qty and unit_price but never re-totals the quote, and the PurchaseOrder amount is frozen at issue time. After a qty-short amend that drops 10000→400 units, the quote/PO total stays at the pre-amend figure while the line total collapses. The buyer is invoiced for the original order; the floor produces the reduced one.
**Repro.** Same run as F4: after amending line 12 to qty 400 @ 0.01 (line_total **4.00**), quote 13 still reports `subtotal:422533.00 / total:422593.00`, and PO-13 amount was locked at 422593 at issue. Delivered goods = 400 units; invoice = 10000-unit price. (Direction is symmetric - an amend *upward* would under-invoice.)
**Severity.** blocker (B2B dispute-protection is the whole point of the PO/immutable-proof design; here the authoritative money figure silently diverges from what is produced and shipped).
**Suggested checklist ID.** A11 - "After any line reconfirmation (amend/drop), the quote subtotal/total and the linked PO/invoice amount are recomputed (or an explicit re-quote+re-approval is required) so the invoiced amount matches the fulfilled lines."

### F6 - No idempotency on quote submission (duplicate orders)
**Description.** `POST /quotes` has no idempotency key or in-flight guard; the checkout "Place order" button isn't hard-disabled on submit. A double-click or a retry-on-slow-network creates two identical draft quotes.
**Repro.** Rapid repeated `POST /quotes` with the same cart each returns a fresh 201 (quotes 7/8/9 created back-to-back during testing). On the SPA, the earlier double-click path created duplicate drafts before the redirect landed.
**Severity.** minor (duplicate drafts are staff-recoverable, but they pollute the pipeline and risk a double PO if both progress).
**Suggested checklist ID.** A12 - "Submitting the same cart twice (double-click / retry) does not create duplicate quotes - idempotency key or in-flight lock enforced."

---

## Coverage note

6 findings, none overlapping Pass 1 (A1–E4). Priority areas from the prompt:
price manipulation via client-side state - F1, F3 (hit); licence-gate bypass -
attempted, held; quote/PO integrity - F4, F5 (hit, severe); designer/upload
edge - F2 (hit), F6; mobile-only breakage - none new beyond the C-row
touch-target and safe-zone items already in Pass 1.

*Test-data note: this run created draft quotes 7–13 and decremented variant 22
stock in the dev DB; reseed to reset.*
