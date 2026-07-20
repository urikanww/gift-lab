import { useEffect } from 'react';
import { Link } from 'react-router-dom';
import { useAuthStore } from '../stores/authStore';
import { useQuoteStore } from '../stores/quoteStore';
import { Badge, Card, LinkButton, Skeleton } from '../ui';
import Breadcrumb from '../components/Breadcrumb';
import { Motion, fadeInUp, staggerContainer, staggerItem } from '../motion';
import { humanizeState, quoteStateTone } from '../lib/quoteStatus';
import type { QuoteState } from '../types';

/** What a buyer must do next for an order that's waiting on them. */
const AWAITING_ACTION: Partial<Record<QuoteState, { note: string; cta: string }>> = {
  SENT: { note: 'quote sent, awaiting your OK', cta: 'View quote' },
  PROOFING: { note: 'proof ready to approve', cta: 'Review proof' },
  INVOICED: { note: 'invoice ready', cta: 'Pay invoice' },
};

const QUICK_ACTIONS = [
  { to: '/products', label: 'Browse products', icon: BagIcon },
  { to: '/account/addresses', label: 'Saved addresses', icon: PinIcon },
  { to: '/track', label: 'Track an order', icon: TruckIcon },
];

export default function BuyerDashboardPage() {
  const user = useAuthStore((s) => s.user);
  const { summary, quotes, loading, fetchSummary, fetchQuotes } = useQuoteStore();

  useEffect(() => {
    void fetchSummary();
    void fetchQuotes(1);
  }, [fetchSummary, fetchQuotes]);

  const recent = quotes.slice(0, 4);

  return (
    <section aria-labelledby="dashboard-heading" className="flex flex-col gap-6">
      <Breadcrumb items={[{ label: 'Home', to: '/' }, { label: 'My account' }]} />

      <Motion variants={fadeInUp} initial="hidden" animate="visible">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h1 id="dashboard-heading" className="font-display text-3xl text-fg">
              Welcome back{user?.name ? `, ${user.name.split(' ')[0]}` : ''}
            </h1>
            {user?.company?.name && <p className="mt-1 text-sm text-fg-muted">{user.company.name}</p>}
          </div>
          <LinkButton to="/products" variant="primary">
            Browse products
          </LinkButton>
        </div>
      </Motion>

      {/* Order stat tiles */}
      <Motion
        variants={staggerContainer}
        initial="hidden"
        animate="visible"
        className="grid grid-cols-2 gap-3 sm:grid-cols-4"
      >
        <StatTile label="Active" value={summary?.active} loading={loading && !summary} />
        <StatTile label="Awaiting you" value={summary?.awaiting} loading={loading && !summary} accent />
        <StatTile label="In production" value={summary?.in_production} loading={loading && !summary} />
        <StatTile label="Completed" value={summary?.completed} loading={loading && !summary} />
      </Motion>

      {/* Awaiting-you callout - only when something needs a decision */}
      {summary && summary.awaiting_orders.length > 0 && (
        <Motion variants={fadeInUp} initial="hidden" animate="visible">
          <Card padding="lg" className="bg-accent-50">
            <h2 className="flex items-center gap-2 font-display text-lg text-fg">
              <BellIcon /> Awaiting you
            </h2>
            <ul className="mt-3 flex flex-col divide-y divide-border">
              {summary.awaiting_orders.map((o) => {
                const action = AWAITING_ACTION[o.state];
                return (
                  <li
                    key={o.id}
                    className="flex flex-wrap items-center justify-between gap-2 py-2.5 first:pt-0 last:pb-0"
                  >
                    <span className="text-sm text-fg">
                      Quote #{o.id} ·{' '}
                      <span className="text-fg-muted">{action?.note ?? humanizeState(o.state)}</span>
                    </span>
                    <LinkButton to={`/orders/${o.reference}`} variant="secondary" size="sm">
                      {action?.cta ?? 'View order'}
                    </LinkButton>
                  </li>
                );
              })}
            </ul>
          </Card>
        </Motion>
      )}

      {/* Quick actions */}
      <Motion
        variants={staggerContainer}
        initial="hidden"
        animate="visible"
        className="grid grid-cols-2 gap-3 sm:grid-cols-4"
      >
        {QUICK_ACTIONS.map((a) => (
          <Motion key={a.to} variants={staggerItem}>
            <Link
              to={a.to}
              className="flex h-full flex-col items-center gap-2 rounded-xl border border-border bg-surface-2 p-4 text-center transition-colors hover:border-border-strong hover:bg-surface focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
              <span className="text-primary">
                <a.icon />
              </span>
              <span className="text-xs font-medium text-fg">{a.label}</span>
            </Link>
          </Motion>
        ))}
      </Motion>

      {/* Recent orders */}
      <Card padding="lg" aria-labelledby="recent-heading">
        <div className="flex items-center justify-between">
          <h2 id="recent-heading" className="font-display text-xl text-fg">
            Recent orders
          </h2>
          <Link to="/quotes" className="text-sm font-medium text-primary hover:underline">
            View all orders
          </Link>
        </div>

        {loading && quotes.length === 0 ? (
          <div className="mt-4 flex flex-col gap-3" aria-hidden="true">
            {Array.from({ length: 3 }).map((_, i) => (
              <Skeleton key={i} height="1.5rem" />
            ))}
          </div>
        ) : recent.length === 0 ? (
          <p className="mt-4 text-sm text-fg-muted">
            No orders yet. <Link to="/products" className="text-primary hover:underline">Browse the catalogue</Link>{' '}
            to start your first gift order.
          </p>
        ) : (
          <ul className="mt-4 flex flex-col divide-y divide-border">
            {recent.map((q) => (
              <li key={q.id}>
                <Link
                  to={`/orders/${q.reference}`}
                  className="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0 hover:opacity-80 focus-visible:outline-none focus-visible:underline"
                >
                  <span className="font-medium text-fg">Quote #{q.id}</span>
                  <Badge tone={quoteStateTone(q.state)} dot>
                    {humanizeState(q.state)}
                  </Badge>
                  <span className="tabular-nums text-sm text-fg-muted">
                    {q.currency} {q.total}
                  </span>
                </Link>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </section>
  );
}

function StatTile({
  label,
  value,
  loading,
  accent = false,
}: {
  label: string;
  value: number | undefined;
  loading: boolean;
  accent?: boolean;
}) {
  return (
    <Motion variants={staggerItem}>
      <div className={accent ? 'rounded-xl bg-accent-50 p-4' : 'rounded-xl bg-surface-2 p-4'}>
        <p className={accent ? 'text-sm text-primary' : 'text-sm text-fg-muted'}>{label}</p>
        {loading ? (
          <Skeleton className="mt-1.5" width="2rem" height="1.75rem" />
        ) : (
          <p className={`mt-1 font-display text-2xl ${accent ? 'text-primary' : 'text-fg'}`}>{value ?? 0}</p>
        )}
      </div>
    </Motion>
  );
}

function BellIcon() {
  return (
    <svg viewBox="0 0 20 20" className="h-5 w-5 text-primary" fill="none" aria-hidden="true">
      <path
        d="M10 3a4 4 0 0 0-4 4c0 3-1.5 4.5-1.5 4.5h11S14 10 14 7a4 4 0 0 0-4-4ZM8.5 15a1.5 1.5 0 0 0 3 0"
        stroke="currentColor"
        strokeWidth="1.4"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  );
}

function BagIcon() {
  return (
    <svg viewBox="0 0 24 24" className="h-6 w-6" fill="none" aria-hidden="true">
      <path
        d="M6 7h12l-1 12H7L6 7Zm3 0a3 3 0 0 1 6 0"
        stroke="currentColor"
        strokeWidth="1.5"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  );
}

function PinIcon() {
  return (
    <svg viewBox="0 0 24 24" className="h-6 w-6" fill="none" aria-hidden="true">
      <path
        d="M12 21s6-5.2 6-10a6 6 0 1 0-12 0c0 4.8 6 10 6 10Z"
        stroke="currentColor"
        strokeWidth="1.5"
        strokeLinejoin="round"
      />
      <circle cx="12" cy="11" r="2" stroke="currentColor" strokeWidth="1.5" />
    </svg>
  );
}

function TruckIcon() {
  return (
    <svg viewBox="0 0 24 24" className="h-6 w-6" fill="none" aria-hidden="true">
      <path
        d="M3 6h11v9H3V6Zm11 3h4l3 3v3h-7V9Z"
        stroke="currentColor"
        strokeWidth="1.5"
        strokeLinejoin="round"
      />
      <circle cx="7" cy="17" r="1.6" stroke="currentColor" strokeWidth="1.5" />
      <circle cx="17" cy="17" r="1.6" stroke="currentColor" strokeWidth="1.5" />
    </svg>
  );
}
