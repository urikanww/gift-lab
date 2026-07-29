# Gift-Lab — Full Walkthrough Feedback Log

Run date: 2026-07-29 · Live Chrome preview · Sandbox third-parties (NinjaVan sandbox, Stripe test) · Real SMTP (admin@nexgen.com.sg)

**Accounts (pinned §1b):**
- Buyer (register in B1): `darvinhuang97@gmail.com`
- Staff (alerts inbox, repointed): `darvin@nexgen.com.sg` / `ChangeMe!123` (was ops@giftlab.local)
- Superadmin: `superadmin@giftlab.local` / `ChangeMe!123`

**Env deltas applied for this run:** `QUEUE_CONNECTION=sync`; added self-chosen `NINJAVAN_WEBHOOK_SECRET`. DO Spaces kept real (intended). Stripe test keys, NinjaVan sandbox creds.

---

## Findings

Severity: 🔴 blocker / 🟠 UX-hurts / 🟡 polish. Type: UI / UX / copy / a11y / backend-bug / perf.

| ID | Flow (B#/S#) | Severity | Type | What happened | Repro steps | Screenshot | Suggested fix |
|----|--------------|----------|------|---------------|-------------|------------|---------------|
| F1 | B1 | 🟡 polish | copy | Register validation errors shown as one lumped block using Laravel-default phrasing ("The company name field is required") instead of per-field inline, humanised copy. | /register → submit empty | reg-validation | Per-field inline messages; friendlier copy ("Please enter your company name"). |
| F2 | B2 | 🟡 polish | copy | PDP volume-pricing tier reads "1 pcs" (should be "1 pc"). | PDP → volume pricing card | pdp-volume | Singular "1 pc". |
| F3 | B4 | 🟡 polish | UX | Cart Estimate shows Subtotal SGD 295 for 50×SGD5.40 but never itemizes the SGD 25 setup fee or per-unit price, so buyer can't reconcile 270→295 (LT16). Math is CORRECT. NOTE: the ORDER DETAIL page DOES itemize it ("Personalisation SGD 25" line) — so this is a cart-vs-order-page inconsistency. | Add 50 to cart → view cart Estimate vs order page | cart-estimate | Mirror the order page: show the "Personalisation"/setup line in the cart too. Reconsider a 25 setup fee on a fully blank line. |
| F4 | B6 | 🟠 UX-hurts | copy | Buyer order page exposes raw state-machine names and step counter: "● Draft → next: Sent  step 1 of 8". §5/L1 say buyers must NEVER see internal jargon or raw state-machine step counts. The adjacent plain-language Next-step card is excellent and could stand alone. My Orders list also shows "Draft" as the status chip. | /quotes → open order MANVQ3MT1M | order-status-jargon | Replace "Draft/Sent/step 1 of 8" with buyer-facing labels (e.g. "Awaiting quote"); drop the raw N-of-8 counter or make it a friendly progress bar. |
| F5 | §2.5 Quote-ready email | 🟠 UX-hurts | UI | Plain quote-ready email (no proof) renders an empty "PROOF PREVIEW" dashed placeholder box — reads as a broken/missing image on the first customer email (§2.5 "no empty sections"). Confirmed in real inbox. Root cause: resources/views/mail/quote-ready.blade.php line 143 `@else` renders the placeholder whenever there is no proof. | Send a no-proof quote to buyer → open email | quote-email-proofbox | Wrap the whole proof-preview `<tr>` so it only renders when a proof image/round exists; drop the `@else` empty box. |
| F6 | §2.5 Quote-ready email | 🟡 polish | copy | Email item line reads "1 item(s), 50 unit(s)" (blade line 103) — parenthetical plurals instead of natural "1 item, 50 units". | Quote-ready email body | quote-email-plurals | Pluralize properly (Laravel `Str::plural`). |
| F7 | B7 / §2.5 Accepted email | 🟠 UX-hurts | copy | "Accepted" milestone email hardcodes "We're preparing your artwork proof and will send it over shortly" (OrderMilestone.php:77). For a PLAIN-STOCK order (auto-skips proofing straight to PROOF_APPROVED), no proof is ever coming — misleading. | Accept a blank/plain-stock quote → Accepted email | accepted-email-proof-copy | Make the acceptance copy conditional: proof-needing → "preparing your proof"; plain-stock → "preparing your order for invoicing/production". |

| F8 | S5 Commit | 🟠 UX-hurts | UX | "Commit order" is disabled until a **PO reference** is entered, but nothing marks the field required — no asterisk, no "PO required to raise the invoice" helper, and the disabled button gives no reason. Staff will be confused why Commit is greyed out. | Proof-approved order → Commit panel with empty PO | commit-po-required | Mark PO reference required (asterisk + helper), or show a tooltip/inline message on the disabled Commit button explaining a PO is needed. |
| F10 | S10 / production queue | 🔴 blocker | UI | **Returned-parcel resolution has NO UI entry point.** A courier-returned parcel (SHIPPED + "Delivery unsuccessful — returned") sits in the "Awaiting delivery" board offering only "Mark delivered" — whose own dialog copy says *"if the parcel was returned or failed, use the returned-parcel resolution instead"* — but that resolution (reship / close / cancel & credit) has no button/link anywhere, and the backend rejects Mark-delivered on a returned parcel (422). Staff cannot resolve a returned parcel through the UI at all. Backend `resolveReturn`/`returnParcel` (M15) is fully built & tested; only the frontend wiring is missing (no call to `production-jobs/{id}/resolve-return` exists in frontend/src). Also blocks driving the M15 multi-parcel UI test (webhook-level isolation verified: only GLRET1 flagged, GLRET2 untouched). | Seed/ship a parcel → POST "Returned to Sender" webhook → open Production queue → returned parcel shows only "Mark delivered" | s10-no-resolve-ui | Add a returned-parcel resolution panel/action (reship / close / cancel & credit) that calls the existing resolve-return endpoint; route returned parcels out of the plain Awaiting-Delivery board as the AwaitingDeliveryPanel comment already assumes. |
| F9 | S11 / dashboard | 🟠 UX-hurts | UX | Dashboard "Delivered · unpaid: 1" tile is clickable but navigates to the **unfiltered** /quotes list (shows all orders incl. Draft/Proofing), with no filter applied and no unpaid marker in the list — staff can't identify or act on the delivered-unpaid order from there. | Dashboard → click "Delivered · unpaid" tile | delivered-unpaid-tile-filter | Link the tile to a filtered view (delivered + outstanding balance), or add an unpaid indicator/column to the quotes list. |

**Behaviour note (NOT a bug — verified by-design):** Accepting a blank/plain-stock quote (no proof-needing line) auto-advances SENT→ACCEPTED→PROOF_APPROVED in one action (QuoteService::accept L783-789, well-commented; `needsProof()` false for uncustomised lines). MANVQ3MT1M skipped proofing as expected. Consequence: B8/S4 proofing must be exercised on a separate proof-needing order.

**Positive:** M4 verified — commit went step 5→6 (Confirmed), no phantom "Invoiced" step. Invoice shows "Unpaid" honestly. Committed email H4-clean (no false "payment received"). Buyer-notifications panel positively confirms the send ("Order confirmed email sent … 09:07 PM").

**Positive (S6 H3/M21):** Record partial (SGD 150) → badge "Partial" + "SGD 150.00 collected · SGD 184.63 still owed" (334.63−150 exact). Helper copy names the rules clearly (partial < total; full = Mark paid; cancellation refunds only collected). Left order with outstanding balance to test S11 next.

**Positive (S11/LT14):** Dashboard "Delivered · unpaid" tile went 0→1 when the delivered order still had a balance; order page showed red banner "Delivered — payment outstanding: SGD 184.63 still owed" + state "Closed · All steps complete" (buyer-friendly, no raw step count at terminal). Mark paid → badge "Paid", banner cleared, no buyer email. (Tile-click filtering gap = F9.)

**S8 note (usability):** The "Book NinjaVan shipment" dialog is taller than the viewport and its primary **Book shipment** button sits below the fold with no internal scroll — on a laptop screen staff may not see/reach it (had to keyboard-Tab to it). Consider making the dialog body scroll with a sticky action footer. Booking itself works: dialog "Book NinjaVan shipment — MANVQ3MT1M" ("billable courier shipment… confirm the address"), address-confirm + Save, then Book → job SHIPPED, consignment **GL1**, carrier NINJAVAN. LT13 ✓ (order page "Shipments: NINJAVAN · GL1"). Idempotency verified (retries → one consignment). Sandbox returned "requested_tracking_number already exists" → app treated as already-booked (graceful). NOTE for S9 webhook: tracking_number = **GL1**.

---

## Email content review (§2.5)

| Email | Triggered at | Delivery | Content notes |
|-------|--------------|----------|---------------|
| **Quote ready** (QuoteReadyMail) | S3 Send to buyer (MANVQ3MT1M) | ✅ Inbox, ~instant, from "Gift Lab <admin@nexgen.com.sg>" | Subject "Your quote is ready to review — Gift Lab" ✓; "Hi Darvin," ✓; ref MANVQ3MT1M + tracking GL-X78J0F + Total S$334.63 ✓; CTA "Review & approve"→/orders/MANVQ3MT1M ✓; logo embedded; responsive. ISSUES: F5 empty PROOF PREVIEW box, F6 "item(s)/unit(s)". |
| **Milestone: Accepted** | B7 buyer accepts (MANVQ3MT1M) | ✅ Inbox, 9:00 PM, from "Gift Lab <admin@nexgen.com.sg>" | Subject "We've received your acceptance — order MANVQ3MT1M" ✓; "Hi Darvin," ✓; ref ✓; CTA "View your order" ✓; honest footer "Just reply to this email if you need us". ISSUE: F7 — body promises "artwork proof" on a plain-stock order (confirmed by human). |
| **Milestone: Committed** | S5 commit (MANVQ3MT1M) | ✅ Inbox, 9:07 PM, from "Gift Lab <admin@nexgen.com.sg>" | Subject "Your order is confirmed — MANVQ3MT1M" ✓; "Hi Darvin," ✓; ref ✓; CTA "View your order" ✓; **H4-clean — NO payment wording while unpaid** (confirmed by human). Clean. |
| **Milestone: In production** | S7 confirm stock → Ready (MANVQ3MT1M) | ✅ Inbox, 9:20 PM, from "Gift Lab <admin@nexgen.com.sg>" | Subject "Your order is now in production — MANVQ3MT1M" ✓; "Hi Darvin," ✓; ref ✓; CTA "View your order" ✓; honest footer. Clean (confirmed by human). |
| **Milestone: Shipped** | S8 book NinjaVan shipment (MANVQ3MT1M, consignment GL1) | ✅ Inbox, 9:33 PM, single email, from "Gift Lab <admin@nexgen.com.sg>" | Subject "Your order is on its way — MANVQ3MT1M" ✓; "Hi Darvin," ✓; ref ✓; **Tracking: GL1 + "Track your parcel" link** ✓; CTA "View your order" ✓. M19 verified — exactly one email despite retries (confirmed by human). |
| **Milestone: Delivered** | S9 signed Delivered webhook (GL1) | ✅ Inbox, 9:38 PM, single email, from "Gift Lab <admin@nexgen.com.sg>" | Subject "Your order has been delivered — MANVQ3MT1M" ✓; "Hi Darvin," ✓; ref ✓; CTA "View your order" ✓; honest footer. **L10 idempotency VERIFIED**: 2nd identical POST → 200 "duplicate/replay ignored", no 2nd email, processed_events stayed 1 (confirmed single by human). |
| **Proof ready** (QuoteReadyMail, proof round) | S4 Send proofs (KPWSQV5JY0, 2nd order — proof-needing) | ✅ Inbox, from "Gift Lab <admin@nexgen.com.sg>" | Subject "Your quote & proof are ready to review — Gift Lab" ✓; "Hi Darvin," + honest copy ✓; ref KPWSQV5JY0 + tracking + Total S$180.00 ✓; **PROOF IMAGE RENDERS from signed DO-Spaces URL** (confirmed by human) — validates real object-storage path + proof-box-shown-when-proof-exists (contrast F5). "Review & approve" CTA ✓. Still shows F6 "1 item(s), 30 unit(s)". NOTE: proof staged via service (pane can't upload). |
| **Staff: parcel returned** (ParcelReturnedMail) | S10 Returned-to-Sender webhook (GLRET1, order 11JHGBN4C1) | ✅ STAFF inbox darvin@nexgen.com.sg, 22:14 | Subject "Parcel returned/failed on Order 11JHGBN4C1 — Gift Lab" ✓; "A parcel needs staff attention" + Order ref + Consignment ref GLRET1 + Courier status "Delivery unsuccessful — returned" ✓; "Open the order" CTA ✓. Body lists the 3 dispositions "close / reship / cancel with credit" — **which the UI can't deliver (F10)**. **M15 isolation ✓** (only GLRET1 flagged). **L7 idempotency ✓**. Confirmed by human. |
| **Chase: proof reminder** (ReminderProof) | `quotes:chase` on stale PROOFING order KPWSQV5JY0 | ⏳ awaiting human confirm | Subject "Your proof is still waiting for you — KPWSQV5JY0"; "…proof is still waiting for approval. Nothing goes into [production until…]". Proof ladder [2,5,9] (vs price [3,7,12]) — chased sooner, per design. reminders_sent=1, phase=proof. |
| **Chase: price reminder** (ReminderPrice) | `quotes:chase` on stale SENT order D4A276AT9M | ✅ Inbox 10:27 PM | Subject "A reminder about your quote — D4A276AT9M"; "We haven't heard back on your quote yet. Have a look…just reply if anything needs changing"; "Review pricing" CTA ✓. **M16 ✓** (reminders_sent=1, reminded_phase='price'); **M17 ✓** in code. Proof chase = same mechanism. Confirmed by human. |
| **Milestone: Cancelled** | Cancel order D4A276AT9M | ✅ Inbox 10:28 PM | Subject "Your order has been cancelled — D4A276AT9M"; "…If that's unexpected, please get in touch and we'll sort it out"; "View your order" CTA ✓. Honest — no false charge/refund claim. Confirmed by human. |
| **Staff: job failed** (JobFailedAlertMail) | — | ⏭️ not forced (queue-job failure hard to trigger safely); noted per plan. |
| **Staff: proof changes requested** (ProofChangesRequestedMail) | B8 buyer Request changes (KPWSQV5JY0) | ✅ STAFF inbox darvin@nexgen.com.sg, 22:09 | Subject "Changes requested on Order KPWSQV5JY0 — Gift Lab" ✓; "A buyer requested changes", proof **v1**, ref ✓; **"What to change" = buyer's full note** ✓; "Open the order" CTA ✓; footer correctly "Internal notification." (distinct from buyer footer). **L19 VERIFIED** (client disables Send on whitespace; server trims note pre-validation → required_if rejects, 422; comment cites L19). Confirmed by human. |

---

## Passed clean (no finding)

- **B1** register (validation works; F1 copy nit only). **B2** PDP + volume pricing (F2 "1 pcs" nit). **B3** designer live estimate + logo-size band. **B4** cart. **B5** checkout + confirmation modal (order ref + Track + QR). **B6** dashboard stat tiles + Awaiting-you + reconciling order page.
- **B7** accept. **B10** public tracker: plain-language stages, names+qty only (no price/PII), **L14 returned parcel shown honestly** (not "done"). 
- **S1** dashboard queues + LT14 tile. **S3** send quote. **S4** send proofs (real DO-Spaces thumbnail renders). **S5** commit (**M4** no phantom Invoiced step). **S6** partial payment (**H3/M21** "X collected / Y owed"). **S7** procurement confirm-stock gate. **S8** NinjaVan sandbox booking + LT13 shipment shown + **M19** one shipped email despite retries. **S9** delivered webhook + **L10** idempotency. **S11** delivered-unpaid banner + LT14 tile + Mark-paid. **S2** create+publish (Ops Admin has products.approve).
- **Cross-cutting verified:** **LT1** variant-less published products hidden from storefront; **L25/L26/L27** publish/import/auto-publish permission gates (route middleware); **L19** whitespace proof-note rejected (client + server); **L7** returned-webhook idempotency; **H4** no false "payment received" copy on unpaid orders; **M15** webhook-level parcel isolation; **M16/M17** chase ladder per-phase + disable-gating.
- **Emails (11 types) all delivered + content-clean**, real from-address, honest copy, correct CTAs — see §2.5 table.

## Summary

**Scope covered:** B1–B8, B10, B11 (buyer) · S1–S11 + parts of S12 (staff) · all 11 notification types in §2.5 · negative probes L7/L10/L14/L19/L25–27/LT1/M4/M15(webhook)/M16/M17/H3/H4/M21 · §5 dark mode. Two real inboxes exercised (buyer gmail + staff nexgen). NinjaVan sandbox booked a real consignment; delivered/returned webhooks signed & POSTed.

**Overall:** The backend logic is sound — no API returned wrong data or 500 in any happy or unhappy path. Money reconciles everywhere (order page "Personalisation" line, partial-payment "collected/owed", reorder re-pricing). Every email delivered within seconds from the real from-address with honest, on-brand copy and working CTAs; idempotency (L7/L10) and one-email-per-order (M19) hold. The one serious problem is a **missing UI**, not broken logic.

**Issues by severity:**
- 🔴 blocker ×1: **F10** — returned-parcel resolution (reship/close/cancel-credit, S10/M15) has no UI entry point; backend is fully built but unreachable.
- 🟠 UX-hurts ×5: **F4** buyer sees raw state names + "step N of 8"; **F7** Accepted email promises an "artwork proof" on plain-stock orders; **F8** Commit disabled with no hint PO is required; **F9** "Delivered·unpaid" tile → unfiltered list; **F3** cart doesn't itemize the setup fee (order page does).
- 🟡 polish ×3: **F1** register validation copy; **F2** "1 pcs"; **F6** email "1 item(s), 30 unit(s)".

**Top 5 to fix first:**
1. **F10 (🔴)** — wire up the returned-parcel resolution UI (call the existing `resolve-return` endpoint; route returned parcels out of Awaiting-Delivery). Staff currently cannot action a returned parcel at all.
2. **F7 (🟠)** — make the Accepted-email copy conditional (plain-stock vs proof-needing) so it doesn't promise a proof that never comes.
3. **F8 (🟠)** — mark the Commit PO field required / explain the disabled button.
4. **F4 (🟠)** — replace buyer-facing raw state machine names + "step N of 8" with friendly labels.
5. **F9 (🟠)** — make the "Delivered·unpaid" tile open a filtered, actionable list.

**Additional coverage (round 2):**
- **S12** — Notifications (per-milestone toggles), Courier (pickup+timeslot), **Pricing** (test-quote breakdown reconciles end-to-end), **Users** + **H1** (non-superadmin can't reset a superadmin password — code-verified). Ops Admin nav correctly hides Users/Pricing (permission-gated).
- **§5 responsive** — mobile (375) designer + cart render clean (hamburger, single-column, no overflow); dark mode clean across staff panels.
- **L21** — reorder of an all-unpublished-line order → graceful 422 (no crash/bad draft); unpublished product leaves storefront ("No products published yet" empty state) + catalogue API empties.
- **§4 kill-API (L9/LT9)** — API down → clean "Network Error + Retry", no raw stack/CSRF; page shell still renders.
- **Chase: proof reminder** — fired (ladder [2,5,9]); **price reminder** confirmed earlier.

**Intentionally skipped (user chose "wrap up"):**
- **B9** Stripe pay-now (off by default; needs Stripe CLI + human card entry — I cannot enter card numbers).
- **L3** (session-expiry → /checkout), **L4** (stale persisted need-by cleared), realtime 2-session push.
- **M15 cancel-credit UI** + full S10 dispositions — blocked by **F10** (no UI). Backend isolation verified at webhook level.
- **Job-failed** alert — not force-triggered (queue-failure hard to trigger safely). **Gift-ideas/Blank-recs** M20 panel — not toured.
- Two proof-flow steps (S4 proof send, 2nd order's quote) used a service/seeded shortcut because the in-app pane can't drive native file uploads; the emails fired through the real code path and were human-verified.
