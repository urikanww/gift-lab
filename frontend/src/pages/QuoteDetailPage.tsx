import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useQuoteStore } from '../stores/quoteStore';
import { useAuthStore } from '../stores/authStore';
import { Badge, Button, Card, Input, Modal, Skeleton, Textarea, useToast } from '../ui';
import { EmptyState as LegacyEmpty, ErrorState } from '../components/ui/States';
import { Motion, staggerContainer, staggerItem } from '../motion';
import { safeHref } from '../lib/safeHref';
import { isStaffRole } from '../lib/roles';
import { humanizeState, proofStateTone, quoteStateTone } from '../lib/quoteStatus';
import TrackingQr from '../components/TrackingQr';
import Breadcrumb from '../components/Breadcrumb';
import QuoteTimeline from '../components/quote/QuoteTimeline';
import StatusHistory from '../components/quote/StatusHistory';
import QuoteLineItems, { PricingSummary } from '../components/quote/QuoteLineItems';
import QuoteLineEditor from '../components/quote/QuoteLineEditor';
import type { Proof, QuoteState } from '../types';

/**
 * Passive "what happens next" copy for buyer-facing states that carry no buyer
 * action. Keeps the buyer oriented (whose court the ball is in) after they hand
 * off - e.g. right after requesting changes there's otherwise no confirmation
 * of what follows. States WITH a buyer action (SENT, PROOF_APPROVED) are
 * handled by the "Next step" action card instead; CANCELLED is surfaced by the
 * timeline card.
 */
const BUYER_STATUS_NOTE: Partial<Record<QuoteState, string>> = {
  ACCEPTED: 'Quote accepted. Our team is preparing your first proof - we’ll let you know when it’s ready to review.',
  CHANGES_REQUESTED: 'We’ve received your change request and will send a revised proof shortly.',
  PROOFING: 'Your proof is being prepared. We’ll notify you as soon as it’s ready to review.',
  INVOICED: 'Payment received and an invoice has been issued. We’re confirming your order for production.',
  CONFIRMED: 'Your order is confirmed. It will be scheduled for production shortly.',
  PROCURING: 'Your order is being prepared for production.',
  READY: 'Your order is ready. We’ll be in touch about delivery.',
  CLOSED: 'This order is complete. Thanks for working with us.',
};

export default function QuoteDetailPage() {
  // Buyer/public URLs carry the opaque order reference; the API resolves it
  // (or a numeric id) server-side. Once loaded, actions use the quote's real id.
  const { reference } = useParams();
  const {
    current,
    loading,
    error,
    actionError,
    clearActionError,
    fetchQuote,
    amend,
    send,
    accept,
    procure,
    issueProof,
    decideProof,
    issueInvoice,
    payNow,
    cancelQuote,
  } = useQuoteStore();
  const user = useAuthStore((s) => s.user);
  const isStaff = isStaffRole(user?.role);
  const { toast } = useToast();

  // Staff line editor. Closed on save so the read-only table reflects what the
  // server actually stored, rather than leaving the form's optimistic figures
  // on screen.
  const [editingLines, setEditingLines] = useState(false);

  const [artworkRef, setArtworkRef] = useState('');
  const [artworkRefError, setArtworkRefError] = useState<string | undefined>();
  // Dedicated state for the DRAFT send-with-proof field. Kept separate from the
  // issue-proof state above: the component does not unmount across a state
  // change, so a shared field would bleed a typed DRAFT ref into the
  // Issue-proof input if the quote transitions to PROOFING under it.
  const [sendProofRef, setSendProofRef] = useState('');
  const [sendProofRefError, setSendProofRefError] = useState<string | undefined>();
  const [poRef, setPoRef] = useState('');
  const [poRefError, setPoRefError] = useState<string | undefined>();
  const [busy, setBusy] = useState(false);
  // "Request changes" inline reveal: let the buyer say WHAT to change instead
  // of firing a canned note.
  const [changesOpen, setChangesOpen] = useState(false);
  const [changeNotes, setChangeNotes] = useState('');
  // Staff-only cancel confirm modal.
  const [cancelOpen, setCancelOpen] = useState(false);
  const [cancelReason, setCancelReason] = useState('');

  useEffect(() => {
    // Clear on navigation: the store outlives the page, so without this a
    // failure on one order greets you on the next one you open.
    clearActionError();
    if (reference) void fetchQuote(reference);
  }, [reference, fetchQuote, clearActionError]);

  const run = async (fn: () => Promise<void>, successMsg?: string) => {
    setBusy(true);
    try {
      await fn();
      if (successMsg && !useQuoteStore.getState().actionError) {
        toast({ title: successMsg, tone: 'success' });
      }
    } finally {
      setBusy(false);
    }
  };

  const latestOpenProof = (proofs: Proof[] | undefined): Proof | null =>
    proofs?.find((p) => p.state === 'SENT') ?? null;

  // Light client-side validation for the staff free-text refs - catches the
  // obvious mistakes (blank, spaces in a storage key, runaway length) before a
  // round-trip; the backend remains the authority.
  const validateArtworkRef = (value: string): string | undefined => {
    const v = value.trim();
    if (!v) return 'Enter the object-store key for the artwork.';
    if (/\s/.test(v)) return 'Storage keys cannot contain spaces.';
    if (v.length > 1024) return 'Storage key is too long (max 1024 characters).';
    return undefined;
  };

  const validatePoRef = (value: string): string | undefined => {
    const v = value.trim();
    if (!v) return 'Enter the PO number.';
    if (v.length > 64) return 'PO reference is too long (max 64 characters).';
    return undefined;
  };

  if (loading && !current) return <QuoteDetailSkeleton />;
  if (error) return <ErrorState message={error} onRetry={() => reference && fetchQuote(reference)} />;
  if (!current) return <LegacyEmpty title="Quote not found." />;

  const quote = current;
  const isCancellable = !['READY', 'CLOSED', 'CANCELLED'].includes(quote.state);

  /**
   * The Proofs card, rendered in a DIFFERENT position per role - see the two
   * guarded slots below.
   *
   * For a buyer this card is not reference material: it carries their proof
   * sign-off (approve / request changes), which is the primary call to action
   * on the whole page. It stays high, above the pricing and the "Next step"
   * card, so their action is never buried.
   *
   * Staff never see that sign-off. For them the proof list is a record to read
   * while working the controls, so it belongs after the Staff actions card
   * rather than pushing those controls down the page.
   *
   * Defined once and rendered in one of two slots rather than duplicated:
   * two copies of ~110 lines of JSX would drift. Do NOT "simplify" this back
   * to a single slot - the position is deliberately role-dependent.
   */
  const proofsCard = (
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
          <div className="mt-5 flex flex-col gap-3">
            <div className="flex flex-wrap gap-3">
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
                disabled={busy || changesOpen}
                onClick={() => setChangesOpen(true)}
              >
                Request changes
              </Button>
            </div>

            {/* Inline reveal: capture WHAT to change before sending. */}
            {changesOpen && (
              <div className="flex flex-col gap-3 rounded-md border border-border bg-surface-2/50 p-3">
                <label htmlFor="change-notes" className="text-sm font-medium text-fg">
                  What should we change?{' '}
                  <span className="font-normal text-fg-muted">(optional)</span>
                </label>
                <textarea
                  id="change-notes"
                  rows={3}
                  maxLength={2000}
                  value={changeNotes}
                  onChange={(e) => setChangeNotes(e.target.value)}
                  placeholder="e.g. Move the logo up and use the darker blue from our brand kit."
                  className="w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-base text-fg placeholder:text-fg-subtle transition-colors duration-fast ease-standard focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-bg"
                />
                <div className="flex flex-wrap gap-3">
                  <Button
                    variant="primary"
                    loading={busy}
                    disabled={busy}
                    onClick={() =>
                      run(async () => {
                        // API requires a note with request_changes - fall
                        // back to a generic one if the buyer left it blank.
                        await decideProof(
                          latestOpenProof(quote.proofs)!.id,
                          'request_changes',
                          changeNotes.trim() || 'Please revise.',
                        );
                        if (!useQuoteStore.getState().actionError) {
                          setChangesOpen(false);
                          setChangeNotes('');
                        }
                      }, 'Changes requested')
                    }
                  >
                    Send request
                  </Button>
                  <Button variant="ghost" disabled={busy} onClick={() => setChangesOpen(false)}>
                    Cancel
                  </Button>
                </div>
              </div>
            )}
          </div>
        )}
      </Card>
    </Motion>
  );

  return (
    <Motion variants={staggerContainer} initial="hidden" animate="visible">
      <section className="flex flex-col gap-6" aria-labelledby="quote-heading">
        {/* Buyers-only: staff arrive from the console, not from an account area. */}
        {!isStaff && (
          <Motion variants={staggerItem}>
            <Breadcrumb
              items={[
                { label: 'Home', to: '/' },
                { label: 'My account', to: '/account' },
                { label: 'My Orders', to: '/quotes' },
                { label: `Order ${quote.reference}` },
              ]}
            />
          </Motion>
        )}

        {/* Header */}
        <Motion variants={staggerItem}>
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <h1 id="quote-heading" className="font-display text-3xl text-fg">
                Order {quote.reference}
              </h1>
              {quote.created_at && (
                <p className="mt-1 text-sm text-fg-muted">
                  Created {new Date(quote.created_at).toLocaleDateString()}
                </p>
              )}
              {quote.needed_by && (
                <p className="mt-1 text-sm text-fg-muted">
                  Need by{' '}
                  <span className="font-medium text-fg">
                    {new Date(quote.needed_by).toLocaleDateString(undefined, {
                      day: 'numeric',
                      month: 'short',
                      year: 'numeric',
                    })}
                  </span>
                </p>
              )}
              {quote.tracking_code && (
                <p className="mt-1 text-sm text-fg-muted">
                  Tracking code{' '}
                  <span className="font-mono font-semibold text-fg">{quote.tracking_code}</span>
                  <span className="text-fg-subtle"> - share to track without an account at /track</span>
                </p>
              )}
            </div>
            <Badge tone={quoteStateTone(quote.state)} size="md" dot>
              {humanizeState(quote.state)}
            </Badge>
          </div>
        </Motion>

        {/* Rejected write. Sits below the header so it is the first thing read
            after the order identity, and crucially LEAVES THE ORDER ON SCREEN -
            these used to replace the whole page, so a mistyped PO reference
            took the controls away along with the explanation. */}
        {actionError && (
          <Motion variants={staggerItem}>
            <div
              role="alert"
              className="flex items-start justify-between gap-3 rounded-md border border-danger/30 bg-danger-bg p-4"
            >
              <div>
                <p className="font-medium text-fg">That didn’t go through</p>
                <p className="mt-1 text-sm text-fg-muted">{actionError}</p>
              </div>
              <Button variant="ghost" size="sm" onClick={clearActionError}>
                Dismiss
              </Button>
            </div>
          </Motion>
        )}

        {/* Status timeline */}
        <Motion variants={staggerItem}>
          <QuoteTimeline state={quote.state} />
        </Motion>

        {/* Recorded state changes - how the order got where it is. */}
        <Motion variants={staggerItem}>
          {/* `state` is not rendered by StatusHistory - it is what makes the
              history refetch when the order moves under it, so the trail can
              never contradict the badge and timeline above. */}
          <StatusHistory reference={quote.reference} state={quote.state} />
        </Motion>

        {/* Login-free tracking link + QR - share with the recipient. Buyer-only:
            the whole card is a sharing affordance (scan/bookmark to follow
            without an account), which is meaningless to staff, who reach this
            order through the console and already see its live state above. The
            tracking CODE stays in the header for everyone - staff need to read
            it back to a buyer who calls in. */}
        {!isStaff && quote.tracking_link && (
          <Motion variants={staggerItem}>
            <Card padding="lg" aria-labelledby="track-heading">
              <h2 id="track-heading" className="font-display text-xl text-fg">
                Track this order
              </h2>
              <div className="mt-4 flex flex-col items-center gap-3">
                <a
                  href={quote.tracking_link}
                  className="text-sm font-medium text-primary underline"
                >
                  Track your order
                </a>
                <TrackingQr link={quote.tracking_link} />
                <p className="text-xs text-fg-subtle">
                  Scan or bookmark to follow this order — no login needed.
                </p>
              </div>
            </Card>
          </Motion>
        )}

        {/* Line items */}
        <Motion variants={staggerItem}>
          <Card padding="none" className="overflow-hidden">
            <div className="flex items-center justify-between gap-3 border-b border-border px-5 py-4">
              <h2 className="font-display text-xl text-fg">Items</h2>
              {/* DRAFT-only, staff-only: the service refuses to amend anything
                  further along, and past SENT the buyer has seen the figures. */}
              {isStaff && quote.state === 'DRAFT' && !editingLines && (
                <Button variant="secondary" size="sm" onClick={() => setEditingLines(true)}>
                  Edit items
                </Button>
              )}
            </div>
            {isStaff && quote.state === 'DRAFT' && editingLines ? (
              <QuoteLineEditor
                quote={quote}
                onCancel={() => setEditingLines(false)}
                onSave={async (payload) => {
                  const fieldErrors = await amend(quote.id, payload);
                  if (Object.keys(fieldErrors).length === 0) {
                    setEditingLines(false);
                    toast({ title: 'Order updated.' });
                  }
                  return fieldErrors;
                }}
              />
            ) : (
              <>
                <QuoteLineItems items={quote.line_items} />
                <PricingSummary quote={quote} />
              </>
            )}
          </Card>
        </Motion>

        {/* Proofs (buyer slot) - carries the buyer's proof sign-off, so it sits
            above the pricing and "Next step" cards. See `proofsCard` above. */}
        {!isStaff && proofsCard}

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
                    onClick={() =>
                      run(async () => {
                        // Only toast on immediate capture - the Stripe path
                        // redirects away, so feedback there would be lost.
                        const paid = await payNow(quote.id);
                        if (paid && !useQuoteStore.getState().actionError) {
                          toast({ title: 'Payment received', tone: 'success' });
                        }
                      })
                    }
                  >
                    Pay now
                  </Button>
                </div>
              )}
            </Card>
          </Motion>
        )}

        {/* Buyer status note - passive "what happens next" for every
            buyer-facing state with no buyer action, so the ball is never
            silently in our court. Mirrors the staff fallback line. */}
        {!isStaff &&
          BUYER_STATUS_NOTE[quote.state] &&
          // PROOFING's "being prepared" copy is contradictory once a proof is
          // actually open for review - the sign-off card above covers that case.
          !(quote.state === 'PROOFING' && latestOpenProof(quote.proofs)) && (
          <Motion variants={staggerItem}>
            <Card padding="lg">
              <h2 className="font-display text-xl text-fg">What happens next</h2>
              <p className="mt-3 text-sm text-fg-muted">{BUYER_STATUS_NOTE[quote.state]}</p>
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
                  <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                    <div className="flex-1">
                      <Input
                        label="Attach proof (optional)"
                        hint="Leave blank to send a plain quote, or add an artwork reference to send it straight into proofing."
                        placeholder="object-store key"
                        value={sendProofRef}
                        error={sendProofRefError}
                        onChange={(e) => {
                          setSendProofRef(e.target.value);
                          setSendProofRefError(undefined);
                        }}
                      />
                    </div>
                    <Button
                      variant="primary"
                      loading={busy}
                      disabled={busy}
                      onClick={() => {
                        // Empty field: frictionless plain send (DRAFT -> SENT),
                        // no validation and nothing to reset.
                        if (!sendProofRef.trim()) {
                          void run(() => send(quote.id), 'Sent to buyer');
                          return;
                        }
                        // Validate BEFORE run() so a bad ref never triggers run's
                        // success toast (it toasts whenever store.error is unset).
                        const err = validateArtworkRef(sendProofRef);
                        if (err) {
                          setSendProofRefError(err);
                          return;
                        }
                        void run(async () => {
                          await send(quote.id, { artwork_version_ref: sendProofRef.trim() });
                          // send() swallows errors into store.error and never
                          // rejects, so only clear the field on a clean send.
                          if (!useQuoteStore.getState().actionError) setSendProofRef('');
                        }, 'Sent to buyer with proof');
                      }}
                    >
                      Send to buyer
                    </Button>
                  </div>
                )}

                {(quote.state === 'ACCEPTED' || quote.state === 'PROOFING') && (
                  <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                    <div className="flex-1">
                      <Input
                        label="Artwork reference"
                        placeholder="object-store key"
                        value={artworkRef}
                        error={artworkRefError}
                        onChange={(e) => {
                          setArtworkRef(e.target.value);
                          setArtworkRefError(undefined);
                        }}
                      />
                    </div>
                    <Button
                      variant="primary"
                      loading={busy}
                      disabled={busy || !artworkRef}
                      onClick={() => {
                        const err = validateArtworkRef(artworkRef);
                        if (err) {
                          setArtworkRefError(err);
                          return;
                        }
                        void run(async () => {
                          await issueProof(quote.id, artworkRef.trim(), null);
                          setArtworkRef('');
                        }, 'Proof issued');
                      }}
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
                        error={poRefError}
                        onChange={(e) => {
                          setPoRef(e.target.value);
                          setPoRefError(undefined);
                        }}
                      />
                    </div>
                    <Button
                      variant="primary"
                      loading={busy}
                      disabled={busy || !poRef}
                      onClick={() => {
                        const err = validatePoRef(poRef);
                        if (err) {
                          setPoRefError(err);
                          return;
                        }
                        void run(async () => {
                          await issueInvoice(quote.id, poRef.trim(), null);
                          setPoRef('');
                        }, 'Invoice issued');
                      }}
                    >
                      Issue invoice
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

                {quote.state === 'PROCURING' && (
                  <div className="flex flex-col gap-2">
                    <p className="text-sm text-fg-muted">
                      {quote.line_items?.some((li) => li.line_state === 'AWAITING_RECONFIRM')
                        ? 'One or more lines need a stock/price decision before this order can be queued.'
                        : 'Procurement is running. Any line flagged during the re-check is resolved at the procurement desk.'}
                    </p>
                    <Link
                      to="/procurement"
                      className="text-sm font-medium text-primary hover:underline focus-visible:outline-none focus-visible:underline"
                    >
                      Go to procurement desk →
                    </Link>
                  </div>
                )}

                {!['DRAFT', 'ACCEPTED', 'PROOFING', 'PROOF_APPROVED', 'CONFIRMED', 'PROCURING'].includes(
                  quote.state,
                ) && <p className="text-sm text-fg-muted">No staff action available for this state.</p>}
              </div>

              {/* Cancel: staff-only, available from every pre-production state. */}
              {isCancellable && (
                <div className="mt-6 border-t border-border pt-4">
                  <Button variant="danger" disabled={busy} onClick={() => setCancelOpen(true)}>
                    Cancel quote
                  </Button>
                </div>
              )}
            </Card>
          </Motion>
        )}

        {/* Proofs (staff slot) - reference material for staff, so it follows the
            controls they act with rather than pushing them down. See
            `proofsCard` above. */}
        {isStaff && proofsCard}

        {isStaff && isCancellable && (
          <Modal
            open={cancelOpen}
            onClose={() => {
              setCancelOpen(false);
              setCancelReason('');
            }}
            title="Cancel this quote?"
            description="Stock already consumed will be returned. This cannot be undone."
            footer={
              <>
                <Button variant="ghost" disabled={busy} onClick={() => setCancelOpen(false)}>
                  Keep quote
                </Button>
                <Button
                  variant="danger"
                  loading={busy}
                  disabled={busy}
                  onClick={() =>
                    run(async () => {
                      const ok = await cancelQuote(quote.id, cancelReason.trim() || undefined);
                      if (ok) {
                        setCancelOpen(false);
                        setCancelReason('');
                      }
                    }, 'Quote cancelled')
                  }
                >
                  Confirm cancellation
                </Button>
              </>
            }
          >
            <Textarea
              label="Reason"
              hint="Optional - shown to staff on the quote history."
              rows={3}
              maxLength={2000}
              value={cancelReason}
              onChange={(e) => setCancelReason(e.target.value)}
              placeholder="e.g. Buyer requested cancellation."
            />
          </Modal>
        )}
      </section>
    </Motion>
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
