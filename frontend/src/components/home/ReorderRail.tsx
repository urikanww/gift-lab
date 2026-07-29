import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { fetchRecentQuotes } from '../../lib/quotes';
import { useQuoteStore } from '../../stores/quoteStore';
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
  // The id of the card currently reordering, so only THAT card's button
  // disables - a slow reorder on one card must not freeze the other two.
  const [reorderingId, setReorderingId] = useState<number | null>(null);
  const reorderQuote = useQuoteStore((s) => s.reorderQuote);
  const navigate = useNavigate();

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

  const handleReorder = async (sourceId: number) => {
    setReorderingId(sourceId);
    const created = await reorderQuote(sourceId);
    setReorderingId(null);
    if (created) navigate(`/orders/${created.reference}`);
  };

  if (quotes.length === 0) return null;

  return (
    <section aria-labelledby="home-reorder">
      <div className="flex items-end justify-between gap-4">
        <h2 id="home-reorder" className="font-display text-lg text-fg sm:text-xl">
          Reorder from a past quote
        </h2>
        {/* 44px tap target on touch, 24px on pointer (WCAG 2.5.8 AA) - see the
            same trade-off on HomePage's rail headers. */}
        <Link
          to="/quotes"
          className="inline-flex min-h-[44px] items-center text-sm font-medium text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring sm:min-h-[24px]"
        >
          View all
        </Link>
      </div>
      {/* A rail, not a grid: with up to MAX_QUOTES cards a 3-column track leaves
          a lone quote stranded beside two empty columns. Fixed-width cards that
          overflow into a scroll read as deliberate at any count. */}
      <ul className="mt-3 flex gap-3 overflow-x-auto pb-1">
        {quotes.map((q) => (
          <li key={q.id} className="w-56 shrink-0">
            {/* Card content and the Reorder action are siblings, not nested -
                a <button> inside this <Link>'s <a> would be invalid HTML and
                would swallow the button's clicks as a navigation instead. */}
            <div className="flex flex-col gap-1 rounded-xl border border-border bg-surface p-4 shadow-card transition-colors hover:border-primary/50">
              <Link
                to={`/orders/${q.reference}`}
                aria-label={`Order ${q.reference}`}
                className="flex min-h-[44px] flex-col gap-1 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              >
                {q.items_preview && q.items_preview.length > 0 && (
                  <div className="mb-1 flex items-center gap-2">
                    <div className="flex -space-x-2">
                      {q.items_preview.slice(0, 3).map((it, i) =>
                        it.image_url ? (
                          <img
                            key={i}
                            src={it.image_url}
                            alt=""
                            className="h-9 w-9 rounded-md border border-border bg-surface-2 object-cover"
                          />
                        ) : (
                          <div
                            key={i}
                            aria-hidden="true"
                            className="h-9 w-9 rounded-md border border-border bg-surface-2"
                          />
                        ),
                      )}
                    </div>
                    {q.items_preview.length > 3 && (
                      <span className="text-xs text-fg-muted">+{q.items_preview.length - 3}</span>
                    )}
                  </div>
                )}
                <span className="truncate font-display text-sm text-fg">
                  {q.items_preview && q.items_preview.length > 0
                    ? q.items_preview
                        .map((it) => it.name)
                        .filter(Boolean)
                        .slice(0, 2)
                        .join(', ') + (q.items_preview.length > 2 ? '…' : '')
                    : `Order ${q.reference}`}
                </span>
                <span className="text-xs text-fg-muted">
                  Order {q.reference} · {q.currency} {q.total}
                </span>
              </Link>
              <button
                type="button"
                onClick={() => void handleReorder(q.id)}
                disabled={reorderingId === q.id}
                className="mt-1 inline-flex min-h-[44px] items-center justify-center rounded-lg border border-border text-sm font-medium text-primary transition-colors hover:border-primary/50 disabled:cursor-not-allowed disabled:opacity-60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring sm:min-h-[36px]"
              >
                {reorderingId === q.id ? 'Reordering…' : 'Reorder'}
              </button>
            </div>
          </li>
        ))}
      </ul>
    </section>
  );
}
