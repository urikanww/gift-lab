import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { motion } from 'framer-motion';
import { useQuoteStore } from '../stores/quoteStore';
import { useAuthStore } from '../stores/authStore';
import { isStaffRole } from '../lib/roles';
import { Badge, Button, Card, EmptyState, Skeleton } from '../ui';
import { ErrorState } from '../components/ui/States';
import Breadcrumb from '../components/Breadcrumb';
import {
  Motion,
  fadeInUp,
  staggerContainer,
  staggerItem,
  useReducedMotionSafe,
} from '../motion';
import { humanizeState, quoteStateTone } from '../lib/quoteStatus';
import type { Quote } from '../types';

function formatDate(iso: string | null): string {
  return iso ? new Date(iso).toLocaleDateString() : '-';
}

export default function QuoteListPage() {
  const { quotes, loading, error, page, lastPage, fetchQuotes } = useQuoteStore();
  const navigate = useNavigate();
  const shouldAnimate = useReducedMotionSafe();
  const staff = isStaffRole(useAuthStore((s) => s.user?.role));

  const [term, setTerm] = useState('');

  // Also the mount fetch: the empty initial term means the first run asks for
  // an unfiltered page 1. Keep it as one effect - a separate mount fetch
  // alongside this would fire two requests on every mount.
  useEffect(() => {
    const id = setTimeout(() => void fetchQuotes(1, term.trim() || undefined), 300);
    return () => clearTimeout(id);
  }, [term, fetchQuotes]);

  return (
    <section aria-labelledby="quotes-heading">
      {/* Buyers-only: staff reach this from the console, where "My account" is
          not their path and the crumb would point somewhere they never came from. */}
      {!staff && (
        <Breadcrumb
          items={[
            { label: 'Home', to: '/' },
            { label: 'My account', to: '/account' },
            { label: 'My Orders' },
          ]}
        />
      )}

      <Motion variants={fadeInUp} initial="hidden" animate="visible" className="mb-6 mt-4">
        <h1 id="quotes-heading" className="font-display text-3xl text-fg">
          {/* Buyers reach this page via the "My Orders" nav item - keep the
              title consistent with that entry point. Staff keep "Quotes". */}
          {staff ? 'Quotes' : 'My Orders'}
        </h1>
        <p className="mt-1 text-sm text-fg-muted">
          {staff
            ? 'All customer quotes, newest first - across every company.'
            : 'Track your gift orders from request through production.'}
        </p>
      </Motion>

      {/* Outside the loading/empty branches below: a search that matches nothing
          must keep its own box on screen so the user can clear or amend the term. */}
      <label className="mb-4 block">
        <span className="sr-only">Search orders</span>
        <input
          type="search"
          value={term}
          onChange={(e) => setTerm(e.target.value)}
          placeholder="Search by order reference or id"
          className="w-full max-w-sm rounded-md border border-border bg-surface px-3 py-2 text-sm text-fg placeholder:text-fg-subtle focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        />
      </label>

      {loading ? (
        <QuoteListSkeleton />
      ) : error ? (
        <ErrorState message={error} onRetry={() => fetchQuotes(page)} />
      ) : quotes.length === 0 ? (
        <EmptyState
          title="No quotes yet"
          description={
            staff
              ? 'Customer quote requests will appear here as they come in.'
              : 'Once you request a quote from your cart, it will appear here.'
          }
          action={
            staff ? undefined : (
              <Button variant="primary" onClick={() => navigate('/products')}>
                Browse catalogue
              </Button>
            )
          }
        />
      ) : (
        <>
          {/* Desktop: table. Mobile: stacked cards. */}
          <div className="hidden md:block">
            <Card padding="none" className="overflow-hidden">
              <table className="w-full text-left text-sm">
                <thead>
                  <tr className="border-b border-border text-xs uppercase tracking-wide text-fg-subtle">
                    <th scope="col" className="px-5 py-3 font-medium">
                      Quote
                    </th>
                    {staff && (
                      <th scope="col" className="px-5 py-3 font-medium">
                        Company
                      </th>
                    )}
                    <th scope="col" className="px-5 py-3 font-medium">
                      Status
                    </th>
                    <th scope="col" className="px-5 py-3 text-right font-medium">
                      Total
                    </th>
                    <th scope="col" className="px-5 py-3 font-medium">
                      Created
                    </th>
                  </tr>
                </thead>
                <motion.tbody
                  variants={shouldAnimate ? staggerContainer : undefined}
                  initial={shouldAnimate ? 'hidden' : false}
                  animate="visible"
                >
                  {quotes.map((q) => (
                    <QuoteRow key={q.id} quote={q} animate={shouldAnimate} showCompany={staff} />
                  ))}
                </motion.tbody>
              </table>
            </Card>
          </div>

          <Motion
            variants={staggerContainer}
            initial="hidden"
            animate="visible"
            className="flex flex-col gap-3 md:hidden"
          >
            {quotes.map((q) => (
              <QuoteCard key={q.id} quote={q} showCompany={staff} />
            ))}
          </Motion>

          {lastPage > 1 && (
            <nav className="mt-6 flex items-center justify-between gap-4" aria-label="Pagination">
              <Button
                variant="outline"
                size="sm"
                disabled={loading || page <= 1}
                onClick={() => void fetchQuotes(page - 1)}
              >
                Previous
              </Button>
              <span className="text-sm text-fg-muted" aria-live="polite">
                Page {page} of {lastPage}
              </span>
              <Button
                variant="outline"
                size="sm"
                disabled={loading || page >= lastPage}
                onClick={() => void fetchQuotes(page + 1)}
              >
                Next
              </Button>
            </nav>
          )}
        </>
      )}
    </section>
  );
}

function QuoteRow({
  quote,
  animate,
  showCompany,
}: {
  quote: Quote;
  animate: boolean;
  showCompany: boolean;
}) {
  const navigate = useNavigate();
  return (
    <motion.tr
      variants={animate ? staggerItem : undefined}
      className="cursor-pointer border-b border-border last:border-0 transition-colors duration-fast ease-standard hover:bg-surface-2"
      onClick={() => navigate(`/orders/${quote.reference}`)}
    >
      <td className="px-5 py-4">
        <Link
          to={`/orders/${quote.reference}`}
          className="font-medium text-fg hover:text-primary focus-visible:outline-none focus-visible:underline"
          onClick={(e) => e.stopPropagation()}
        >
          Quote #{quote.id}
        </Link>
      </td>
      {showCompany && (
        <td className="px-5 py-4 text-fg-muted">
          {quote.company_name ?? `Company #${quote.company_id}`}
        </td>
      )}
      <td className="px-5 py-4">
        <Badge tone={quoteStateTone(quote.state)} dot>
          {humanizeState(quote.state)}
        </Badge>
      </td>
      <td className="px-5 py-4 text-right tabular-nums text-fg">
        {quote.currency} {quote.total}
      </td>
      <td className="px-5 py-4 text-fg-muted">{formatDate(quote.created_at)}</td>
    </motion.tr>
  );
}

function QuoteCard({ quote, showCompany }: { quote: Quote; showCompany: boolean }) {
  const navigate = useNavigate();
  return (
    <Motion variants={staggerItem}>
      <Card interactive padding="md" onClick={() => navigate(`/orders/${quote.reference}`)}>
        <div className="flex items-start justify-between gap-3">
          <div>
            <Link
              to={`/orders/${quote.reference}`}
              className="font-display text-lg text-fg focus-visible:outline-none focus-visible:underline"
              onClick={(e) => e.stopPropagation()}
            >
              Quote #{quote.id}
            </Link>
            <p className="mt-0.5 text-xs text-fg-muted">{formatDate(quote.created_at)}</p>
            {showCompany && (
              <p className="mt-0.5 text-xs text-fg-muted">
                {quote.company_name ?? `Company #${quote.company_id}`}
              </p>
            )}
          </div>
          <Badge tone={quoteStateTone(quote.state)} dot>
            {humanizeState(quote.state)}
          </Badge>
        </div>
        <p className="mt-3 font-medium tabular-nums text-fg">
          {quote.currency} {quote.total}
        </p>
      </Card>
    </Motion>
  );
}

function QuoteListSkeleton() {
  return (
    <div className="flex flex-col gap-3" aria-hidden="true">
      {Array.from({ length: 4 }).map((_, i) => (
        <Card key={i} padding="md">
          <div className="flex items-center justify-between gap-4">
            <Skeleton width="8rem" height="1.25rem" />
            <Skeleton width="5rem" height="1.5rem" />
          </div>
          <Skeleton className="mt-3" width="6rem" height="1rem" />
        </Card>
      ))}
    </div>
  );
}
