# gift-lab Release-Readiness QA Audit — Design

**Date:** 2026-07-03
**Status:** Approved (design phase)
**Type:** Read-only QA evaluation (no code changes)

## Goal

Evaluate the gift-lab application (Laravel API + React/Vite SPA) for release readiness
across six quality pillars — stability, security, functionality, usability, performance,
and cross-device conformance — and produce a single prioritized findings report with a
go/no-go verdict.

This is a **read-only** pass. Agents do not edit application code, do not create git
worktrees, and do not push branches. The only artifact written is a QA report document.

## Source of Truth

No formal PRD exists. Intended behavior is derived from:
- the codebase itself (routes, controllers, services, enums, policies, stores, pages),
- `README` / in-repo docs,
- database seeders and factories (expected seeded data + roles),
- existing automated tests (Pest backend, vitest frontend).

Findings flag broken flows, internal inconsistencies, and deviations from behavior a
reasonable reading of the code + docs implies.

## Scope — Six Pillars

1. **Stability** — crashes, unhandled errors, race conditions, teardown bugs.
2. **Security** — authn/authz, input validation, secrets, CSRF, broadcast auth, uploads.
3. **Functionality** — buyer + staff flows complete end-to-end; edge cases handled.
4. **Usability** — heuristics, feedback states, copy, flow friction, consistency.
5. **Performance** — bundle size, N+1 queries, API latency, render/memory cost.
6. **Cross-device** — responsive layout, keyboard/ARIA, touch targets, dark mode.

## Agent Lanes (5, parallel, read-only)

| Lane | Covers | Method |
|---|---|---|
| `functional-e2e` | Buyer + staff flows end-to-end, edge cases, error/empty/loading states, broken paths, data integrity | Live (owns dev server) + code read |
| `security` | Sanctum authn, policy/channel authz, input validation (known smell: `QuoteController` uses raw `request->array()` not `validated()` → mass-assignment risk), secrets in repo, CSRF, broadcast/channel auth, file-upload handling | Static: grep/read + security-review heuristics |
| `performance-stability` | Bundle weight (851 kB index chunk observed), Laravel N+1, missing indexes, API latency, error boundaries, Reverb/echo race + teardown, memory | Static: code + `npm run build` metrics + query analysis |
| `ux-usability` | Nielsen heuristics, copy clarity, feedback states, flow friction, consistency | Static: code read + UX reasoning |
| `cross-device-a11y` | Viewport 375/768/1280, keyboard nav, ARIA, touch targets, dark mode, horizontal overflow | Mostly static (post prior polish pass) + brief live confirm |

## Server & Verification Constraints

Single preview dev server (vite :5173 + api :8000 via `.claude/launch.json`). Live access
is serialized:
- `functional-e2e` runs live first (owns the server).
- `cross-device-a11y` takes the live slot after functional releases it.
- `security`, `performance-stability`, `ux-usability` are fully static and run in
  parallel with either live agent.

Environment verification rules (carried from prior session):
- Do NOT rely on `preview_screenshot` (hangs on the pusher/echo websocket) or
  rAF-timed assertions (throttled headless).
- Verify via `preview_eval` computed geometry / `getComputedStyle` / DOM snapshot,
  plus `npm run typecheck` / `npm run build` / test suites.
- `preview_click` synthetic events do not reach React — dispatch a real bubbling
  `MouseEvent` (and `KeyboardEvent` for keyboard) in `preview_eval`.
- Mobile preset quirk: `window.innerWidth != document.documentElement.clientWidth` —
  judge layout by `document.documentElement.clientWidth` / `scrollWidth` and classes.

## Severity Schema

Every finding is tagged:
- **P0 blocker** — release-stopping: data loss, security hole, or broken core flow.
- **P1 major** — significant defect with a workaround.
- **P2 minor** — limited-impact defect.
- **P3 polish** — cosmetic / nice-to-have.

Fields per finding: `pillar · severity · file:line-or-endpoint · repro · observed-vs-expected · impact · suggested-fix-owner`.

## Output Artifact

`docs/qa/2026-07-03-gift-lab-release-audit.md`, containing:
- merged findings table sorted by severity,
- per-pillar summary,
- **go/no-go verdict** — rule: any open P0 = NO-GO; all P1s must be triaged.

This document is the only file written. No application code is modified.

## Coordination

Main thread:
1. dispatches the 5 read-only agents (staggering the two live-server lanes),
2. collects findings in the shared severity schema,
3. dedupes cross-lane overlaps,
4. writes the single report to `docs/qa/`,
5. commits the report doc.

The pass is re-runnable as a release gate.

## Non-Goals

- No fixing of discovered bugs (report only; fixes are a separate follow-up pass).
- No new automated tests authored.
- No dependency changes.
- No real physical-device testing (viewport emulation only).
