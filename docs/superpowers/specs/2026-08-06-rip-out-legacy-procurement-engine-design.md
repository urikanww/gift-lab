# Rip Out the Legacy Procurement Engine — Design

**Date:** 2026-08-06
**Status:** Draft for review

## Problem

After the buy-list rework (#26) and the B2C payment fix (#27), the old automatic
procurement engine has **no live caller**. It sits dormant: the sourcing
strategies, `procure()`/`reconfirmLine()`/`confirmStock()`, the reconfirm desk,
and their routes/endpoints are all unreachable from the running app. This is pure
technical debt — delete it.

## Scope: dead engine only (code, no database)

Chosen scope: remove only what is genuinely dead and unshared. **No database
migrations, no table drops.** Explicitly **kept** (verified still used):

- **Variant stock ledger** (`StockLedger`, `StockMovement`, `Variant.stock_on_hand`)
  — a live product-admin feature (staff set/adjust variant stock via
  `AdminProductController`, through the ledger).
- **Buy-list machinery** — `ProcurementManager::markBought`, `QuoteService`'s
  `tryQueue`/`isReadyForProduction`/`cancelIfNothingLeftToProduce`,
  `markLineBought`/`markQuoteBought`/`markProductBought`.
- **Supplier-reorder / filament** — out of scope (a later, destructive step).
- **Cancel/refund** — `returnConsumedStock`/`returnConsumedFilament` stay (part of
  the cancel path; now largely no-ops but harmless and out of scope).
- **`ProductClass` enum** and product filament labels — classify products; stay.
- **`LineItemState::AwaitingReconfirm`** — the enum case and its state-machine
  transitions are **retained** (removing an enum case risks historical
  audit/line rows referencing it; no migration desired). It simply becomes
  unproducible.

## Deletions

### Backend — services
- Delete `app/Services/Procurement/CoreProcurement.php`,
  `ScrapedUvProcurement.php`, `Model3dProcurement.php`.
- Delete their support: `Contracts/ProcurementStrategy.php`,
  `Contracts/MarketplaceRechecker.php` and its implementation(s)
  (`HttpMarketplaceRechecker`/`FixtureMarketplaceRechecker` — whichever exist),
  `ProcurementResult.php`, and the `ProcurementOutcome` enum. Verify none are
  referenced outside the deleted set before removing each.
- `ProcurementManager.php`: remove `procureLine`, `strategyFor`, `onReconfirm`,
  `onAdvisory`, `blocksOnQtyShort`, and the strategy constructor dependencies.
  **Keep** `markBought` and `onProcured` (+ the `AuditLogger` dependency).
- `QuoteService.php`: delete `procure()`, `reconfirmLine()`,
  `retotalAfterReconfirm()`, and `confirmStock()`. Keep everything the buy list
  and cancel path use.

### Backend — HTTP + events
- `QuoteController`: delete `procure()` and `confirmStock()` actions.
- `ProcurementController`: delete `index()` (awaiting-reconfirm list) and
  `reconfirm()`. **Keep** `buyList`/`markBought`/`markProductBought`.
- `routes/api.php`: delete `POST /quotes/{quote}/procure`,
  `POST /quotes/{quote}/confirm-stock`, `POST /line-items/{lineItem}/reconfirm`,
  `GET /procurement/awaiting-reconfirm`.
- Delete the `LineItemAwaitingReconfirm` event and its broadcast channel
  registration (`routes/channels.php`); remove the `staff.procurement` /
  `.line-item.awaiting-reconfirm` auth entry (see `BroadcastChannelAuthTest`).
- Delete `ReconfirmLineItemRequest` (form request for the deleted route) and
  `SetApprovalOrderRequest`? — no, keep approval-order. Only the reconfirm request.

### Frontend
- `frontend/src/stores/procurementStore.ts`: remove the reconfirm slice —
  `alerts`, `fetchAlerts`, `subscribe`, `unsubscribe`, `reconfirm`, and the
  `ReconfirmAlert`/`ReconfirmedLine`/`ReconfirmOutcome`/`AwaitingReconfirmLine`
  types + the echo import. **Keep** the `buyList` slice
  (`fetchBuyList`/`markBought`/`markProductBought`/`BuyListRow`).

## Test handling

- **Delete** (test the deleted engine directly): `tests/Feature/ProcurementTest.php`,
  `tests/Feature/Model3dProcurementTest.php`,
  `tests/Feature/ScrapedUvProcurementTest.php`,
  `tests/Feature/ProcurementDeskTest.php`.
- **Delete old-flow harness scenarios** (per decision — the journey no longer
  exists; the new flow is covered by `MarkBoughtOrderTest`/`MarkBoughtLineTest`/
  `BuyListEndpointTest`/`PayNowTest`): `HappyPathTest.php`,
  `AcceptAsIsRetotalsTest.php`, `Cancel3dFilamentReturnTest.php`.
- **Trim** `tests/Harness/Agents/StaffAgent.php`: remove `procure()` and
  `confirmStock()` (and any reconfirm/accept-as-is helper). Keep the rest.
- **Fix mixed-use** feature tests that used the dead endpoints only to reach a
  state — re-seed the state directly or drive the buy-list path instead:
  `QuoteGstPersistenceTest.php`, `QuoteHistoryTest.php`,
  `BroadcastChannelAuthTest.php` (drop the removed channel's case).
- `MarkBoughtLineTest.php`/`PayNowTest.php` reference `ProcurementManager`/
  `markBought` which **stay** — no change expected.
- Run the full backend and frontend suites; fix any smoke scenario
  (`ProductionAgentSmoke`, etc.) that indirectly depended on a removed method.

## Verification / success

- `php artisan test` and `npx vitest run` both green.
- `grep` shows no remaining references to the deleted classes/methods/routes
  outside deleted files.
- No behavioural change to the live app — only already-unreachable paths are
  removed. Manual smoke: buy list still buys → bills → floor; product-admin
  stock adjust still works; order cancel still works.

## Out of scope (future)

- Dropping `filaments` / `supplier_reorders` tables and removing the
  supplier-reorder feature (destructive; separate decision).
- Removing `returnConsumedStock`/`returnConsumedFilament` and the
  `AwaitingReconfirm` enum case (data/migration risk; not worth it now).
