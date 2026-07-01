import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useCartStore } from '../stores/cartStore';
import { useAuthStore } from '../stores/authStore';
import { useQuoteStore } from '../stores/quoteStore';
import { EmptyState, ErrorState } from '../components/ui/States';

export default function CartPage() {
  const { lines, estimate, estimating, estimateError, updateQty, removeLine, refreshEstimate, clear } =
    useCartStore();
  const user = useAuthStore((s) => s.user);
  const createQuote = useQuoteStore((s) => s.createQuote);
  const navigate = useNavigate();
  const [submitting, setSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState<string | null>(null);

  // Live estimate is event-driven (debounced on cart change) — never polled.
  useEffect(() => {
    const t = setTimeout(() => void refreshEstimate(), 400);
    return () => clearTimeout(t);
  }, [lines, refreshEstimate]);

  const requestQuote = async () => {
    if (!user) {
      navigate('/login', { state: { from: '/cart' } });
      return;
    }
    if (user.company_id === null) {
      setSubmitError('Your account is not linked to a company. Contact your administrator.');
      return;
    }
    setSubmitting(true);
    setSubmitError(null);
    const quote = await createQuote(user.company_id, lines, null);
    setSubmitting(false);
    if (quote) {
      clear();
      navigate(`/quotes/${quote.id}`);
    } else {
      setSubmitError('Could not create the quote. Please review your cart and try again.');
    }
  };

  if (lines.length === 0) {
    return (
      <EmptyState title="Your cart is empty.">
        <p className="muted">Browse the catalogue and customise a product to get started.</p>
      </EmptyState>
    );
  }

  return (
    <section className="cart">
      <h1>Your cart</h1>

      <table className="table">
        <thead>
          <tr>
            <th>Product</th>
            <th>Options</th>
            <th>Customisation</th>
            <th>Qty</th>
            <th />
          </tr>
        </thead>
        <tbody>
          {lines.map((l) => (
            <tr key={l.key}>
              <td>{l.product.name}</td>
              <td>{l.variant ? Object.values(l.variant.attributes).join(' / ') : '—'}</td>
              <td>
                {l.customization.logo_size ? `Logo ${l.customization.logo_size}` : ''}
                {l.customization.name_text ? ` “${l.customization.name_text}”` : ''}
                {!l.customization.logo_size && !l.customization.name_text ? 'Blank' : ''}
              </td>
              <td>
                <input
                  type="number"
                  min={1}
                  value={l.qty}
                  onChange={(e) => updateQty(l.key, Number(e.target.value))}
                  className="qty-input"
                />
              </td>
              <td>
                <button type="button" className="btn btn--ghost" onClick={() => removeLine(l.key)}>
                  Remove
                </button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      <div className="cart__summary">
        <h3>Estimate</h3>
        {estimateError ? (
          <ErrorState message={estimateError} onRetry={refreshEstimate} />
        ) : estimating ? (
          <p className="muted">Recalculating…</p>
        ) : estimate ? (
          <ul className="totals">
            <li>
              <span>Subtotal</span>
              <span>
                {estimate.currency} {estimate.subtotal.toFixed(2)}
              </span>
            </li>
            <li>
              <span>Delivery</span>
              <span>
                {estimate.currency} {estimate.delivery.toFixed(2)}
              </span>
            </li>
            <li className="totals__grand">
              <span>Estimated total</span>
              <span>
                {estimate.currency} {estimate.total.toFixed(2)}
              </span>
            </li>
          </ul>
        ) : (
          <p className="muted">Add items to see an estimate.</p>
        )}
        <p className="fineprint">Estimate only. Final pricing is confirmed on your formal quote.</p>

        {submitError && <ErrorState message={submitError} />}

        <button type="button" className="btn btn--primary" onClick={requestQuote} disabled={submitting}>
          {submitting ? 'Requesting…' : 'Request a quote'}
        </button>
      </div>
    </section>
  );
}
