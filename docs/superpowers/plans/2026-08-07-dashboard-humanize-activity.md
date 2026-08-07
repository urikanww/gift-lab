# Dashboard — Humanize Activity Feed (Piece C) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The dashboard "Recent activity" feed reads as plain English for staff — a category icon, a readable sentence, and a relative time — instead of raw `actor · event (label)` audit rows.

**Architecture:** A pure, unit-tested humanizer (`activityHumanize.ts`) maps `(event, actor, label, at)` → `{ category, text, when, title }`. The dashboard renders it with a category icon. No backend change — `DashboardActivity` already carries everything.

**Tech Stack:** React 18 + TypeScript, Vitest + Testing Library. House inline-SVG icons in `src/components/icons.tsx`. `Intl.RelativeTimeFormat` for relative time (no dependency).

Spec: `docs/superpowers/specs/2026-08-07-admin-dashboard-users-ux-design.md` (Piece C — "humanize now"; the actionable feed is a separate later spec).

---

## Known data (verified against the codebase)

`DashboardActivity` (`frontend/src/lib/dashboard.ts`): `{ id, actor: string|null, event: string, auditableType: string, auditableId: number, auditableLabel: string, at: string|null }`.

`auditableLabel` is server-composed: `"Order <ref>"` for quotes, else `"<Type> #<id>"` (e.g. `"Product #12"`, `"User #5"`). So the sentence template `"{actor} {verb} {label}"` reads naturally: "Jane amended Order 9BWV", "Jane created Product #12".

Audit `event` vocabulary present today (humanizer maps these; anything unlisted falls back safely):
`quote.amended, quote.approval_order_changed, quote.cancelled, quote.chase_exhausted, quote.stock_confirmed, invoice.issued, invoice.voided, invoice.retotaled, invoice.parcel_returned, credit_note.issued, payment.captured, payment.reconciled, product.created, product.updated, product.archived, product.restored, product.blockers_resolved, product.gate_deleted, product.image_updated, product.image_removed, variant.created, variant.updated, variant.archived, variant.bulk_created, user.created, user.updated, user.deactivated, user.reactivated, user.password_reset, proof.approved, proof.resent, production_job.manually_delivered, production_job.return_resolved, supplier_reorder.received, line_item.bought, line_item.procured, courier_config.updated, pricing_config.updated, notification_setting.updated, notification_cadence.updated`.

Category is derived from the event prefix: `quote|invoice|credit_note|payment → order`; `product|variant → catalogue`; `user → user`; `proof|production_job|supplier_reorder|line_item → production`; everything else (`*_config`, `notification_*`, unknown) → `system`.

**Known tradeoff (acceptable for "humanize now"):** the sentence is generic over auditable type, so a few phrasings can double up (e.g. `invoice.issued` on an Invoice-typed row → "issued an invoice for Invoice #7"). This is still far more readable than the raw token and is refined by the later actionable-feed spec. Do not add per-type naming rules here.

## File Structure

- Create: `frontend/src/lib/activityHumanize.ts` — pure humanizer (`humanizeActivity`, `timeAgo`, `activityCategory`).
- Create: `frontend/src/lib/activityHumanize.test.ts` — unit tests.
- Modify: `frontend/src/components/icons.tsx` — add 5 category icons.
- Modify: `frontend/src/pages/DashboardPage.tsx` — render the humanized feed.
- Create: `frontend/src/pages/DashboardActivity.render.test.tsx` — page-level render test for the feed.

---

## Task 1: The humanizer util

**Files:**
- Create: `frontend/src/lib/activityHumanize.ts`
- Test: `frontend/src/lib/activityHumanize.test.ts`

- [ ] **Step 1: Write the failing test**

Create `frontend/src/lib/activityHumanize.test.ts`:

```ts
import { describe, expect, it } from 'vitest';
import { humanizeActivity, activityCategory } from './activityHumanize';
import type { DashboardActivity } from './dashboard';

function act(partial: Partial<DashboardActivity>): DashboardActivity {
  return {
    id: 1,
    actor: 'Jane',
    event: 'quote.amended',
    auditableType: 'Quote',
    auditableId: 1,
    auditableLabel: 'Order 9BWVKW',
    at: '2026-08-07T10:00:00Z',
    ...partial,
  };
}

describe('activityCategory', () => {
  it('derives category from the event prefix', () => {
    expect(activityCategory('quote.amended')).toBe('order');
    expect(activityCategory('payment.captured')).toBe('order');
    expect(activityCategory('product.created')).toBe('catalogue');
    expect(activityCategory('variant.updated')).toBe('catalogue');
    expect(activityCategory('user.deactivated')).toBe('user');
    expect(activityCategory('proof.approved')).toBe('production');
    expect(activityCategory('pricing_config.updated')).toBe('system');
    expect(activityCategory('totally.unknown')).toBe('system');
  });
});

describe('humanizeActivity', () => {
  it('renders a known event as an actor-first sentence', () => {
    const r = humanizeActivity(act({ event: 'quote.amended', actor: 'Jane', auditableLabel: 'Order 9BWVKW' }));
    expect(r.text).toBe('Jane amended Order 9BWVKW');
    expect(r.category).toBe('order');
  });

  it('uses "System" when there is no actor', () => {
    const r = humanizeActivity(act({ actor: null, event: 'quote.chase_exhausted', auditableLabel: 'Order 9BWVKW' }));
    expect(r.text.startsWith('System ')).toBe(true);
  });

  it('falls back to a readable phrase for an unknown event (no raw dotted token)', () => {
    const r = humanizeActivity(act({ event: 'weird.new_thing', actor: 'Jane', auditableLabel: 'Product #5' }));
    expect(r.text).toBe('Jane weird new thing Product #5');
    expect(r.category).toBe('system');
    expect(r.text).not.toContain('weird.new_thing');
  });

  it('provides a relative "when" and an absolute "title"', () => {
    const r = humanizeActivity(act({ at: '2026-08-07T10:00:00Z' }));
    expect(typeof r.when).toBe('string');
    expect(r.when.length).toBeGreaterThan(0);
    expect(r.title).toContain('2026'); // absolute timestamp string
  });

  it('handles a null timestamp without throwing', () => {
    const r = humanizeActivity(act({ at: null }));
    expect(r.when).toBe('');
    expect(r.title).toBe('');
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd frontend && npx vitest run src/lib/activityHumanize.test.ts`
Expected: FAIL — module `./activityHumanize` does not exist.

- [ ] **Step 3: Write the implementation**

Create `frontend/src/lib/activityHumanize.ts`:

```ts
import type { DashboardActivity } from './dashboard';

export type ActivityCategory = 'order' | 'catalogue' | 'user' | 'production' | 'system';

/** Category from the event prefix. Unknown prefixes read as "system". */
export function activityCategory(event: string): ActivityCategory {
  const prefix = event.split('.')[0];
  switch (prefix) {
    case 'quote':
    case 'invoice':
    case 'credit_note':
    case 'payment':
      return 'order';
    case 'product':
    case 'variant':
      return 'catalogue';
    case 'user':
      return 'user';
    case 'proof':
    case 'production_job':
    case 'supplier_reorder':
    case 'line_item':
      return 'production';
    default:
      return 'system';
  }
}

/**
 * Verb phrase per known event, slotted into "{actor} {verb} {label}". The label
 * is server-composed ("Order 9BWV", "Product #12"), so the phrase ends where the
 * object begins. Unknown events fall back to the de-dotted event token, so a new
 * event is still readable (never a raw "foo.bar" token on its own).
 */
const VERBS: Record<string, string> = {
  'quote.amended': 'amended',
  'quote.approval_order_changed': 'reordered approvals on',
  'quote.cancelled': 'cancelled',
  'quote.chase_exhausted': 'exhausted chase reminders on',
  'quote.stock_confirmed': 'confirmed stock on',
  'invoice.issued': 'issued an invoice for',
  'invoice.voided': 'voided the invoice for',
  'invoice.retotaled': 'retotaled the invoice for',
  'invoice.parcel_returned': 'logged a returned parcel for',
  'credit_note.issued': 'issued a credit note for',
  'payment.captured': 'recorded a payment on',
  'payment.reconciled': 'reconciled payment on',
  'product.created': 'created',
  'product.updated': 'updated',
  'product.archived': 'archived',
  'product.restored': 'restored',
  'product.blockers_resolved': 'resolved blockers on',
  'product.gate_deleted': 'deleted',
  'product.image_updated': 'updated the image of',
  'product.image_removed': 'removed the image of',
  'variant.created': 'created',
  'variant.updated': 'updated',
  'variant.archived': 'archived',
  'variant.bulk_created': 'bulk-created variants of',
  'user.created': 'created',
  'user.updated': 'updated',
  'user.deactivated': 'deactivated',
  'user.reactivated': 'reactivated',
  'user.password_reset': 'reset the password for',
  'proof.approved': 'approved a proof on',
  'proof.resent': 'resent a proof on',
  'production_job.manually_delivered': 'marked delivered',
  'production_job.return_resolved': 'resolved a return on',
  'supplier_reorder.received': 'received a reorder for',
  'line_item.bought': 'bought a line item on',
  'line_item.procured': 'procured a line item on',
  'courier_config.updated': 'updated courier config',
  'pricing_config.updated': 'updated pricing config',
  'notification_setting.updated': 'updated a notification setting',
  'notification_cadence.updated': 'updated notification cadence',
};

/** Relative time via Intl; empty string for a null timestamp. */
export function timeAgo(iso: string | null, now: Date = new Date()): string {
  if (!iso) return '';
  const then = new Date(iso).getTime();
  const diffSec = Math.round((then - now.getTime()) / 1000);
  const abs = Math.abs(diffSec);
  const rtf = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });
  const units: [Intl.RelativeTimeFormatUnit, number][] = [
    ['year', 31536000], ['month', 2592000], ['day', 86400],
    ['hour', 3600], ['minute', 60], ['second', 1],
  ];
  for (const [unit, secs] of units) {
    if (abs >= secs || unit === 'second') {
      return rtf.format(Math.round(diffSec / secs), unit);
    }
  }
  return '';
}

export interface HumanizedActivity {
  category: ActivityCategory;
  text: string;
  when: string;
  title: string;
}

export function humanizeActivity(a: DashboardActivity, now: Date = new Date()): HumanizedActivity {
  const actor = a.actor ?? 'System';
  const verb = VERBS[a.event] ?? a.event.replace(/[._]/g, ' ');
  return {
    category: activityCategory(a.event),
    text: `${actor} ${verb} ${a.auditableLabel}`.trim(),
    when: timeAgo(a.at, now),
    title: a.at ? new Date(a.at).toLocaleString() : '',
  };
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd frontend && npx vitest run src/lib/activityHumanize.test.ts`
Expected: PASS (all cases).

- [ ] **Step 5: Typecheck**

Run: `cd frontend && npm run typecheck`
Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/lib/activityHumanize.ts frontend/src/lib/activityHumanize.test.ts
git commit -m "feat(dashboard): add activity humanizer util"
```

---

## Task 2: Category icons + render the humanized feed

**Files:**
- Modify: `frontend/src/components/icons.tsx`
- Modify: `frontend/src/pages/DashboardPage.tsx:102-119`
- Test: `frontend/src/pages/DashboardActivity.render.test.tsx` (create)

- [ ] **Step 1: Add the category icons**

Append to `frontend/src/components/icons.tsx` (match the existing pattern: `viewBox="0 0 20 20"`, `className` prop default `'h-4 w-4'`, `stroke="currentColor"`, `aria-hidden`):

```tsx
export function OrderIcon({ className = 'h-4 w-4' }: { className?: string }) {
  return (
    <svg viewBox="0 0 20 20" className={className} fill="none" aria-hidden="true">
      <path d="M4 6h12l-1 9H5L4 6Z" stroke="currentColor" strokeWidth="1.5" strokeLinejoin="round" />
      <path d="M7 6a3 3 0 0 1 6 0" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
    </svg>
  );
}

export function CatalogueIcon({ className = 'h-4 w-4' }: { className?: string }) {
  return (
    <svg viewBox="0 0 20 20" className={className} fill="none" aria-hidden="true">
      <path d="M4 4h9l3 3v9H4V4Z" stroke="currentColor" strokeWidth="1.5" strokeLinejoin="round" />
      <path d="M7 9h6M7 12h6" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
    </svg>
  );
}

export function UserIcon({ className = 'h-4 w-4' }: { className?: string }) {
  return (
    <svg viewBox="0 0 20 20" className={className} fill="none" aria-hidden="true">
      <circle cx="10" cy="7" r="3" stroke="currentColor" strokeWidth="1.5" />
      <path d="M4.5 16a5.5 5.5 0 0 1 11 0" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
    </svg>
  );
}

export function ProductionIcon({ className = 'h-4 w-4' }: { className?: string }) {
  return (
    <svg viewBox="0 0 20 20" className={className} fill="none" aria-hidden="true">
      <path d="M4 16V8l4 3V8l4 3V8l4 3v5H4Z" stroke="currentColor" strokeWidth="1.5" strokeLinejoin="round" />
    </svg>
  );
}

export function SystemIcon({ className = 'h-4 w-4' }: { className?: string }) {
  return (
    <svg viewBox="0 0 20 20" className={className} fill="none" aria-hidden="true">
      <circle cx="10" cy="10" r="2.5" stroke="currentColor" strokeWidth="1.5" />
      <path d="M10 3v2M10 15v2M3 10h2M15 10h2M5 5l1.5 1.5M13.5 13.5 15 15M15 5l-1.5 1.5M6.5 13.5 5 15" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
    </svg>
  );
}
```

- [ ] **Step 2: Write the failing render test**

Create `frontend/src/pages/DashboardActivity.render.test.tsx`:

```tsx
import { afterEach, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';

const load = vi.fn();
vi.mock('../stores/dashboardStore', () => ({
  useDashboardStore: () => ({
    data: {
      queues: { proofsPending: 0, cataloguePending: 0, unpaidDelivered: 0 },
      production: { overdue: 0, wip: 0, byState: {} },
      pipeline: {},
      atRisk: [],
      valueBooked: null,
      activity: [
        { id: 1, actor: 'Jane', event: 'quote.amended', auditableType: 'Quote', auditableId: 1, auditableLabel: 'Order 9BWVKW', at: '2026-08-07T10:00:00Z' },
        { id: 2, actor: null, event: 'weird.new_thing', auditableType: 'Product', auditableId: 5, auditableLabel: 'Product #5', at: '2026-08-07T09:00:00Z' },
      ],
    },
    loading: false,
    error: null,
    load,
  }),
}));

import { ThemeProvider } from '../ui';
import DashboardPage from './DashboardPage';

afterEach(cleanup);

function renderPage() {
  return render(
    <ThemeProvider>
      <MemoryRouter>
        <DashboardPage />
      </MemoryRouter>
    </ThemeProvider>,
  );
}

it('renders humanized activity rows, not raw event tokens', () => {
  renderPage();
  expect(screen.getByText('Jane amended Order 9BWVKW')).toBeTruthy();
  // Unknown event: readable fallback, actor falls back to System, no raw token.
  expect(screen.getByText('System weird new thing Product #5')).toBeTruthy();
  expect(screen.queryByText(/weird\.new_thing/)).toBeNull();
});
```

- [ ] **Step 3: Run it to verify it fails**

Run: `cd frontend && npx vitest run src/pages/DashboardActivity.render.test.tsx`
Expected: FAIL — the page currently renders `Jane · quote.amended (Order 9BWVKW)`, so the humanized strings are not found.

- [ ] **Step 4: Render the humanized feed in DashboardPage**

In `frontend/src/pages/DashboardPage.tsx`:

a) Add imports after the existing imports (lines 1-5):

```tsx
import { humanizeActivity, type ActivityCategory } from '../lib/activityHumanize';
import { OrderIcon, CatalogueIcon, UserIcon, ProductionIcon, SystemIcon } from '../components/icons';
```

b) Add a category→icon map and a small row component above `DashboardPage` (after the existing `StatTile`, around line 19):

```tsx
const CATEGORY_ICON: Record<ActivityCategory, (p: { className?: string }) => JSX.Element> = {
  order: OrderIcon,
  catalogue: CatalogueIcon,
  user: UserIcon,
  production: ProductionIcon,
  system: SystemIcon,
};

function ActivityRow({ activity }: { activity: import('../lib/dashboard').DashboardActivity }) {
  const h = humanizeActivity(activity);
  const Icon = CATEGORY_ICON[h.category];
  return (
    <div className="flex items-center gap-3 p-3 text-sm">
      <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-surface-2 text-fg-muted">
        <Icon className="h-4 w-4" />
      </span>
      <span className="min-w-0 flex-1 truncate text-fg">{h.text}</span>
      <span className="shrink-0 text-fg-subtle" title={h.title}>{h.when}</span>
    </div>
  );
}
```

c) Replace the Recent-activity `<Card>` body (currently lines 104-118, the `data.activity.length === 0 ? … : data.activity.map(...)`) with:

```tsx
        <Card padding="none" className="divide-y divide-border">
          {data.activity.length === 0 ? (
            <p className="p-4 text-sm text-fg-muted">No recent activity.</p>
          ) : (
            data.activity.map((a) => <ActivityRow key={a.id} activity={a} />)
          )}
        </Card>
```

- [ ] **Step 5: Run the render test to verify it passes**

Run: `cd frontend && npx vitest run src/pages/DashboardActivity.render.test.tsx`
Expected: PASS.

- [ ] **Step 6: Typecheck + existing dashboard test**

Run: `cd frontend && npm run typecheck`
Expected: no errors. (If `JSX.Element` is flagged in the icon-map type, import type is global with the React 18 setup here — if the project's tsconfig complains, change the map value type to `React.ComponentType<{ className?: string }>` and add `import type React from 'react'`.)

Run: `cd frontend && npx vitest run src/pages/DashboardPage.test.tsx`
Expected: PASS (or, if the old test asserted the raw `actor · event` text, update that assertion to the humanized text — report if you change it).

- [ ] **Step 7: Commit**

```bash
git add frontend/src/components/icons.tsx frontend/src/pages/DashboardPage.tsx frontend/src/pages/DashboardActivity.render.test.tsx
git commit -m "feat(dashboard): humanize the recent-activity feed"
```

---

## Self-Review

- **Spec coverage (Piece C):** humanizer maps known events to plain sentences with actor + label (Task 1); relative time + absolute title (Task 1); category icon per row (Task 2); unknown events fall back to a readable phrase, never a raw dotted token (tested in both tasks); actionable feed explicitly out of scope. Covered.
- **Placeholders:** none — full code for the util, icons, and page wiring; exact commands with expected pass/fail.
- **Type consistency:** `ActivityCategory` defined in Task 1 and imported in Task 2; `humanizeActivity` returns `{ category, text, when, title }` used verbatim by `ActivityRow`; `CATEGORY_ICON` keys are exactly the five `ActivityCategory` values; icon component names match those added to `icons.tsx`.
- **Fallout:** the existing `DashboardPage.test.tsx` may assert the old raw-text row; Task 2 Step 6 runs it and instructs updating that assertion if so.
