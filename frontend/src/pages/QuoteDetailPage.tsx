import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { useQuoteStore } from '../stores/quoteStore';
import { useAuthStore } from '../stores/authStore';
import { Badge, Button, Card, Input, Skeleton, useToast } from '../ui';
import { EmptyState as LegacyEmpty, ErrorState } from '../components/ui/States';
import { Motion, staggerContainer, staggerItem } from '../motion';
import { safeHref } from '../lib/safeHref';
import { isStaffRole } from '../lib/roles';
import { humanizeState, lineStateTone, proofStateTone, quoteStateTone } from '../lib/quoteStatus';
import type { LineItem, Proof, Quote, QuoteState } from '../types';

/** Ordered happy-path lifecycle used to render the status timeline. */
const TIMELINE: QuoteState[] = [
  'DRAFT',
  'SENT',
  'ACCEPTED',
  'PROOFING',
  'PROOF_APPROVED',
  'PO_ISSUED',
  'CONFIRMED',
  'PROCURING',
  'READY',
];

function timelineIndex(state: QuoteState): number {
  const i = TIMELINE.indexOf(state);
  if (i !== -1) return i;
  // Off-path states (CHANGES_REQUESTED, CLOSED, CANCELLED) — treat as end/first.
  if (state === 'CLOSED') return TIMELINE.length - 1;
  return 0;
}

export default function QuoteDetailPage() {
  const { id } = useParams();
  const quoteId = Number(id);
  const {
    current,
    loading,
    error,
    fetchQuote,
    send,
    accept,
    procure,
    issueProof,
    decideProof,
    issuePurchaseOrder,
    payNow,
  } = useQuoteStore();
  const user = useAuthStore((s) => s.user);
  const isStaff = isStaffRole(user?.role);
  const { toast } = useToast();

  const [artworkRef, setArtworkRef] = useState('');
  const [poRef, setPoRef] = useState('');
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    void fetchQuote(quoteId);
  }, [quoteId, fetchQuote]);

  const run = async (fn: () => Promise<void>, successMsg?: string) => {
    setBusy(true);
    try {
      await fn();
      if (successMsg && !useQuoteStore.getState().error) {
        toast({ title: successMsg, tone: 'success' });
      }
    } finally {
      setBusy(false);
    }
  };

  const latestOpenProof = (proofs: Proof[] | undefined): Proof | null =>
    proofs?.find((p) => p.state === 'SENT') ?? null;

  if (loading && !current) return <QuoteDetailSkeleton />;
  if (error) return <ErrorState message={error} onRetry={() => fetchQuote(quoteId)} />;
  if (!current) return <LegacyEmpty title="Quote not found." />;

  const quote = current;

  return (
    <Motion variants={staggerContainer} initial="hidden" animate="visible">
      <section className="flex flex-col gap-6" aria-labelledby="quote-heading">
        {/* Header */}
        <Motion variants={staggerItem}>
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <h1 id="quote-heading" className="font-display text-3xl text-fg">
                Quote #{quote.id}
              </h1>
              {quote.created_at && (
                <p className="mt-1 text-sm text-fg-muted">
                  Created {new Date(quote.created_at).toLocaleDateString()}
                </p>
              )}
              {quote.tracking_code && (
                <p className="mt-1 text-sm text-fg-muted">
                  Tracking code{' '}
                  <span className="font-mono font-semibold text-fg">{quote.tracking_code}</span>
                  <span className="text-fg-subtle"> — share to track without an account at /track</span>
                </p>
              )}
            </div>
            <Badge tone={quoteStateTone(quote.state)} size="md" dot>
              {humanizeState(quote.state)}
            </Badge>
          </div>
        </Motion>

        {/* Status timeline */}
        <Motion variants={staggerItem}>
          <StatusTimeline state={quote.state} />
        </Motion>

        {/* Line items */}
        <Motion variants={staggerItem}>
          <Card padding="none" className="overflow-hidden">
            <div className="border-b border-border px-5 py-4">
              <h2 className="font-display text-xl text-fg">Items</h2>
            </div>
            <LineItemList items={quote.line_items} />
            <PricingSummary quote={quote} />
          </Card>
        </Motion>

        {/* Proofs */}
        <Motion variants={staggerItem}>
          <Card padding="lg" aria-labelledby="proofs-heading">
            <h2 id="proofs-heading" className="font-display text-xl text-fg">
              Proofs
            </h2>
            {quote.proofs && quote.proofs.length > 0 ? (
              <ul className="mt-4 flex flex-col divide-y divide-border">
                {quote.proofs.map((p) => (
                  <li key={p.id} className="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0">
                    <span className="flex items-center gap-3">
                      <span className="font-medium text-fg">v{p.version}</span>
                      <Badge tone={proofStateTone(p.state)} size="sm">
                        {humanizeState(p.state)}
                      </Badge>
                    </span>
                    {safeHref(p.artwork_version_ref) ? (
                      <a
                        href={safeHref(p.artwork_version_ref)}
                        target="_blank"
                        rel="noreferrer"
                        className="text-sm font-medium text-primary hover:underline focus-visible:outline-none focus-visible:underline"
                      >
                        View artwork
                      </a>
                    ) : (
                      <span className="text-sm text-fg-subtle">{p.artwork_version_ref}</span>
                    )}
                  </li>
                ))}
              </ul>
            ) : (
              <p className="mt-3 text-sm text-fg-muted">No proofs issued yet.</p>
            )}

            {/* Buyer sign-off on the open proof (gate 1). */}
            {!isStaff && latestOpenProof(quote.proofs) && (
              <div className="mt-5 flex flex-wrap gap-3">
                <Button
                  variant="primary"
                  loading={busy}
                  disabled={busy}
                  onClick={() =>
                    run(
                      () => decideProof(latestOpenProof(quote.proofs)!.id, 'approve', null),
                      'Proof approved',
                    )
                  }
                >
                  Approve proof
                </Button>
                <Button
                  variant="outline"
                  disabled={busy}
                  onClick={() =>
                    run(
                      () =>
                        decideProof(latestOpenProof(quote.proofs)!.id, 'request_changes', 'Please revise.'),
                      'Changes requested',
                    )
                  }
                >
                  Request changes
                </Button>
              </div>
            )}
          </Card>
        </Motion>

        {/* Buyer actions */}
        {!isStaff && (quote.state === 'SENT' || quote.state === 'PROOF_APPROVED') && (
          <Motion variants={staggerItem}>
            <Card padding="lg">
              <h2 className="font-display text-xl text-fg">Next step</h2>
              {quote.state === 'SENT' && (
                <div className="mt-4">
                  <p className="mb-3 text-sm text-fg-muted">
                    Review the pricing above, then accept to move into proofing.
                  </p>
                  <Button
                    variant="primary"
                    loading={busy}
                    disabled={busy}
                    onClick={() => run(() => accept(quote.id), 'Quote accepted')}
                  >
                    Accept quote
                  </Button>
                </div>
              )}
              {quote.state === 'PROOF_APPROVED' && (
                <div className="mt-4">
                  <p className="mb-3 text-sm text-fg-muted">Your proof is approved. Pay now to confirm production.</p>
                  <Button
                    variant="primary"
                    loading={busy}
                    disabled={busy}
                    onClick={() => run(() => payNow(quote.id))}
                  >
                    Pay now
                  </Button>
                </div>
              )}
            </Card>
          </Motion>
        )}

        {/* Staff workflow controls */}
        {isStaff && (
          <Motion variants={staggerItem}>
            <Card padding="lg" aria-labelledby="staff-heading">
              <h2 id="staff-heading" className="font-display text-xl text-fg">
                Staff actions
              </h2>

              <div className="mt-4">
                {quote.state === 'DRAFT' && (
                  <Button
                    variant="primary"
                    loading={busy}
                    disabled={busy}
                    onClick={() => run(() => send(quote.id), 'Sent to buyer')}
                  >
                    Send to buyer
                  </Button>
                )}

                {(quote.state === 'ACCEPTED' || quote.state === 'PROOFING') && (
                  <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                    <div className="flex-1">
                      <Input
                        label="Artwork reference"
                        placeholder="object-store key"
                        value={artworkRef}
                        onChange={(e) => setArtworkRef(e.target.value)}
                      />
                    </div>
                    <Button
                      variant="primary"
                      loading={busy}
                      disabled={busy || !artworkRef}
                      onClick={() =>
                        run(async () => {
                          await issueProof(quote.id, artworkRef, null);
                          setArtworkRef('');
                        }, 'Proof issued')
                      }
                    >
                      Issue proof
                    </Button>
                  </div>
                )}

                {quote.state === 'PROOF_APPROVED' && (
                  <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                    <div className="flex-1">
                      <Input
                        label="PO reference"
                        placeholder="PO number"
                        value={poRef}
                        onChange={(e) => setPoRef(e.target.value)}
                      />
                    </div>
                    <Button
                      variant="primary"
                      loading={busy}
                      disabled={busy || !poRef}
                      onClick={() =>
                        run(async () => {
                          await issuePurchaseOrder(quote.id, poRef, null);
                          setPoRef('');
                        }, 'Purchase order issued')
                      }
                    >
                      Issue PO
                    </Button>
                  </div>
                )}

                {quote.state === 'CONFIRMED' && (
                  <Button
                    variant="primary"
                    loading={busy}
                    disabled={busy}
                    onClick={() => run(() => procure(quote.id), 'Procurement started')}
                  >
                    Run procurement
                  </Button>
                )}

                {!['DRAFT', 'ACCEPTED', 'PROOFING', 'PROOF_APPROVED', 'CONFIRMED'].includes(quote.state) && (
                  <p className="text-sm text-fg-muted">No staff action available for this state.</p>
                )}
              </div>
            </Card>
          </Motion>
        )}
      </section>
    </Motion>
  );
}

function StatusTimeline({ state }: { state: QuoteState }) {
  const cancelled = state === 'CANCELLED';
  const currentIdx = timelineIndex(state);

  if (cancelled) {
    return (
      <Card padding="md">
        <div className="flex items-center gap-2">
          <Badge tone="danger" dot>
            Cancelled
          </Badge>
          <span className="text-sm text-fg-muted">This quote was cancelled.</span>
        </div>
      </Card>
    );
  }

  return (
    <Card padding="md">
      <ol className="flex flex-wrap items-center gap-x-2 gap-y-3">
        {TIMELINE.map((step, i) => {
          const done = i < currentIdx;
          const active = i === currentIdx;
          return (
            <li key={step} className="flex items-center gap-2">
              <span
                className={
                  'flex h-6 w-6 items-center justify-center rounded-full text-2xs font-semibold ' +
                  (active
                    ? 'bg-primary text-primary-fg'
                    : done
                      ? 'bg-success text-white'
                      : 'bg-surface-2 text-fg-subtle')
                }
                aria-hidden="true"
              >
                {done ? '✓' : i + 1}
              </span>
              <span
                className={
                  'text-xs ' + (active ? 'font-semibold text-fg' : done ? 'text-fg-muted' : 'text-fg-subtle')
                }
              >
                {humanizeState(step)}
                {active && <span className="sr-only"> (current status)</span>}
              </span>
              {i < TIMELINE.length - 1 && <span className="mx-1 h-px w-4 bg-border" aria-hidden="true" />}
            </li>
          );
        })}
      </ol>
    </Card>
  );
}

function LineItemList({ items }: { items: LineItem[] | undefined }) {
  if (!items || items.length === 0) {
    return <p className="px-5 py-6 text-sm text-fg-muted">No items on this quote.</p>;
  }
  return (
    <>
      {/* Desktop table */}
      <table className="hidden w-full text-left text-sm md:table">
        <thead>
          <tr className="border-b border-border text-xs uppercase tracking-wide text-fg-subtle">
            <th scope="col" className="px-5 py-3 font-medium">
              Item
            </th>
            <th scope="col" className="px-5 py-3 text-right font-medium">
              Qty
            </th>
            <th scope="col" className="px-5 py-3 text-right font-medium">
              Unit
            </th>
            <th scope="col" className="px-5 py-3 text-right font-medium">
              Line total
            </th>
            <th scope="col" className="px-5 py-3 font-medium">
              Status
            </th>
          </tr>
        </thead>
        <tbody>
          {items.map((li) => (
            <tr key={li.id} className="border-b border-border last:border-0">
              <td className="px-5 py-4 text-fg">{li.product?.name ?? `Product #${li.product_id}`}</td>
              <td className="px-5 py-4 text-right tabular-nums text-fg">{li.qty}</td>
              <td className="px-5 py-4 text-right tabular-nums text-fg-muted">
                {li.currency} {li.unit_price}
              </td>
              <td className="px-5 py-4 text-right tabular-nums text-fg">
                {li.currency} {li.line_total}
              </td>
              <td className="px-5 py-4">
                <Badge tone={lineStateTone(li.line_state)} size="sm">
                  {humanizeState(li.line_state)}
                </Badge>
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      {/* Mobile stacked */}
      <ul className="flex flex-col divide-y divide-border md:hidden">
        {items.map((li) => (
          <li key={li.id} className="flex flex-col gap-2 px-5 py-4">
            <div className="flex items-start justify-between gap-3">
              <span className="font-medium text-fg">{li.product?.name ?? `Product #${li.product_id}`}</span>
              <Badge tone={lineStateTone(li.line_state)} size="sm">
                {humanizeState(li.line_state)}
              </Badge>
            </div>
            <div className="flex justify-between text-sm text-fg-muted">
              <span>
                {li.qty} × {li.currency} {li.unit_price}
              </span>
              <span className="tabular-nums text-fg">
                {li.currency} {li.line_total}
              </span>
            </div>
          </li>
        ))}
      </ul>
    </>
  );
}

function PricingSummary({ quote }: { quote: Quote }) {
  return (
    <div className="border-t border-border bg-surface-2/50 px-5 py-4">
      <dl className="ml-auto flex max-w-xs flex-col gap-2">
        <div className="flex justify-between text-sm">
          <dt className="text-fg-muted">Subtotal</dt>
          <dd className="tabular-nums text-fg">
            {quote.currency} {quote.subtotal}
          </dd>
        </div>
        <div className="flex justify-between text-sm">
          <dt className="text-fg-muted">Delivery</dt>
          <dd className="tabular-nums text-fg">
            {quote.currency} {quote.delivery}
          </dd>
        </div>
        <div className="mt-1 flex items-baseline justify-between border-t border-border pt-2">
          <dt className="font-medium text-fg">Total</dt>
          <dd className="font-display text-xl text-fg">
            {quote.currency} {quote.total}
          </dd>
        </div>
      </dl>
    </div>
  );
}

function QuoteDetailSkeleton() {
  return (
    <div className="flex flex-col gap-6" aria-hidden="true">
      <div className="flex items-center justify-between">
        <Skeleton width="10rem" height="2rem" />
        <Skeleton width="6rem" height="1.75rem" />
      </div>
      <Card padding="md">
        <Skeleton height="1.5rem" />
      </Card>
      <Card padding="lg">
        <Skeleton height="1.25rem" width="8rem" />
        <Skeleton className="mt-4" height="1rem" />
        <Skeleton className="mt-2" height="1rem" width="80%" />
        <Skeleton className="mt-2" height="1rem" width="60%" />
      </Card>
    </div>
  );
}
