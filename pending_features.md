# Pending Features

Deferred from the 2026-07-10 checkout/tracking + staff-efficiency planning round.
Not scheduled for the current build — parked here with enough context to pick up later.

---

## #6 — Self-serve reorder from the tracker

**Idea:** Let a buyer re-order a delivered order straight from the public tracking
page (or their quote list), producing a new quote pre-filled with the same line
items, customizations, and quantities. Repeat sales with zero staff touch.

**Why pending:** Reorder machinery already exists on the *supplier* side
(`AdminReorderController`, `App\Enums\ReorderState`, `SupplierReorder`), but a
*buyer-facing* reorder is a different flow — it clones a quote, must re-price
against current `PricingConfig`, re-validate stock/`min_order_qty`/backorder, and
re-run the proof gate. Needs its own design pass. Touches quote creation, pricing,
and the public tracker's PII boundary (the tracker is deliberately account-free and
PII-free, so "reorder" likely belongs behind the authenticated quote list, not the
opaque tracker).

**Rough scope when picked up:**
- Clone endpoint: `POST /quotes/{quote}/reorder` (auth, owner-scoped) → new DRAFT quote.
- Re-snapshot pricing at clone time; drop/flag any now-unavailable variant.
- Fresh proof required (artwork refs may have been pruned — see `PruneOrphanArtwork`).
- Frontend: "Reorder" action on `QuoteDetailPage` / `QuoteListPage` for CLOSED quotes.

---

## #8 — Carrier webhook → auto Shipped / Delivered

**Idea:** Integrate a courier API/webhook so label creation auto-transitions a
production job to SHIPPED (writing `consignment_ref` + carrier automatically), and a
delivery scan closes the job (→ DELIVERED). Removes the two biggest manual staff
transitions per order and automates the READY→CLOSED quote-close edge.

**Why pending:** Requires a real carrier account + API credentials and a chosen
courier (SingPost / Ninja Van / J&T / etc.), signed/verified inbound webhooks
(à la the existing `StripeWebhookController` signature pattern), and idempotent
event handling. External-dependency and ops-setup heavy — blocked on picking the
carrier and getting API access. The carrier *enum + tracking-link* work in feature
#3 lays the groundwork (carrier identity on the job); this feature builds the
inbound automation on top.

**Rough scope when picked up:**
- `POST /webhooks/carrier/{carrier}` — signature-verified, throttled, unauthenticated
  (mirror `StripeWebhookController`).
- Map carrier event → `QueueService::advance()` (SHIPPED with ref, or CLOSED).
- Idempotency key per carrier event (dedupe replays).
- Reconcile with feature #3's `carrier` field + #9/#10 manual advance paths.
