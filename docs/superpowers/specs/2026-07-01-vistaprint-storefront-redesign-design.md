# Gift-Lab — Vistaprint-Angled Storefront Redesign

**Date:** 2026-07-01
**Status:** Approved design — ready for implementation plan
**Approach:** A — storefront reskin + ecommerce IA, existing B2B backend untouched

---

## 1. Goal

Transform the Gift-Lab frontend from a thin, un-commercial UI into a professional,
Vistaprint-grade custom-gifting **storefront** — without changing the backend.
The existing B2B quote → proof → pay lifecycle stays exactly as-is; we present it
inside a modern commerce experience.

Non-goals (explicitly out of scope):
- No backend/API contract changes. No new order model, no guest checkout, no
  payment-first flow. (These were considered as "Approach B / phase 2" and deferred.)
- No changes to the Laravel quote state machine, Stripe pay-now, proofing,
  procurement, or production-queue logic.

## 2. Constraints (carried from the current codebase)

- **Backend flow is fixed:** browse (public) → build cart (client-side) → login
  required → create Quote (DRAFT) → staff SENDS → buyer ACCEPTS → PROOFING →
  proof approved → PAY-NOW → production → closed. Payment only happens on a
  proof-approved quote. No guest checkout exists.
- **Stack:** React 18 + TS + Vite, react-router v6, Zustand, axios, fabric.js v6,
  laravel-echo + pusher. Design system already shipped: Tailwind + Framer Motion,
  tokens as CSS vars + Tailwind config, primitives in `src/ui/`, motion presets in
  `src/motion/`, `ThemeProvider` (light/dark), route page-transitions.
- Preserve all existing API calls, routes' data contracts, and Zustand store shapes.

## 3. Visual direction — "Bold Studio"

Dark-default, high-contrast, design-forward. Leans on the fabric.js/3D designer as
the brand hero. **Full dark/light theme toggle** (reuse existing `ThemeProvider`).

Token palette (retheme the existing CSS-var token layer; both themes first-class):

| Token | Dark | Light |
|---|---|---|
| `--bg` | `#0e0e12` | `#f6f6fb` |
| `--surface` | `#16161d` | `#ffffff` |
| `--surface-2` | `#1e1e28` | `#f0f0f6` |
| `--border` | `#2a2a34` | `#e6e6ef` |
| `--fg` | `#f4f4f7` | `#14141a` |
| `--fg-muted` | `#9a9aa8` | `#5b5b6b` |
| `--primary` (accent) | `#ff4d6d` | `#ff3b5f` |
| `--accent-2` | `#7c5cff` | `#6a4bff` |

- Signature gradient: `linear-gradient(135deg,#ff4d6d,#ff9e64)`; violet/cyan
  gradients for secondary product tiles.
- Type: bold, tight-tracked display weights (900) for headlines; existing Inter/UI
  text for body. (Fraunces from the current system may be dropped or kept for a
  single editorial accent — decide at build time; Bold Studio leans sans-heavy.)
- Motion: existing presets. Purposeful — hero entrance, staggered grids, hover
  elevation, spring micro-interactions. Honor `prefers-reduced-motion`.

The theme toggle lives in the header, persists to `localStorage`, and defaults to
dark.

## 4. Information architecture

Current IA is thin: `/` **is** the catalogue; a product click jumps **straight into
the designer**; there is no homepage and no product detail page.

New IA (routes):

| Route | Page | Status |
|---|---|---|
| `/` | **Home** (merchandised landing) | NEW |
| `/products` | **Catalogue** (filterable grid + category nav) | Evolved (was `/`) |
| `/products/:id` | **Product Detail (PDP)** | NEW |
| `/design/:id` | **Designer** (configure-first) | Reskin (was `/catalogue/:id`) |
| `/cart` | **Cart** | Reskin |
| `/checkout` | **Checkout** (framing over quote creation) | Reskin/reframe of quote request |
| `/quotes` | **My Orders** (buyer quote list) | Reskin |
| `/quotes/:id` | **Order / Quote detail** | Reskin |
| `/login` | Auth | Reskin (done) |
| ops/admin | production-queue, procurement, catalogue-admin | Reskin (done) |

Primary journey: **Home → Catalogue → PDP → Customize (Designer) → Cart →
Checkout (creates Quote) → Pay**. The "Checkout" is a storefront-styled wrapper
around the existing `POST /quotes` (+ existing pay-now later in the quote lifecycle);
it does **not** bypass the B2B flow.

Redirects: keep old paths working — `/catalogue` → `/products`, and
`/catalogue/:id` → `/products/:id` (the old "product = designer" link now lands on
the PDP; the designer is reached from the PDP's "Customize" CTA at `/design/:id`),
so bookmarks/tests don't break.

## 5. Global chrome

- **Header (mega-nav):** logo, product categories, search field, cart badge,
  theme toggle, account/login. Sticky, glass/blur background. Collapses to a
  hamburger drawer + bottom-anchored search on mobile.
- **Footer:** trust badges (turnaround, live preview, secure checkout, bulk/
  corporate), link columns, copyright.

## 6. Page designs

### 6.1 Home (NEW)
Sections top→bottom: sticky glass nav → hero (eyebrow, bold headline, dual CTA
"Open the studio" / "Browse products", floating preview chips) → shop-by-category
grid → popular products grid → how-it-works (3 steps) → trust bar → footer.
Data: reuse `GET /catalogue` for popular/featured products; categories derived
from product `class`. No new endpoints.

### 6.2 Catalogue `/products` (evolved)
Filterable product grid + category nav/chips + search (client-side over the loaded
page, as today). Refined Bold Studio product cards (hover elevation, tag badges,
price). Staggered reveal. Loading/empty/error/success states. Product card links
to PDP (`/products/:id`), not straight to the designer.

### 6.3 PDP `/products/:id` (NEW)
Two-column desktop layout:
- **Left gallery — STICKY** (`position:sticky; top:~header height`, releases at the
  Specifications section) so there is no dead space when the info column is taller.
  Main image + thumbnail strip. "3D preview available" badge when applicable.
- **Right info:** breadcrumb, title, rating summary, price, description, variant
  selectors (color swatches, print method), **quantity tier pricing** (surfaces
  B2B bulk value: 25/100/250/500+ per-unit price), primary CTA **"Customize in
  studio"** (→ `/design/:id`), secondary "Add sample to cart", trust mini-row.
- **Below (full-width):** Specifications, reviews, related products.
Data: reuse `GET /catalogue/{product}` and `POST /price-estimate` for tier prices.
Reviews are presentational (static/placeholder) unless a source exists — no new
backend.

### 6.4 Designer `/design/:id` (reskin)
Bold Studio theme applied to the existing configure-first designer (already
rebuilt with pro-tool layout, floating layer toolbar, save/add-to-cart). fabric.js
wiring untouched.

### 6.5 Cart `/cart` + Checkout `/checkout` (reskin/reframe)
Storefront cart (line items, tier-aware pricing, summary) → **Checkout** page
styled as commerce (contact/shipping context, order summary, confirm). On confirm
it calls the existing `POST /quotes` (login required — prompt/redirect to `/login`
if anonymous, then resume). The celebratory confirmation + subsequent
proof/pay steps use the existing quote lifecycle. No payment-first.

### 6.6 My Orders `/quotes`, `/quotes/:id` (reskin)
Buyer-facing quote list re-labeled "My Orders", detail page with status timeline,
line items, pricing, proofs, and lifecycle actions (accept, pay, proof decisions) —
already themeable via primitives.

### 6.7 Auth + ops/admin (reskin)
Apply Bold Studio tokens; already component-driven from the earlier redesign.

## 7. Mobile responsiveness (cross-cutting, REQUIRED for every page)

Mobile-first, must work 360px → desktop with **no horizontal scroll** on any page:
- Header collapses to hamburger drawer; search becomes a full-width row or icon-
  triggered overlay; cart + theme toggle remain reachable.
- Grids reflow: category grid 6→2/3 cols; product grids 4→2→1; steps/trust 3-4→1-2.
- **PDP:** columns stack; gallery is NOT sticky on mobile (normal flow); tier
  pricing wraps; CTAs full-width; consider a sticky bottom "Customize" bar.
- **Tables → cards:** any tabular data (cart, quotes, procurement, production,
  admin) stacks into cards on small screens (pattern already used).
- Tap targets ≥44px; motion lighter on mobile; respect `prefers-reduced-motion`.
- Verify each page at 360 / 768 / 1280 before it's considered done.

## 8. Quality bar (unchanged from house standard)

- A11y WCAG 2.1 AA: keyboard nav, focus-visible, ARIA, 4.5:1 contrast (verify both
  themes), reduced-motion.
- TypeScript strict, no `any`. `npm run typecheck` clean. `npm test` green; add/adjust
  tests for new pages (Home, PDP) and updated routes/redirects.
- Motion on transform/opacity/layout only; no CLS; lazy-load routes/images.
- Reuse the design system — no one-off styles or ad-hoc animations.

## 9. Implementation phasing (suggested)

1. **Retheme tokens → Bold Studio** (dark+light), theme toggle persistence. Global,
   unblocks everything.
2. **Global chrome** — mega-nav header + footer, responsive drawer.
3. **Routing + IA** — add `/products`, `/products/:id`, `/design/:id`, `/checkout`;
   redirects for old paths.
4. **Home** (new).
5. **PDP** (new, sticky gallery + tier pricing).
6. **Catalogue** evolve (grid links to PDP).
7. **Cart → Checkout** reframe.
8. **My Orders / detail + auth + ops/admin** retheme pass.
9. **Mobile QA pass** across all pages at 360/768/1280.

## 10. Risks / open questions

- **Checkout ↔ login gate:** anonymous users building a cart must authenticate
  before `POST /quotes`. UX: prompt at checkout, redirect to login, resume cart
  (cart is client-side Zustand, so it survives). Confirm this is acceptable vs.
  gating "Add to cart" behind login.
- **Reviews & tier pricing data:** reviews are presentational unless real data
  exists; tier pricing uses `POST /price-estimate` per quantity — confirm the
  endpoint returns per-unit breaks or compute client-side from returned totals.
- **Old test coverage:** `CataloguePage.test.tsx` and others assume current routes;
  update alongside the route changes.
