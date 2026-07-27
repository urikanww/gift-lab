# Missed Goals — Strategic Roadmap

**Date:** 2026-07-27  
**Method:** 8-area capability-gap audit (financial/tax, security, order lifecycle, comms, observability/resilience, PDPA, testing/CI, production-ops). Each gap confirmed in code. Excludes everything already fixed in prior sets.

# Goal-Gap Roadmap — Singapore B2B Custom-Gifting Platform

Scope note: two `possible-intentional` items were dropped — **multi-currency FX** (SGD-only is a defensible Phase-2 decision; the `char(3) default 'SGD'` columns just need a one-line recorded decision) and **cookie-consent banner** (only first-party Sanctum cookies exist today; revisit the moment any analytics pixel lands). **SMS/WhatsApp** is kept despite its `possible-intentional` tag because it materially serves SG B2B procurement contacts. Overlapping GST/invoice, reorder, refund-vs-cancel, and failed-job goals have been merged.

## Top missed goals

Ranked by business/legal risk × value.

- **[CRITICAL][L] Compute Singapore GST (9%) as a first-class, itemised line across quote → invoice.** `PricingService::quoteTotals` (app/Services/PricingService.php:229-239) builds `total = subtotal + delivery` only; the quotes migration (2026_07_01_000009:40-43) and `Invoice` (app/Models/Invoice.php:29-39) have no tax column — the sole "tax" path is a hand-typed free-text `adjustments` note (Quote.php:101-112; QuoteLineEditor.tsx placeholder "e.g. …GST"). Every order total is wrong and output tax can't be reconstructed for IRAS. *First step:* add a `tax`/`gst_amount` column + a 9% GST line in `quoteTotals`, surfaced separately in the breakdown.

- **[CRITICAL][L] Give the floor a defect / QC-gate / reprint path.** `JobState` is linear Ready→InProduction→Shipped→Closed with no branch; grep for `defect|reprint|rework|qc` returns zero production hits; `production_jobs` has no scrap/rework columns. Owner states defect output is real, yet a scrapped print is silently re-run with no stock write-off, cost attribution, or date-slip signal. *First step:* add a `Hold/Rejected` job state + reprint action that writes off consumed blank/filament stock and re-queues.

- **[HIGH][S] Publish a Privacy Policy / PDPA notice and a DPO contact.** grep of frontend/src for `privacy|pdpa|/legal` returns only invoice-terms; no `/privacy` route, page, or footer link exists. PDPA s.11-12 require published policy + designated DPO — non-compliant on day one, cheap to fix. *First step:* ship a `/privacy` page with purpose/retention/complaint channel + DPO email, linked in the footer.

- **[HIGH][M] Capture and record PDPA consent where PII is collected (registration + ship-to).** RegisterPage.tsx (71-146) collects name/work-email/phone/company with no consent checkbox or purpose notice; `AuthController` has no `consent`/`pdpa` handling; no `consented_at` column exists. Recipient PII is collected with zero recorded basis. *First step:* add a consent checkbox + `consented_at` column, persisted server-side on register and checkout.

- **[HIGH][L] Issue IRAS-compliant tax invoices — sequential numbering + printable document + GST shown.** Invoice refs are staff-typed unique strings, never sequential (IssueInvoiceRequest.php:26-27; QuoteService.php:870-880); no PDF library in composer.json; the seller's GST-registration number is stored nowhere (companies.registration_no is the buyer UEN, 2026_07_01_000001:22). Buyers have no legal document to pay/claim input tax against. *First step:* auto-generate a sequential invoice number and render a "Tax Invoice" PDF carrying supplier GST no., date, GST amount.

- **[HIGH][M] Close the cancel/return money loop — invoice void + refund / credit-note record.** `QuoteService::cancel()` (946-964) transitions state and reverses stock but never touches the invoice; `QuoteState` allows Invoiced/Confirmed→Cancelled (56-58), so a PAID order can be cancelled while the invoice still reads PAID; `PaymentState` has only UNPAID/PARTIAL/PAID/VOID with no partial-credit mechanism (PaymentState.php); `PaymentGateway` has no `refund()`. Leaves accounting/GST discrepancies on paid, cancelled orders. *First step:* add a credit-note model + wire `cancel()` to void/credit the linked invoice.

- **[HIGH][M] Adopt centralized error tracking + failed-job observability.** No sentry/bugsnag/flare in composer.json; `bootstrap/app.php` maps 4 domain exceptions then falls to the flat `single` log file. The `failed_jobs` table exists (config/queue.php) but nothing prunes or alerts on it, and the one queued job (EnrichImportedModel3dProduct) has no `failed()` handler. A silent 500 in checkout/dispatch, or a 3×-failed enrich, vanishes unheard. *First step:* wire a Sentry DSN + a `Queue::failing` hook that pings staff.

- **[HIGH][M] Harden the SSRF-exposed staff URL-capture path.** `ListingCapture.php:23-27` does `Http::get($staffUrl)` with no host allowlist, no private-IP block, and default redirect-following; the route (routes/api.php:253) carries no `permission:` middleware so any `staff_admin` can hit it, and extracted title/price are reflected back (partial-read SSRF). SECURITY.md A10 explicitly deferred exactly this control. On DO/AWS this reaches the metadata endpoint → cloud-credential theft. *First step:* add a host allowlist + private/link-local block + redirect pinning before the fetch.

- **[HIGH][S] Harden the Stripe client — timeouts, bounded retries, idempotency key.** `StripePaymentGateway.php:25` builds `new StripeClient(secret)` with no options (SDK ~80s default) and `createCheckout` (34) passes no `idempotency_key`, versus the well-guarded `HttpNinjaVanClient` (120-126). A Stripe brown-out ties up PHP-FPM workers; a retried payNow spins duplicate Checkout Sessions. The revenue path is the least resilient integration. *First step:* set connect/read timeouts + `setMaxNetworkRetries` + a per-quote idempotency key.

- **[HIGH][M] Enforce PDPA retention — PII purge/anonymise cron + right-to-erasure.** routes/console.php purges only files/drafts, never customer PII; `ExpireStaleDrafts` cancels drafts but keeps attached ship-to recipient name/phone/email/address forever; `AdminUserController::deactivate` soft-deletes but retains name/email/phone; audit_logs "Never purged". PDPA s.25 (retention limitation) and s.16/s.22 (withdrawal/correction) can't be honoured. *First step:* a scheduled command to anonymise ship-to PII N months post-delivery + a hard-delete/anonymise erasure path.

- **[HIGH][L] Deliver true one-click reorder / approved-design reuse.** `ReorderRail.tsx` (38,56) only `<Link>`s to a read-only order page; `QuoteController`/routes/api.php (125-154) expose no reorder/clone/duplicate; `cartStore.addLine()` has no `fromQuote` path. This is a repeat-purchase business — the friction directly suppresses the highest-margin revenue and re-proofs already-approved art. *First step:* a `POST /quotes/{quote}/reorder` that clones lines into a fresh draft.

- **[HIGH][S] Put the tracking number + courier link inside the "order shipped" email.** `OrderMilestoneMail::content` (45-61) passes only heading/body/ctaLabel/quoteUrl; the Shipped body (OrderMilestone.php:82) has no `consignment_ref` and no `Carrier::trackingUrl`, though that data already exists. The most-opened transactional email can't do its one job. *First step:* pass consignment ref + tracking URL into the Shipped mail template.

## All surviving goals by area

### Financial / Tax
- **[CRITICAL][L]** Compute 9% GST as an itemised line, quote→invoice (PricingService.php:229-239; quotes migration 2026_07_01_000009:40-43; Invoice.php:29-39).
- **[HIGH][L]** IRAS tax invoice: sequential numbering + PDF + supplier GST no. (IssueInvoiceRequest.php:26-27; QuoteService.php:870-880; no PDF lib in composer.json; companies.registration_no = buyer UEN).
- **[HIGH][L]** Refund / credit-note flow for post-payment corrections (grep refund/credit_note = 0; PaymentState VOID-only, QuoteService.php:922-927).
- **[HIGH][M]** Track partial-payment amount + balance-due (invoice has single `amount`, 2026_07_01_000013:25; reconcilePayment flips PARTIAL with only a note, QuoteService.php:926-927).
- **[HIGH][L]** AR / GST finance reporting + accounting export (routes/api.php only per-quote issue/reconcile; no aged-AR, output-tax summary, or CSV/Xero export).
- *(Deferred: multi-currency FX — record the SGD-only decision; currency columns are not working FX.)*

### Security
- **[HIGH][M]** SSRF-harden ListingCapture: host allowlist + private-IP block + redirect pinning + `permission:` on route (ListingCapture.php:23-27; routes/api.php:253; SECURITY.md A10).
- **[MEDIUM][M]** Production-safe uploads: re-encode raster artwork, malware scan, pin Content-Disposition/nosniff (UploadController::artwork 41-44 / proof 68-71 store raw bytes; no imagick/clamav; files flow to print floor via ProductionQueueController 108/143; SECURITY.md Residual line 43).

### Order lifecycle
- **[HIGH][M]** Wire cancel → invoice void + refund/credit-note (QuoteService::cancel 946-964; QuoteState 56-58 allows Invoiced→Cancelled; PaymentGateway has no refund()). *Shares delivery with the Financial credit-note goal.*
- **[HIGH][L]** Staff resolution path for returned/RTS parcels — reship / refund / close-as-returned (NinjaVanStatusMapper.php:49-50; webhook only advances on deliver, NinjaVanWebhookController.php:105-137; job stuck in Shipped, quote stuck READY, QueueService.php:288-290).
- **[HIGH/MED][M]** One-click reorder — clone lines to draft (ReorderRail.tsx 38/56; no reorder route in routes/api.php:125-154; cartStore.addLine no fromQuote). *Merged with prodops reorder goal.*
- **[MEDIUM][L]** Controlled post-acceptance line/qty edits or explicit re-quote (QuoteService::amend 228-243 "Only DRAFT can be amended"; no edge back to Draft from Invoiced/Confirmed/Procuring).

### Comms / Notifications
- **[HIGH][M]** Notify buyer AND alert staff on delivery-fail / RTS (NinjaVanWebhookController.php:125-135 broadcasts only on public channel; no Mail/StaffNotifier on non-deliver). *Pairs with the RTS resolution goal.*
- **[HIGH][S]** Tracking number + courier link in shipped email (OrderMilestoneMail.php:45-61; OrderMilestone.php:82; Carrier::trackingUrl exists at Carrier.php:36).
- **[HIGH][M]** Bounce/complaint capture + suppression list (grep bounce/complaint/SNS = 0; config/mail.php default `log`; OrderNotifier.php:85-94 treats present-but-bouncing address as delivered).
- **[MEDIUM][S]** Alert on queued-mail that fails send, not just enqueue (OrderNotifier.php:96-104 / StaffNotifier.php:60-68 catch only enqueue). *Merge with failed-job observability below.*
- **[MEDIUM][M]** Durable staff exception escalation (email/digest fallback) for procurement-blocked / courier-fail (LineItemAwaitingReconfirm.php:38 Reverb-only; StaffNotifier has only proofChangesRequested).
- **[MEDIUM][L]** SMS/WhatsApp channel for proof-approval + out-for-delivery (grep twilio/whatsapp/sms = 0; email-only). *Kept — SG B2B lives on WhatsApp.*

### Observability / Resilience
- **[HIGH][M]** Centralized error tracking (Sentry/Bugsnag) (none in composer.json; bootstrap/app.php maps 4 exceptions then flat single-file log).
- **[HIGH][M]** Failed-job observability + dead-letter policy: alert on failed_jobs, per-job retry/backoff + `failed()` + pruning (config/queue.php; EnrichImportedModel3dProduct.php has no tries/backoff/failed()). *Absorbs the queued-mail-fail comms item.*
- **[HIGH][S]** Stripe client resilience — timeouts, bounded retries, idempotency key (StripePaymentGateway.php:25,34 vs HttpNinjaVanClient.php:120-126).
- **[MEDIUM][S]** Deep health-check endpoint verifying DB/Redis/queue/Reverb (bootstrap/app.php `health: '/up'` only fires DiagnosingHealth; multi-node per DEPLOYMENT.md).
- **[MEDIUM][M]** Proactive heartbeat/dead-man's-switch for scheduler/workers/Reverb (supervisor autorestart only; routes/console.php 9 nightly cmds unmonitored).
- **[MEDIUM][M]** Structured JSON logging + correlation IDs + aggregation (config/logging.php default `single`; papertrail unconfigured; no request-id processor).

### Compliance / PDPA
- **[HIGH][S]** Publish Privacy Policy + DPO contact (no /privacy route/page/footer link; PDPA s.11-12).
- **[HIGH][M]** Capture PDPA consent at registration + checkout ship-to (RegisterPage.tsx 71-146; no consent handling in AuthController; no `consented_at`; PDPA s.13-14).
- **[HIGH][M]** Retention/purge cron for customer PII (routes/console.php purges only files/drafts; ship-to PII retained forever; PDPA s.25).
- **[HIGH][M]** Right-to-erasure / correction (AdminUserController::deactivate soft-deletes, retains name/email/phone; no anonymise; PDPA s.16/s.22).
- **[MEDIUM][M]** Right-to-access / data-portability export (no per-user export route; only GET /user profile; PDPA s.21).
- **[MEDIUM][M]** Encrypt sensitive PII at rest via `encrypted` casts (User.php casts only password; ship-to + companies UEN/phone/address plaintext; PDPA s.24).
- **[MEDIUM][L]** Tamper-resistant audit log + PII minimisation (AuditLog.php no hash-chain; AuditLogger stores full old/new_values JSON; table "Never purged").
- *(Deferred: cookie-consent banner — only first-party Sanctum cookies today; add when any pixel lands.)*

### Testing / CI
- **[HIGH][S]** Make CI green — fix ThreeMfToStlTest missing fixtures (reads base_path('scraper/out/models3d/*.3mf'); dir absent, not gitignored; ci.yml:38 runs pest unfiltered → 2/3 fail every run).
- **[HIGH][M]** Static-analysis + lint gate: ESLint + PHPStan/Larastan + Pint (no ESLint dep/config in frontend; pint in require-dev but no pint.json, never invoked; ci.yml runs only typecheck/test/build/pest).
- **[HIGH][L]** E2E/browser smoke tests for buyer→staff→production→ship (no Playwright/Cypress; PHP Feature + Vitest jsdom each mock the other half).
- **[MEDIUM][M]** Coverage measurement/enforcement + diff-coverage (ci.yml:20 `coverage: none`; no vitest coverage block).
- **[MEDIUM][M]** Stripe-webhook tests + pin NinjaVan/Stripe response contracts (StripeWebhookController unauthenticated, routes/api.php:106, zero tests; NinjaVan uses hand-written Http::fake stubs).
- **[MEDIUM][M]** Load/perf baselines on heavy endpoints incl. 3D paths (no k6/artillery/etc.).
- **[LOW][S]** Hermetic test env pinned in phpunit.xml (no SANCTUM_STATEFUL_DOMAINS; .env.ci minimal; local .env can diverge).

### Production-ops & Self-service
- **[CRITICAL][L]** Defect / QC-gate / reprint capability (JobState linear; no defect/scrap/rework columns or actions; QueueService exposes only advance/createShipment).
- **[HIGH][XL]** Capacity planning + machine/operator assignment + WIP limits (QueueService pure FCFS by ready_at; no machine_id/assigned_to/scheduled_for; UV + 3D share one list).
- **[MEDIUM][M]** Capture real cycle-time + throughput / promised-vs-actual (production_jobs has only ready_at/delivered_at; DashboardMetrics AT_RISK_SLA_HOURS hardcoded 72).
- **[MEDIUM][L]** Business-analytics/reporting layer — revenue trends, throughput, top products, repeat-rate, order export (DashboardMetrics is point-in-time only; single GET /admin/dashboard).
- **[LOW][S]** Automated a11y regression checks (jest-axe/axe-core absent; strong hand-maintained a11y unguarded).
- *Self-serve GST invoice download — folded into the Financial/Tax IRAS-invoice goal.*

## Suggested sequencing

### Wave 1 — Now (launch blockers, legal + revenue correctness, cheap security wins)
GST computation (critical) · IRAS tax invoice + sequential numbering + PDF · Privacy Policy + DPO · PDPA consent capture · Defect/QC-gate/reprint (critical) · SSRF guard on ListingCapture · Stripe client hardening (S, revenue) · Sentry + failed-job alerting · Tracking number in shipped email (S) · Fix ThreeMfToStlTest so CI is trustworthy (S).

### Wave 2 — Next (close the operational + accounting + PDPA loops)
Refund/credit-note + wire cancel→invoice reversal · Partial-payment/balance tracking · AR/GST reporting + export · RTS parcel resolution + buyer/staff alerting · One-click reorder · PDPA retention purge cron + right-to-erasure · Bounce/complaint capture + suppression · Deep health-check endpoint · Static-analysis + lint gate · Upload re-encode/malware/nosniff · Stripe-webhook tests.

### Wave 3 — Later (scale, insight, and hardening)
Capacity planning + machine/WIP (XL) · Cycle-time + throughput metrics · Business-analytics/reporting layer · Structured logging + aggregation · Scheduler/worker/Reverb heartbeat monitoring · Durable staff-exception digest · SMS/WhatsApp channel · Post-acceptance line/qty edits · PII-at-rest encryption · Audit-log hash-chain + PII minimisation · Right-to-access export · E2E/browser suite · Coverage floor · Load baselines · Hermetic test env · a11y regression checks · record SGD-only + cookie-posture decisions.