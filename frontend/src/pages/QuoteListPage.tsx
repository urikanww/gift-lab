import { useEffect, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { motion } from 'framer-motion';
import { useQuoteStore } from '../stores/quoteStore';
import { useAuthStore } from '../stores/authStore';
import { isStaffRole } from '../lib/roles';
import { Badge, Button, Card, EmptyState, Input, Skeleton } from '../ui';
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
import ListFilters, { FilterBadges } from '../components/filters/ListFilters';
import type { FilterValues } from '../components/filters/types';
import {
  quoteFilterFields,
  quoteFiltersToParams,
  deliveredUnpaidSeed,
} from '../lib/quoteListFilters';
import type { Quote } from '../types';

function formatDate(iso: string | null): string {
  return iso ? new Date(iso).toLocaleDateString() : '-';
}

export default function QuoteListPage() {
  const { quotes, loading, error, page, lastPage, fetchQuotes } = useQuoteStore();
  const navigate = useNavigate();
  const shouldAnimate = useReducedMotionSafe();
  const staff = isStaffRole(useAuthStore((s) => s.user?.role));

  // The dashboard "Delivered · unpaid" tile deep-links with ?filter=delivered_unpaid;
  // seed the filter state from it so the list opens pre-scoped and its badges show.
  const [searchParams] = useSearchParams();
  const [filters, setFilters] = useState<FilterValues>(() =>
    searchParams.get('filter') === 'delivered_unpaid' ? deliveredUnpaidSeed() : {},
  );
  const params = quoteFiltersToParams(filters);
  const paramsKey = JSON.stringify(params);

  const [term, setTerm] = useState('');

  // Every fetch here sends this - the debounced search below, and equally the
  // paging and retry handlers. fetchQuotes always writes its `term` argument to
  // the store, so omitting it at those call sites would clear the stored term
  // while the text still sits in the input - store and screen would then
  // disagree, which is the exact failure the term was moved into the store to
  // prevent.
  const activeTerm = term.trim() || undefined;

  // Also the mount fetch: the empty initial term means the first run asks for
  // an unfiltered page 1. Keep it as one effect - a separate mount fetch
  // alongside this would fire two requests on every mount. Always page 1: a new
  // term invalidates the current offset, so a user on page 3 who searches lands
  // on page 1 of the filtered results.
  //
  // Keyed on activeTerm, not the raw text: typing a trailing space leaves the
  // effective term identical, and re-running on that would bounce a user sitting
  // on page 2 of filtered results back to page 1 for a keystroke that changed
  // nothing.
  // Debounced so typing coalesces to one request; re-runs when the applied
  // filters change too (paramsKey), always resetting to page 1. `params` is read
  // from the closure — paramsKey is the value that actually changed.
  useEffect(() => {
    const id = setTimeout(() => void fetchQuotes(1, activeTerm, params), 300);
    return () => clearTimeout(id);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [activeTerm, paramsKey, fetchQuotes]);

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

      {/* Search + filters. The Filters button sits on the same row as the search
          box (bottom-aligned to the input, past its label); active filters wrap
          onto their own line below as removable badges. Both stay on screen
          through empty/loading states so a zero-result filter can still cleared. */}
      <div className="mb-4 flex flex-col gap-3">
        <div className="flex flex-wrap items-end gap-3">
          <div className="w-full max-w-sm">
            <Input
              type="search"
              label="Search orders"
              placeholder="Search by order reference or id"
              value={term}
              onChange={(e) => setTerm(e.target.value)}
            />
          </div>
          <ListFilters fields={quoteFilterFields(staff)} value={filters} onChange={setFilters} />
        </div>
        <FilterBadges fields={quoteFilterFields(staff)} value={filters} onChange={setFilters} />
      </div>

      {/* Focus stays in the input while the list is replaced underneath it, and
          QuoteListSkeleton is aria-hidden, so without this the whole exchange is
          silent to a screen reader (WCAG 4.1.3). Must stay OUTSIDE the ternary
          below: a live region that mounts already holding content is announced
          unreliably. The error arm is deliberately empty - ErrorState carries
          role="alert", and both would announce one event twice. */}
      <span className="sr-only" role="status" aria-live="polite">
        {loading
          ? 'Loading orders…'
          : error
            ? ''
            : `${quotes.length} order${quotes.length === 1 ? '' : 's'}${
                activeTerm ? ` matching "${activeTerm}"` : ''
              }`}
      </span>

      {loading ? (
        <QuoteListSkeleton />
      ) : error ? (
        <ErrorState message={error} onRetry={() => fetchQuotes(page, activeTerm, params)} />
      ) : quotes.length === 0 ? (
        // A search/filter that matches nothing is NOT an empty order history.
        // Telling a buyer with twelve orders that theirs is empty is both false
        // and alarming, so a filtered miss gets its own copy and a way back out.
        activeTerm || Object.keys(params).length > 0 ? (
          <EmptyState
            title={staff ? 'No quotes match those filters' : 'No orders match those filters'}
            description="Nothing matches the current search and filters. Adjust or clear them to see more."
            action={
              <Button
                variant="outline"
                onClick={() => {
                  setTerm('');
                  setFilters({});
                }}
              >
                Clear search &amp; filters
              </Button>
            }
          />
        ) : (
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
        )
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
                      Items
                    </th>
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
                onClick={() => void fetchQuotes(page - 1, activeTerm, params)}
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
                onClick={() => void fetchQuotes(page + 1, activeTerm, params)}
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
          Order {quote.reference}
        </Link>
      </td>
      {showCompany && (
        <td className="px-5 py-4 text-fg-muted">
          {quote.company_name ?? `Company #${quote.company_id}`}
        </td>
      )}
      <td className="px-5 py-4 tabular-nums text-fg-muted">{quote.items_preview?.length ?? '—'}</td>
      <td className="px-5 py-4">
        <Badge tone={quoteStateTone(quote.state)} dot>
          {humanizeState(quote.state)}
        </Badge>
      </td>
      <td className="px-5 py-4 text-right tabular-nums text-fg">
        {quote.currency} {quote.total}
        {/* Only present on the delivered-unpaid filter (which eager-loads the
            invoice); a red owed line makes the actionable amount scannable. */}
        {quote.invoice && quote.invoice.balance_owed > 0 && (
          <span className="block text-xs font-medium text-danger">
            {quote.currency} {quote.invoice.balance_owed.toFixed(2)} owed
          </span>
        )}
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
              Order {quote.reference}
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
        {quote.invoice && quote.invoice.balance_owed > 0 && (
          <p className="mt-0.5 text-xs font-medium text-danger">
            {quote.currency} {quote.invoice.balance_owed.toFixed(2)} owed
          </p>
        )}
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
