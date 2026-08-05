import type { ReactNode } from 'react';
import { Motion, staggerContainer, staggerItem } from '../motion';

/**
 * Shared shell for the auth pages (login / register / forgot / reset / Google
 * completion). An elevated split panel: a cobalt->azure brand panel and the
 * form beside it. The brand panel now shows on EVERY breakpoint - a full-height
 * column on desktop, a compact gradient header band on mobile - so phones get
 * the same branded treatment instead of a bare form.
 *
 * Pages pass their heading + form as `title`/`subtitle`/`children`; the chrome
 * lives here so the pages stay consistent and thin.
 */
export function AuthLayout({
  title,
  subtitle,
  children,
}: {
  title: string;
  subtitle: string;
  children: ReactNode;
}) {
  return (
    // Full-bleed: no card wrapper, no max-width. The panel and form each own
    // half the viewport on desktop; on mobile the panel is a band above the
    // form. (The app Layout drops its content cap + padding for auth routes.)
    <div className="grid w-full lg:min-h-[calc(100vh-4rem)] lg:grid-cols-2">
      <BrandPanel />

      {/* Not a <main> - the app Layout already owns the page's single <main>. */}
      <div className="flex flex-col justify-center px-6 py-12 sm:px-10 lg:px-16">
        <div className="mx-auto w-full max-w-sm">
          <Motion variants={staggerContainer} initial="hidden" animate="visible">
            <Motion variants={staggerItem} className="mb-8 text-center lg:text-left">
              <h1 className="font-display text-3xl text-fg sm:text-4xl">{title}</h1>
              <p className="mt-2 text-sm text-fg-muted">{subtitle}</p>
            </Motion>
            {children}
          </Motion>
        </div>
      </div>
    </div>
  );
}

// Honest product signals (mirrors the site footer's trust badges) - factual
// features, not testimonials or invented stats.
const VALUE_PROPS: { title: string; note: string }[] = [
  { title: '3-day turnaround', note: 'Most orders ship within three working days.' },
  { title: 'Live 2D + 3D preview', note: 'See your design before you commit.' },
  { title: 'Bulk & corporate pricing', note: 'Volume discounts for teams.' },
  { title: 'Secure checkout', note: 'Encrypted payments, consent-first.' },
];

/**
 * Brand panel. Deliberately carries NO logo: the site header already shows the
 * one GiftLab mark, so a lockup here just duplicated it a few pixels away.
 *
 * Content: eyebrow + headline + tagline, then a value-prop list on desktop.
 * The list is `hidden lg:flex` so the mobile band (where the panel stacks above
 * the form) stays short - only the headline/tagline ride along on phones. Text
 * stays readable to AT; all the shapes/pattern are aria-hidden.
 */
function BrandPanel() {
  return (
    <aside
      className="relative flex flex-col justify-center gap-8 overflow-hidden p-8 text-white lg:p-12"
      style={{ background: 'var(--grad-brand)' }}
    >
      {/* Dot-grid texture to fill the negative space. */}
      <span
        aria-hidden="true"
        className="pointer-events-none absolute inset-0 opacity-[0.08]"
        style={{
          backgroundImage: 'radial-gradient(currentColor 1px, transparent 1px)',
          backgroundSize: '22px 22px',
        }}
      />
      {/* Ambient light blobs. */}
      <span
        aria-hidden="true"
        className="pointer-events-none absolute -left-20 -top-24 h-64 w-64 rounded-full bg-white/15 blur-2xl"
      />
      {/* Gift-box + ribbon motif (desktop only), anchored bottom-right. */}
      <svg
        aria-hidden="true"
        viewBox="0 0 200 200"
        className="pointer-events-none absolute -bottom-8 -right-8 hidden h-80 w-80 text-white/10 lg:block"
        fill="currentColor"
      >
        <rect x="34" y="78" width="132" height="92" rx="16" />
        <rect x="92" y="78" width="16" height="92" />
        <rect x="34" y="112" width="132" height="16" />
        <path d="M100 78C92 60 70 56 64 68c-5 11 8 18 24 18h12zM100 78c8-18 30-22 36-10 5 11-8 18-24 18h-12z" />
      </svg>
      {/* Overall scrim keeps white text at AA across the whole gradient. */}
      <span
        aria-hidden="true"
        className="pointer-events-none absolute inset-0 bg-ink-900/15"
      />

      <div className="relative">
        <p className="mb-3 text-xs font-semibold uppercase tracking-[0.14em] text-white/70">
          Custom gifting for teams
        </p>
        {/* Explicit text-white: the global `h1-h4 { color: var(--color-fg) }`
            base rule (dark ink) outranks the inherited text-white on the panel,
            so the class is needed to keep the headline readable on the gradient. */}
        <h2 className="font-display text-3xl leading-tight text-white lg:text-4xl">
          Gifting, crafted to order.
        </h2>
        <p className="mt-3 max-w-sm text-sm text-white/90">
          Custom gifts and merchandise, designed live and delivered fast.
        </p>
      </div>

      <ul className="relative hidden max-w-sm flex-col gap-4 lg:flex">
        {VALUE_PROPS.map((v) => (
          <li key={v.title} className="flex items-start gap-3">
            <span
              aria-hidden="true"
              className="mt-0.5 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-white/15"
            >
              <svg viewBox="0 0 20 20" className="h-3.5 w-3.5" fill="none">
                <path
                  d="M4 10.5l3.5 3.5L16 6"
                  stroke="currentColor"
                  strokeWidth="2.2"
                  strokeLinecap="round"
                  strokeLinejoin="round"
                />
              </svg>
            </span>
            <span>
              <span className="block text-sm font-medium">{v.title}</span>
              <span className="block text-xs text-white/75">{v.note}</span>
            </span>
          </li>
        ))}
      </ul>
    </aside>
  );
}
