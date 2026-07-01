import { useEffect } from 'react';
import { Link } from 'react-router-dom';
import { useQuoteStore } from '../stores/quoteStore';
import { AsyncBoundary } from '../components/ui/States';

export default function QuoteListPage() {
  const { quotes, loading, error, fetchQuotes } = useQuoteStore();

  useEffect(() => {
    void fetchQuotes();
  }, [fetchQuotes]);

  return (
    <section>
      <h1>Quotes</h1>
      <AsyncBoundary
        loading={loading}
        error={error}
        isEmpty={quotes.length === 0}
        emptyTitle="No quotes yet."
        onRetry={fetchQuotes}
      >
        <table className="table">
          <thead>
            <tr>
              <th>#</th>
              <th>State</th>
              <th>Total</th>
              <th>Created</th>
            </tr>
          </thead>
          <tbody>
            {quotes.map((q) => (
              <tr key={q.id}>
                <td>
                  <Link to={`/quotes/${q.id}`}>Quote #{q.id}</Link>
                </td>
                <td>
                  <span className={`badge badge--${q.state.toLowerCase()}`}>{q.state}</span>
                </td>
                <td>
                  {q.currency} {q.total}
                </td>
                <td>{q.created_at ? new Date(q.created_at).toLocaleDateString() : '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </AsyncBoundary>
    </section>
  );
}
