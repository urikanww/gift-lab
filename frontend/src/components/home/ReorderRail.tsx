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
      <ul className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
        {quotes.map((q) => (
          <li key={q.id}>
            <Link
              to={`/quotes/${q.id}`}
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
