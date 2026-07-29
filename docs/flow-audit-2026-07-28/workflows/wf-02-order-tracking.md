# WF-02 — Order Tracking (login-free)

**Actors:** anyone with a tracking code + billing-email prefix, or a signed link.

## Flow

```mermaid
flowchart TD
  A[/track page/] -->|code + email| B[POST /track]
  B -->|match code AND first-5 of billing_email| C[OrderTracker payload: stage, dates, shipments]
  B -->|unknown code OR wrong email| D[Generic 404 - anti-enumeration]
  E[Signed QR/bookmark link] --> F[/track/view ?code&signature/]
  F -->|signed:relative verifies| C
  C --> G[Stepper: Confirmed → In production → Shipped → Delivered]
  G -.live.-> H[Reverb public channel track.{code}]
```

## Stages

| # | Stage | Trigger | API → handler | Result |
|---|-------|---------|---------------|--------|
| 1 | Manual track | enter code + email | `POST /track` → `TrackingController::__invoke` | PII-free stage payload, or generic 404 |
| 2 | Signed view | open QR/bookmark | `GET /track/view` (`signed:relative`) | same payload, no email needed |
| 3 | Live updates | — | Reverb `track.{code}` via `OrderTrackingUpdated` | stepper updates without reload |

## Findings touched
L14 (returned parcel shows "completed"), L17 (signed link permanent/unrevocable), L10/L11 (webhook idempotency/replay — server-side). See [FINDINGS.md](../FINDINGS.md).

## REAL-USER TEST PROMPT

> **Persona:** customer checking where their order is.
>
> 1. Get the tracking code for the seeded CLOSED order `BY4W6Q3CMN` (company 7). Open `http://127.0.0.1:5173/track`.
> 2. Enter the code + the first characters of the order's billing email. Submit. Confirm the tracker shows the **Delivered** stage with the stepper. Screenshot.
> 3. Re-submit with a **wrong email**. Confirm a single generic "not found" error (no hint that the code exists). Screenshot.
> 4. If a signed `/track/view?code=…&signature=…` link is available (from a confirmation/QR), open it and confirm it resolves with **no email prompt**. Screenshot.
>
> **Flag if:** the error text differs between "bad code" and "bad email" (enumeration leak); a returned/failed parcel reads as "delivered/completed" (L14); the stepper labels are confusing; or PII (name/address) appears in the public payload.
