# Parked Improvements — revisit AFTER the demo walkthrough

> **Resolved 2026-07-31:** the parked UX items are now shipped on `master` — **P1** (orderability gate), **P2** (buyer next-step card), **P3** (buyer table view), **P4** (sticky buyer action card, PR #20), **P5** (production board aligned column scan, PR #20), **P6** (NinjaVan confirm modal), **P7** (tracker names×qty), **P8** (reorder-rail thumbnails). See `FINDINGS.md` → Fix log Batch 7. *(P5 aligns columns card-based, not a semantic `<table>`; P4 pins the buyer card — staff actions were already top-anchored.)*

**Status:** ⏸️ Parked by owner. MVP frozen until production. Do NOT implement yet. This is the running list of owner-approved directions + real issues to act on once all workflow demos are done.

---

## P1 — Orderability gate: never show a buyer a product they can't check out

**Owner ask (2026-07-28):** "Only display products that can be checked out by a buyer. No-stock / variant issues should not be reflected on the storefront in the first place. Show a clear warning to staff in the product list + product detail page, message clear enough that staff can take the next action."

**Root cause (verified in code):**
- Storefront filter `Product::scopePublished` (`app/Models/Product.php:234-236`) gates on `publish_state = PUBLISHED` **only** — no variant/stock/orderability check.
- The buyer only discovers un-orderability at the last step: `StoreQuoteRequest.php:170-177` rejects a **CORE** product with no variant ("This product cannot be ordered yet - no variants are configured.").
- The publish/completeness gate (`CompletenessGate::reasons`) checks price/dims/printable/stock — **not** "has ≥1 sellable variant."
- Confirmed live: cap/mug/tote = 0 variants (un-orderable), enamel pin = 1 variant (orderable). 3 of 4 published products are dead ends.

**Scope of the variant error:** CORE-only. SCRAPED_UV (incl. blank-recommendation / capture ingest) and MODEL_3D are NOT blocked by the variant check — they order via scraped price / filament, so a blank-recommended product does not hit this specific error. The gate still applies its own price/dims/printable/stock checks to those.

**Proposed direction (post-MVP):**
1. **Define one "orderable" predicate** (single source of truth) per product class:
   - CORE → has ≥1 active variant (and, if stock-tracked, buyable/back-order allowed).
   - SCRAPED_UV → passes completeness gate (price/dims/printable/stock) — already exists.
   - MODEL_3D → has a printable model + filament resolvable.
2. **Storefront:** `scopePublished` (or a new `scopeBuyable`) also requires "orderable." A published-but-not-orderable product is hidden from `/catalogue`, PDP, related, search.
3. **Publish gate:** block publish (or auto-unpublish) when a product is published but not orderable, so it can't reach the storefront in that state.
4. **Staff visibility (the key part of the ask):** in **Product Admin list** and **Product Detail**, show a clear status chip + actionable message when a product is published-but-not-orderable, e.g.:
   - CORE, 0 variants → **"Live but not buyable — add a variant (size/colour/option) before customers can order."** with a link to add a variant.
   - Missing stock/price → name the exact missing field + the fix action.
5. **Buyer-side safety net:** keep the final-step 422, but it should now be unreachable for a normal browse (defence in depth).

**Acceptance idea:** a published product with no orderable path never appears in `/catalogue`; staff see exactly why + the next action on both the list and the detail page; no buyer reaches Place-order on an un-orderable line.

**Live-verified nuance (2026-07-28):** the Product **detail** page already shows *"No variants — not orderable"* under Variants & stock (good). But (a) the product **list** shows no such flag — cap/mug/tote read as plain "Published" like the sellable pin, even showing "100/250/300 sold"; and (b) the item is still **"Published" and live on the storefront** despite "not orderable" — publish state and orderability are disconnected. So P1 = surface the flag in the **list**, and make **publish require orderability** (or hide non-orderable from the storefront). The detail-page hint is a good base to build on.

---

## Real issues hit during live testing (to revisit with P1)

Full detail + `file:line` in [`FINDINGS.md`](FINDINGS.md) → "Live-test findings" (LT1–LT9) and the ranked register above it. Highlights to bundle with the orderability work:

- **LT1/LT2** — un-orderable products on storefront (this P1).
- **LT3** — "Out of stock" wording on a made-to-order shop; really means "not set up to sell." Reword / hide.
- **LT4** — stale login state on checkout (says "Sign in" after signing in until reload).
- **LT5** — "Company default" address option shown when no default exists; doesn't autofill or warn.
- **LT6** — footer "About"/"Help" links dead (point to current page); footer shows "Log in" while logged in.
- **LT7** — fake seeded reviews on every product (trust/legal risk at launch).
- **LT8** — duplicate-key render warning on product page (can duplicate/omit list items).
- **LT9** — raw "CSRF token mismatch." can leak to buyers if site domain ≠ API domain in prod. Verify domains line up at go-live.

Plus the ranked code findings (H1–H6, M1–M22, L1–L28) already in `FINDINGS.md` — all frozen until the freeze lifts.

---

---

## UX / Layout improvements (owner-requested during 2026-07-28 walkthrough)

Design/UX changes the owner flagged while watching the live demo. Parked, not implemented. Cross-referenced to existing findings so nothing is duplicated.

### P2 — Buyer "what's next" direction after key emails/actions
**Ask:** buyer has no clear direction after getting the accept-price email or after approving a proof.
**Recommendation:** a prominent **"Next step"** action card at the top of the buyer order page for every buyer-actionable state — Sent → *Accept quote*; Artwork-approved → *Accept pricing*; Proofing → *Review proof*; Proof-approved → *"We're preparing your invoice"* (informational). Landing page after the email deep-link should match.
**Dedup:** subsumes the buyer-facing half of **M5/M6** (dashboard "Awaiting you" omits ARTWORK_APPROVED + PROOF_APPROVED) and pairs with **L1** (buyer sees internal state jargon). Implement together; don't double-count.

### P3 — Buyer account → table view (dashboard + My Orders)
**Ask:** buyer account page → table view with proper spacing. **Scope (confirmed):** both the account dashboard landing (recent orders / awaiting-you) **and** the My Orders list.
**Recommendation:** responsive table — Order · Date · Items · Total · Status · Action — row-click to open; collapse to stacked cards on mobile. Keep the "Awaiting you" items visually distinct at the top.
**Dedup:** new (layout). Related to P2 (awaiting-you surfacing).

### P4 — Staff + buyer primary actions pinned to top of order detail
**Ask:** move staff actions to the top instead of scrolling to the bottom. **Scope (confirmed):** **both** staff and buyer primary actions.
**Recommendation:** a **sticky action bar** at the top of the order detail holding the primary action + cancel, always visible on scroll. Note: staff primary actions already render near the top; the buyer's Accept/Approve buttons currently render **below the items** — pin both.
**Dedup:** new (layout). Complements P2.

### P5 — Production queue → table view
**Ask:** staff production list → table with proper spacing instead of cards in a row.
**Recommendation:** dense table — Job · Order · Track (UV/3D) · Qty · Ready-at · Status · Actions — keep the scan-to-advance box and a batch-select column; drop the wide cards.
**Dedup:** new. Note **LT13** (order page lacks shipment visibility) is separate — the table doesn't fix that; the order page still needs shipment status.

### P6 — Confirmation popup when pushing to NinjaVan
**Ask:** change "Create NinjaVan shipment" to a popup.
**Recommendation:** confirm modal showing ship-to + carrier + pickup window + weight, with Confirm/Cancel, before the billable booking.
**Dedup:** **also resolves L23** (create-shipment button can't tell an address is ready — validate inside the modal). Fold L23 into this; don't file twice.

### P7 — More product info on the tracking page
**Ask:** put more product information on the tracking page. **Scope (confirmed):** **product names + quantity only** — no images, no pricing, no buyer PII (the tracker is publicly reachable via code+email or a signed link).
**Recommendation:** add a per-line "product name × qty" list to the tracker payload + UI. Keep the payload PII-free (do not add images/prices).
**Dedup:** new. Respects the existing anti-enumeration / PII-free design (L17 note).

### P8 — Rework the "Reorder from a past quote" rail
**Ask:** show product images etc. instead of just the order number.
**Recommendation:** cards with product thumbnails (stacked for multi-line orders), product names + qty, total, date, plus Reorder + View buttons.
**Dedup:** distinct from the reorder **logic** findings **M22 / L21 / L22** (deleted-variant silently dropped / no availability check / no idempotency) — P8 is presentation only; keep both.

---

*Appended as later workflows surface more. Nothing here is implemented.*
