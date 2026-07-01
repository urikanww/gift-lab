import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { useQuoteStore } from '../stores/quoteStore';
import { useAuthStore } from '../stores/authStore';
import { AsyncBoundary } from '../components/ui/States';
import { safeHref } from '../lib/safeHref';
import type { Proof } from '../types';

export default function QuoteDetailPage() {
  const { id } = useParams();
  const quoteId = Number(id);
  const { current, loading, error, fetchQuote, send, accept, procure, issueProof, decideProof, issuePurchaseOrder } =
    useQuoteStore();
  const user = useAuthStore((s) => s.user);
  const isStaff = user?.role !== 'buyer';

  const [artworkRef, setArtworkRef] = useState('');
  const [poRef, setPoRef] = useState('');
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    void fetchQuote(quoteId);
  }, [quoteId, fetchQuote]);

  const run = async (fn: () => Promise<void>) => {
    setBusy(true);
    try {
      await fn();
    } finally {
      setBusy(false);
    }
  };

  const latestOpenProof = (proofs: Proof[] | undefined): Proof | null =>
    proofs?.find((p) => p.state === 'SENT') ?? null;

  return (
    <AsyncBoundary
      loading={loading && !current}
      error={error}
      isEmpty={!current}
      emptyTitle="Quote not found."
      onRetry={() => fetchQuote(quoteId)}
    >
      {current && (
        <section className="quote-detail">
          <header className="quote-detail__head">
            <h1>Quote #{current.id}</h1>
            <span className={`badge badge--${current.state.toLowerCase()}`}>{current.state}</span>
          </header>

          <table className="table">
            <thead>
              <tr>
                <th>Item</th>
                <th>Qty</th>
                <th>Unit</th>
                <th>Line</th>
                <th>State</th>
              </tr>
            </thead>
            <tbody>
              {current.line_items?.map((li) => (
                <tr key={li.id}>
                  <td>{li.product?.name ?? `Product #${li.product_id}`}</td>
                  <td>{li.qty}</td>
                  <td>
                    {li.currency} {li.unit_price}
                  </td>
                  <td>
                    {li.currency} {li.line_total}
                  </td>
                  <td>
                    <span className={`badge badge--${li.line_state.toLowerCase()}`}>{li.line_state}</span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>

          <ul className="totals">
            <li>
              <span>Subtotal</span>
              <span>
                {current.currency} {current.subtotal}
              </span>
            </li>
            <li>
              <span>Delivery</span>
              <span>
                {current.currency} {current.delivery}
              </span>
            </li>
            <li className="totals__grand">
              <span>Total</span>
              <span>
                {current.currency} {current.total}
              </span>
            </li>
          </ul>

          <section className="proofs">
            <h2>Proofs</h2>
            {current.proofs && current.proofs.length > 0 ? (
              <ul className="proof-list">
                {current.proofs.map((p) => (
                  <li key={p.id}>
                    <span>
                      v{p.version} — <span className={`badge badge--${p.state.toLowerCase()}`}>{p.state}</span>
                    </span>
                    {safeHref(p.artwork_version_ref) ? (
                      <a href={safeHref(p.artwork_version_ref)} target="_blank" rel="noreferrer">
                        View artwork
                      </a>
                    ) : (
                      <span className="muted">{p.artwork_version_ref}</span>
                    )}
                  </li>
                ))}
              </ul>
            ) : (
              <p className="muted">No proofs issued yet.</p>
            )}

            {/* Buyer sign-off on the open proof (gate 1). */}
            {!isStaff && latestOpenProof(current.proofs) && (
              <div className="actions">
                <button
                  type="button"
                  className="btn btn--primary"
                  disabled={busy}
                  onClick={() => run(() => decideProof(latestOpenProof(current.proofs)!.id, 'approve', null))}
                >
                  Approve proof
                </button>
                <button
                  type="button"
                  className="btn"
                  disabled={busy}
                  onClick={() =>
                    run(() => decideProof(latestOpenProof(current.proofs)!.id, 'request_changes', 'Please revise.'))
                  }
                >
                  Request changes
                </button>
              </div>
            )}
          </section>

          {/* Buyer action: accept a sent quote. */}
          {!isStaff && current.state === 'SENT' && (
            <div className="actions">
              <button type="button" className="btn btn--primary" disabled={busy} onClick={() => run(() => accept(current.id))}>
                Accept quote
              </button>
            </div>
          )}

          {/* Staff workflow controls. */}
          {isStaff && (
            <section className="staff-actions">
              <h2>Staff actions</h2>

              {current.state === 'DRAFT' && (
                <button type="button" className="btn btn--primary" disabled={busy} onClick={() => run(() => send(current.id))}>
                  Send to buyer
                </button>
              )}

              {(current.state === 'ACCEPTED' || current.state === 'PROOFING') && (
                <div className="inline-form">
                  <input
                    type="text"
                    placeholder="Artwork ref (object-store key)"
                    value={artworkRef}
                    onChange={(e) => setArtworkRef(e.target.value)}
                  />
                  <button
                    type="button"
                    className="btn btn--primary"
                    disabled={busy || !artworkRef}
                    onClick={() => run(async () => {
                      await issueProof(current.id, artworkRef, null);
                      setArtworkRef('');
                    })}
                  >
                    Issue proof
                  </button>
                </div>
              )}

              {current.state === 'PROOF_APPROVED' && (
                <div className="inline-form">
                  <input
                    type="text"
                    placeholder="PO reference"
                    value={poRef}
                    onChange={(e) => setPoRef(e.target.value)}
                  />
                  <button
                    type="button"
                    className="btn btn--primary"
                    disabled={busy || !poRef}
                    onClick={() => run(async () => {
                      await issuePurchaseOrder(current.id, poRef, null);
                      setPoRef('');
                    })}
                  >
                    Issue PO
                  </button>
                </div>
              )}

              {current.state === 'CONFIRMED' && (
                <button type="button" className="btn btn--primary" disabled={busy} onClick={() => run(() => procure(current.id))}>
                  Run procurement
                </button>
              )}
            </section>
          )}
        </section>
      )}
    </AsyncBoundary>
  );
}
