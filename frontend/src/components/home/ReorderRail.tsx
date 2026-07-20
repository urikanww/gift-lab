import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { fetchRecentQuotes } from '../../lib/quotes';
import type { Quote } from '../../types';

const MAX_QUOTES = 3;

/**
 * Past-quote shortcuts for signed-in buyers - bulk B2B reordering is history-
 * driven, so a returning buyer's fastest path is their own last order.
 * Optional and silent: renders null on empty OR error. `fetchRecentQuotes`
 * already swallows failures; the catch here covers an unexpected throw so this
 * rail can never take the shelf down with it.
 */
export default function ReorderRail() {
  const [quotes, setQuotes] = useState<Quote[]>([]);

  useEffect(() => {
    let active = true;
    fetchRecentQuotes(MAX_QUOTES)
      .then((q) => {
        if (active) setQuotes(q);
      })
      .catch(() => {
        if (active) setQuotes([]);
      });
    return () => {
      active = false;
    };
  }, []);

  if (quotes.length === 0) return null;

  return (
    <section aria-labelledby="home-reorder">
      <div className="flex items-end justify-between gap-4">
        <h2 id="home-reorder" className="font-display text-xl text-fg sm:text-2xl">
          Reorder from a past quote
        </h2>
        <Link
          to="/quotes"
          className="inline-flex min-h-[44px] items-center text-sm font-medium text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        >
          View all
        </Link>
      </div>
      {/* A rail, not a grid: with up to MAX_QUOTES cards a 3-column track leaves
          a lone quote stranded beside two empty columns. Fixed-width cards that
          overflow into a scroll read as deliberate at any count. */}
      <ul className="mt-4 flex gap-3 overflow-x-auto pb-1">
        {quotes.map((q) => (
          <li key={q.id} className="w-56 shrink-0">
            <Link
              to={`/orders/${q.reference}`}
              aria-label={`Quote #${q.id}`}
              className="flex min-h-[44px] flex-col gap-1 rounded-xl border border-border bg-surface p-4 shadow-card transition-colors hover:border-primary/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
              <span className="font-display text-sm text-fg">Quote #{q.id}</span>
              <span className="text-xs text-fg-muted">
                {q.currency} {q.total}
              </span>
            </Link>
          </li>
        ))}
      </ul>
    </section>
  );
}
