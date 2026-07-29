# Gift-Lab — Full Flow Audit & Live-Test Plan

**Date:** 2026-07-28 · **Basis:** current code only, cited `file:line`. Stale `docs/*.md` not trusted.

## What's here

| File | Purpose |
|------|---------|
| [`FINDINGS.md`](FINDINGS.md) | **The one consolidated wrong-flow register.** 6 High / 22 Med / 28 Low, ranked. ⚠️ Freeze — do NOT fix until told. |
| `workflows/wf-01 … wf-10` | One file per end-to-end scenario: actors, stage-by-stage flow (start→finish), mermaid diagram, findings it touches, and a **real-user test prompt**. |

## The 10 workflows (test order)

| # | Workflow | Primary actor | Live-testable in browser? |
|---|----------|---------------|---------------------------|
| 01 | [Buyer purchase](workflows/wf-01-buyer-purchase.md) — browse → customize → cart → checkout → register → order placed | Anonymous → Buyer | ✅ Full |
| 02 | [Order tracking](workflows/wf-02-order-tracking.md) — public `/track` + signed one-click view | Anyone | ✅ Full |
| 03 | [Quote → proof → commit](workflows/wf-03-quote-proof-commit.md) — staff build/send, buyer accept, proof round, approve, issue invoice | Staff + Buyer | ✅ Full |
| 04 | [Procurement](workflows/wf-04-procurement.md) — confirm → procure → reconfirm desk → confirm-stock → jobs built | Staff | ✅ Full |
| 05 | [Production & shipment](workflows/wf-05-production-shipment.md) — queue → advance → create shipment → delivered → closed | Staff | ⚠️ Partial (courier webhook simulated) |
| 06 | [Reorder](workflows/wf-06-reorder.md) — clone a past order → fresh DRAFT re-priced today | Buyer | ✅ Full |
| 07 | [Payment](workflows/wf-07-payment.md) — B2C pay-now / B2B reconcile / cancel + credit note | Buyer + Staff | ⚠️ Partial (Stripe hosted page external) |
| 08 | [Catalogue gate](workflows/wf-08-catalogue-gate.md) — capture URL → resolve blockers → publish → storefront | Staff | ✅ Full |
| 09 | [Admin ops](workflows/wf-09-admin-ops.md) — product / user / pricing / courier / notification config | Staff / Superadmin | ✅ Full |
| 10 | [Unhappy paths](workflows/wf-10-unhappy-paths.md) — changes-requested loop, awaiting-reconfirm, returns, cancellation | Staff + Buyer | ⚠️ Partial |

## Environment (verified live 2026-07-28)

- API `php artisan serve` :8000 · Frontend Vite :5173 · MySQL `giftlab` migrated + seeded · `APP_ENV=local`.
- **Seeded logins:** `superadmin@giftlab.local` / `ChangeMe!123` · `ops@giftlab.local` / `ChangeMe!123` (staff_admin) · `demo-buyer@example.test` / `password` (buyer, company 3).
- **Seeded orders:** `AVYBQQVZCX` PROOFING · `NJRK6KN9YX` ARTWORK_APPROVED · `BY4W6Q3CMN` CLOSED.
- **Products:** baseball-cap, ceramic-mug, tote-bag, demo-enamel-pin-upload-a-look (all CORE, PUBLISHED).

## Test protocol

One workflow at a time, driven live in the Claude Browser pane. After each: screenshots + a UX-issue list for you to flag. **We do not advance to the next workflow until you sign off the current one.** No code is changed during testing — observation only.
