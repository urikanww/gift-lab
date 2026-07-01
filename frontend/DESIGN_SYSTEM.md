# Gift-Lab Design System

The shared visual + motion foundation for the Gift-Lab frontend. **Downstream
feature work must consume these tokens, primitives, and motion presets — do not
reinvent them or hard-code hex / durations.**

---

## 1. Chosen aesthetic — "Modern Boutique"

A warm, editorial, commerce-clear identity. Think a well-art-directed DTC
storefront (Aesop calm + Faire clarity), not a generic SaaS dashboard.

**Moodboard in words**

- **Warm paper canvas.** Backgrounds are a soft warm off-white (`--ink-50`), not
  clinical grey-blue. Surfaces are pure white and lift off the page with soft,
  layered shadows.
- **Ink-forward neutrals.** The neutral scale is a warm brown-grey ("ink"), so
  text feels printed rather than pixelated.
- **Confident clay brand.** A terracotta / clay `--brand-500 (#cf5732)` carries
  primary actions and identity — distinctive, human, giftable.
- **Jade accent** (`--accent-500 #1f8f63`) for success and positive commerce
  affordances.
- **Editorial type pairing.** Display headings in **Fraunces** (a soft,
  high-contrast serif with optical sizing) paired with **Inter** for UI text —
  the serif/grotesk contrast is the signature move.
- **Calm, deliberate motion.** Short (140–360ms) transform/opacity transitions
  on a single standard easing curve. Nothing bounces gratuitously; springs are
  reserved for physical interactions (press, hover-lift, pop-in).

---

## 2. Setup

- **Tailwind CSS v3** + PostCSS + Autoprefixer. Config: `tailwind.config.js`,
  `postcss.config.js`. Entry: `src/index.css` (`@tailwind base/components/utilities`).
- **Framer Motion v11** (`framer-motion`) for animation.
- **Fonts** loaded in `index.html` (Fraunces + Inter, Google Fonts, preconnected).
- **Only two new deps** beyond the brief: none. Tailwind + Framer Motion only.
  `cn()` is a 12-line local helper (no `clsx`), theming is native context.

Tokens are defined **once** as CSS custom properties in `src/index.css` and
mapped into Tailwind in `tailwind.config.js` via `var()`. This makes them
runtime-themeable (light/dark) while still usable as ergonomic Tailwind classes.

---

## 3. Token reference

Prefer the **Tailwind utility** (left) which resolves to the **CSS variable**.

### Color — semantic (use these first)

| Utility | Token | Meaning |
| --- | --- | --- |
| `bg-bg` | `--color-bg` | Page background |
| `bg-surface` / `bg-surface-2` | `--color-surface(-2)` | Cards / raised fills |
| `border-border` / `border-border-strong` | `--color-border(-strong)` | Hairlines / inputs |
| `text-fg` / `text-fg-muted` / `text-fg-subtle` | `--color-fg*` | Text hierarchy |
| `bg-primary` `text-primary-fg` `hover:bg-primary-hover` | `--color-primary*` | Brand actions |
| `text-success` `bg-success-bg` | `--color-success*` | Positive |
| `text-danger` `bg-danger-bg` | `--color-danger*` | Errors / destructive |
| `text-warning` `bg-warning-bg` | `--color-warning*` | Caution |
| `text-info` `bg-info-bg` | `--color-info*` | Informational |
| `ring-ring` | `--color-ring` | Focus ring |

### Color — scales

`brand-{50…900}`, `accent-{50…900}`, `ink-{0,50…900}` — full ramps for custom
compositions. `brand.DEFAULT` = current primary; `accent.DEFAULT` = success.

### Typography

- Families: `font-display` (Fraunces), `font-text` (Inter), `font-mono`.
- Sizes: `text-2xs … text-6xl` (see `tailwind.config.js`). Body default 15px.
- Headings (`h1–h4`) auto-use Fraunces via base layer.

### Radii — `rounded-{xs,sm,md,lg,xl,2xl,full}` → `--radius-*`

### Shadows (layered) — `shadow-{xs,sm,card,md,lg}` + `shadow-focus` → `--shadow-*`

### Z-index — `z-{base,raised,sticky,header,dropdown,overlay,modal,toast,tooltip}`

### Motion tokens

- Durations: `duration-{instant,fast,base,slow,slower}` (80/140/220/360/560ms).
- Easing: `ease-{standard,emphasized,out,in-out}`.
- JS mirror for Framer: `duration` + `easing` objects in `src/motion/transitions.ts`.

### Theming

`<ThemeProvider>` sets `data-theme="light|dark"` on `<html>` (persisted to
localStorage, respects `prefers-color-scheme`). Dark mode flips token values
automatically — components need no dark-specific classes. Toggle via
`useTheme().toggleTheme()`.

---

## 4. Primitives — `src/ui/`

Import from the barrel: `import { Button, Card, Modal, useToast } from '../ui';`
All primitives are typed (no `any`), keyboard-accessible, focus-visible, ARIA-wired,
and honor `prefers-reduced-motion`.

| Primitive | Key props | Notes |
| --- | --- | --- |
| `Button` | `variant` (primary/secondary/ghost/outline/danger), `size` (sm/md/lg), `loading`, `fullWidth`, `leadingIcon`, `trailingIcon` | Press-scale micro-interaction; `loading` sets `aria-busy` + spinner |
| `Input` | `label`, `hint`, `error`, `leadingIcon` | Auto-wires `htmlFor`/`aria-describedby`/`aria-invalid` |
| `Select` | `label`, `hint`, `error`, `options[]` or children, `placeholder` | Custom chevron, same a11y wiring |
| `Badge` | `tone` (neutral/brand/success/danger/warning/info), `size`, `dot` | Status pills |
| `Card` + `CardHeader/Title/Description` | `interactive`, `padding` | `interactive` adds hover-lift spring |
| `Modal` | `open`, `onClose`, `title`, `description`, `footer`, `size` | Portal, focus-trap, Escape, scroll-lock, focus-restore, `role="dialog"` |
| `ToastProvider` + `useToast()` | `toast({ title, description, tone, duration })` | Portal viewport, `aria-live`, auto-dismiss |
| `Skeleton` / `SkeletonText` | `variant`, `width`, `height`, `lines` | Shimmer (static under reduced motion), `aria-hidden` |
| `EmptyState` | `icon`, `title`, `description`, `action` | Zero-state block |
| `Tooltip` | `content`, `side` | Hover **and** focus triggered, `aria-describedby` |
| `Spinner` | `size`, `label` | Add `label` when standalone (sets `role=status`) |

`ThemeProvider`, `ToastProvider` are already mounted in `App.tsx`. `cn(...)` is
the className joiner.

### Existing helpers (do not duplicate)

`src/components/ui/States.tsx` already provides `AsyncBoundary`, `LoadingState`,
`ErrorState`, and a legacy `EmptyState`. For loading/error/empty gating of async
data, keep using `AsyncBoundary`. Use the new `src/ui` `EmptyState` for richer
zero-states with actions.

---

## 5. Motion presets — `src/motion/`

Import from the barrel: `import { fadeInUp, staggerContainer, Motion } from '../motion';`

### Variants (drive with `initial="hidden" animate="visible" exit="exit"`)

- `fadeInUp` — workhorse content enter (fade + rise).
- `fadeIn` — fade only.
- `scaleIn` — pop from 0.96 (modals, popovers, cards).
- `slideUp` — from bottom edge (sheets, toasts).
- `staggerContainer` + `staggerItem` — stagger a list; put the container variant
  on the parent, item variant on each child.
- `pageVariants` — used by the shell's route transition (see below).

### Transitions / springs

`tweenBase`, `tweenFast`, `tweenOut`, `springSoft`, `springSnappy`, plus raw
`duration` and `easing` token objects.

### Reduced-motion

- `useReducedMotionSafe()` → `true` when it's safe to animate.
- `<Motion>` — reduced-motion-aware `motion.div`; strips animation props when the
  user opts out. Prefer it over raw `motion.div` in shared UI.
- `withReducedMotion(variants, animate)` for one-off variant gating.

### Usage example

```tsx
import { Motion, staggerContainer, staggerItem } from '../motion';

<Motion variants={staggerContainer} initial="hidden" animate="visible">
  {items.map((it) => (
    <Motion key={it.id} variants={staggerItem}>
      <ProductCard {...it} />
    </Motion>
  ))}
</Motion>;
```

### Route / page transitions

The app shell (`components/Layout.tsx` → `components/AnimatedOutlet.tsx`) already
wraps routed pages in `<AnimatePresence mode="wait">` keyed on `pathname`.
**Pages get an enter/exit transition for free — do nothing.** For finer control
within a page, wrap a section in `<PageTransition>`.

---

## 6. Accessibility & performance contract

- WCAG 2.1 AA: global visible `:focus-visible` ring, skip-link in the shell,
  labelled controls, `role`/`aria-*` on Modal/Toast/Tooltip/Spinner.
- All motion is transform/opacity only → 60fps, no CLS. Every animation degrades
  to static under `prefers-reduced-motion` (both a global CSS safety net **and**
  per-component logic).
- Mobile-first, no horizontal scroll 360px→desktop. Shell nav collapses to a
  drawer under `md`.

---

## 7. Notes for downstream agents

- **No path alias** is configured — use **relative imports** (`../ui`, `../motion`).
- Legacy hand-rolled classes (`.card`, `.btn`, `.table`, `.badge`, …) still exist
  in `index.css` as **transitional shims** repointed at the new tokens, so
  un-migrated pages don't break. When you restyle your page, replace those
  classes with primitives/utilities; the shims are safe to delete once no page
  uses them.
- Do not change API calls, routes, or Zustand store shapes.
- Run `npm run typecheck` and `npm test` before handing off; both must stay green.
