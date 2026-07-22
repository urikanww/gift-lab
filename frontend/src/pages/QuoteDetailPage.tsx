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
import OrderStatus from '../components/quote/OrderStatus';
import { useQuoteHistory } from '../lib/useQuoteHistory';
import QuoteLineItems, { PricingSummary } from '../components/quote/QuoteLineItems';
import QuoteLineEditor from '../components/quote/QuoteLineEditor';
import AmendmentHistory from '../components/quote/AmendmentHistory';
import ProofFileInput from '../components/quote/ProofFileInput';
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
    resendProof,
    issueInvoice,
    confirmStock,
    payNow,
    cancelQuote,
  } = useQuoteStore();
  const user = useAuthStore((s) => s.user);
  const isStaff = isStaffRole(user?.role);
  const isSuperadmin = user?.role === 'superadmin';
  const { toast } = useToast();

  // The order's recorded state trail, fetched ONCE here and shared by both the
  // status-history card and the timeline's per-step timestamps below. Called at
  // the top level (never after the early returns) so the hook order is stable;
  // it no-ops until `current` gives us a reference. Keyed on state so it
  // refetches when the order moves, keeping every dated surface in step.
  const history = useQuoteHistory(current?.reference ?? '', current?.state ?? 'DRAFT');

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
  // Optional reference image the buyer attaches to a change request. Held as the
  // uploaded storage ref; passed to decideProof and cleared once sent.
  const [changeAttachment, setChangeAttachment] = useState('');
  // Staff-only cancel confirm modal.
  // Committing is irreversible in practice (it opens production), so it is
  // confirmed rather than fired straight from the button.
  const [commitOpen, setCommitOpen] = useState(false);
  const [cancelOpen, setCancelOpen] = useState(false);
  const [cancelReason, setCancelReason] = useState('');
  // Tracking link/QR moved out of a full-width card into a header button that
  // opens this modal, keeping the sharing affordance without the page real
  // estate.
  const [trackOpen, setTrackOpen] = useState(false);

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
  // Staff edit a DRAFT; a superadmin may edit an order's lines at any stage.
  const canEditLines = (isStaff && quote.state === 'DRAFT') || isSuperadmin;
  // The proof the buyer is being asked to sign off right now, if any. Drives
  // the prominent review card (buyer) and the superadmin on-behalf controls.
  const openProof = latestOpenProof(quote.proofs);

  // A buyer's in-app DESIGNER artwork (a print-usable PNG), if any line carries
  // one. Lets staff issue the proof straight from it instead of re-uploading the
  // same file. Deliberately excludes `buyer_uploaded` reference photos: those
  // are a finished-look intent, not print-ready, and must be proofed from scratch.
  const buyerDesignRef =
    quote.line_items?.find(
      (li) => li.customization?.mode !== 'buyer_uploaded' && li.customization?.artwork_ref,
    )?.customization?.artwork_ref ?? null;
  // A line still needing a staff decision blocks the production gate: the gate
  // is a confirmation that everything is in hand, which is not yet true.
  const awaitingDecision =
    quote.line_items?.some((li) => li.line_state === 'AWAITING_RECONFIRM') ?? false;

  /**
   * Buyer proof sign-off - the primary call to action on the whole page when a
   * proof is open. Shows the artwork INLINE (no click-through to see what you're
   * approving) and the approve / request-changes controls right under it. Sits
   * high on the page (rendered straight after the status card) so the decision
   * is the first thing the buyer meets, never buried below pricing.
   *
   * Only rendered for a buyer with an open proof; staff and the read-only
   * history use `proofsCard` below.
   */
  const buyerProofReview = !isStaff && openProof && (
    <Motion variants={staggerItem}>
      <Card padding="lg" aria-labelledby="proof-review-heading">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <h2 id="proof-review-heading" className="font-display text-xl text-fg">
            Review your proof
          </h2>
          <span className="flex items-center gap-2">
            <span className="text-sm font-medium text-fg">v{openProof.version}</span>
            <Badge tone={proofStateTone(openProof.state)} size="sm">
              {humanizeState(openProof.state)}
            </Badge>
          </span>
        </div>
        <p className="mt-1 text-sm text-fg-muted">
          Check the artwork below. Approve it to move ahead, or request changes.
        </p>

        {/* The artwork itself, shown in place. artwork_url is resolved
            server-side; safeHref covers legacy rows holding a pasted URL. */}
        <div className="mt-4">
          <ArtworkPreview url={openProof.artwork_url ?? safeHref(openProof.artwork_version_ref)} />
        </div>

        <div className="mt-5 flex flex-col gap-3">
          <div className="flex flex-wrap gap-3">
            <Button
              variant="primary"
              loading={busy}
              disabled={busy}
              onClick={() => run(() => decideProof(openProof.id, 'approve', null), 'Proof approved')}
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
                <span className="font-normal text-danger">(required)</span>
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
              <p className="text-xs text-fg-subtle">
                Tell us what to fix so we can turn around a revised proof — this note goes to our team.
              </p>

              {/* Optional reference image: a mock-up, a photo, a sketch of what
                  they want. Uploads to the buyer artwork endpoint and travels to
                  staff on the change request. */}
              <ProofFileInput
                label="Attach a reference (optional)"
                hint="An image showing what you'd like — PNG, JPG or WEBP, up to 10 MB."
                value={changeAttachment}
                error={undefined}
                disabled={busy}
                endpoint="/uploads/artwork"
                field="artwork"
                accept="image/png,image/jpeg,image/webp"
                maxBytes={10 * 1024 * 1024}
                onChange={(ref) => setChangeAttachment(ref)}
              />

              <div className="flex flex-wrap gap-3">
                <Button
                  variant="primary"
                  loading={busy}
                  // A reason is mandatory: staff need to know WHAT to revise, and
                  // the API rejects request_changes without a note anyway.
                  disabled={busy || changeNotes.trim().length === 0}
                  onClick={() =>
                    run(async () => {
                      await decideProof(
                        openProof.id,
                        'request_changes',
                        changeNotes.trim(),
                        changeAttachment ? [changeAttachment] : undefined,
                      );
                      if (!useQuoteStore.getState().actionError) {
                        setChangesOpen(false);
                        setChangeNotes('');
                        setChangeAttachment('');
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
      </Card>
    </Motion>
  );

  /**
   * Read-only proof history: the list of every version and its state. For a
   * buyer this is reference only - their sign-off lives in `buyerProofReview`
   * above - so it is shown only once there is no open proof to act on. For
   * staff it is the record they read while working the controls, so it follows
   * the Staff actions card.
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
              <li key={p.id} className="flex flex-col gap-2 py-3 first:pt-0">
                <div className="flex flex-wrap items-center justify-between gap-3">
                  <span className="flex items-center gap-3">
                    <span className="font-medium text-fg">v{p.version}</span>
                    <Badge tone={proofStateTone(p.state)} size="sm">
                      {humanizeState(p.state)}
                    </Badge>
                  </span>
                  {/* artwork_url is resolved server-side so the client never has
                      to know whether the proof was uploaded or pasted. Falls back
                      to the raw ref for legacy rows that are neither. */}
                  {p.artwork_url ?? safeHref(p.artwork_version_ref) ? (
                    <a
                      href={p.artwork_url ?? safeHref(p.artwork_version_ref)}
                      target="_blank"
                      rel="noreferrer"
                      className="text-sm font-medium text-primary hover:underline focus-visible:outline-none focus-visible:underline"
                    >
                      View artwork
                    </a>
                  ) : (
                    <span className="text-sm text-fg-subtle">{p.artwork_version_ref}</span>
                  )}
                </div>

                {/* The buyer's change request: their note + any reference images
                    they attached, so staff see WHAT to revise on this version. */}
                {(p.notes || (p.change_attachments && p.change_attachments.length > 0)) && (
                  <div className="rounded-md border border-border bg-surface-2/50 p-3">
                    {p.notes && (
                      <p className="text-sm text-fg-muted">
                        <span className="font-medium text-fg">Requested changes: </span>
                        {p.notes}
                      </p>
                    )}
                    {p.change_attachments && p.change_attachments.length > 0 && (
                      <div className="mt-2 flex flex-wrap gap-2">
                        {p.change_attachments.map((a) =>
                          a.url ? (
                            <a
                              key={a.ref}
                              href={a.url}
                              target="_blank"
                              rel="noreferrer"
                              className="block h-16 w-16 overflow-hidden rounded-md border border-border bg-surface focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                              title="Open reference"
                            >
                              <img src={a.url} alt="Buyer reference" className="h-full w-full object-cover" />
                            </a>
                          ) : (
                            <span
                              key={a.ref}
                              className="inline-flex items-center rounded-md border border-border bg-surface px-2 py-1 text-xs text-fg-subtle"
                            >
                              Reference attached
                            </span>
                          ),
                        )}
                      </div>
                    )}
                  </div>
                )}
              </li>
            ))}
          </ul>
        ) : (
          <p className="mt-3 text-sm text-fg-muted">No proofs issued yet.</p>
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
            <div className="flex flex-wrap items-center gap-3">
              {/* Buyer-only tracking entry point, aligned with the order title.
                  Replaces the old full-width "Track this order" card. */}
              {!isStaff && quote.tracking_link && (
                <Button variant="secondary" size="sm" onClick={() => setTrackOpen(true)}>
                  Track order
                </Button>
              )}
              <Badge tone={quoteStateTone(quote.state)} size="md" dot>
                {humanizeState(quote.state)}
              </Badge>
            </div>
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

        {/* Order status - the glance (current/next/step) with the recorded
            who/when trail folded in behind a disclosure. One card in place of
            the old stepper + separate status-history pair. */}
        <Motion variants={staggerItem}>
          <OrderStatus state={quote.state} history={history} />
        </Motion>

        {/* Buyer proof sign-off - the page's primary action when a proof is
            open, so it sits right under the status glance with the artwork shown
            inline. See `buyerProofReview` above. */}
        {buyerProofReview}

        {/* Buyer status note - passive "what happens next", sitting right under
            the status glance so the ball is never silently in our court. Only
            for buyer-facing states with no buyer action (states WITH an action
            surface it through their own card). */}
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

        {/* Line items */}
        <Motion variants={staggerItem}>
          <Card padding="none" className="overflow-hidden">
            <div className="flex items-center justify-between gap-3 border-b border-border px-5 py-4">
              <h2 className="font-display text-xl text-fg">Items</h2>
              {/* Staff edit DRAFT only; a superadmin can edit at any stage (the
                  service enforces the same rule, and re-anchors an issued
                  invoice). Past SENT the buyer has seen the figures, so the
                  superadmin override is deliberately narrow to that role. */}
              {canEditLines && !editingLines && (
                <Button variant="secondary" size="sm" onClick={() => setEditingLines(true)}>
                  Edit items
                </Button>
              )}
            </div>
            {canEditLines && editingLines ? (
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

        {/* Staff-only edit trail for DRAFT amendments, sitting just under the
            items it describes. The log is only present in staff payloads, so
            this never renders for a buyer; the component itself hides when the
            order was never amended. */}
        {isStaff && quote.amendment_log && quote.amendment_log.length > 0 && (
          <Motion variants={staggerItem}>
            <AmendmentHistory entries={quote.amendment_log} currency={quote.currency} />
          </Motion>
        )}

        {/* Proofs history (buyer slot) - reference only, shown once there's no
            open proof to act on; the open-proof sign-off lives in the review
            card near the top of the page. See `proofsCard`/`buyerProofReview`. */}
        {!isStaff && !openProof && quote.proofs && quote.proofs.length > 0 && proofsCard}

        {/* Buyer actions */}
        {!isStaff &&
          (quote.state === 'SENT' ||
            quote.state === 'ARTWORK_APPROVED' ||
            quote.state === 'PROOF_APPROVED') && (
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
              {/* Artwork-first route: artwork is signed off, the price is not.
                  Approving artwork no longer implies agreeing the price, so
                  this is the second of the two approvals. */}
              {quote.state === 'ARTWORK_APPROVED' && (
                <div className="mt-4">
                  <p className="mb-3 text-sm text-fg-muted">
                    Your artwork is approved. Review the pricing above and accept it to confirm your
                    order.
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
              {/* Payment is off for this tenant: say what happens instead,
                  rather than offering a button that always fails. */}
              {quote.state === 'PROOF_APPROVED' && !quote.pay_now_enabled && (
                <p className="mt-4 text-sm text-fg-muted">
                  Your proof is approved. We’ll send your invoice and confirm your order for
                  production.
                </p>
              )}
              {quote.state === 'PROOF_APPROVED' && quote.pay_now_enabled && (
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

        {/* Staff workflow controls */}
        {isStaff && (
          <Motion variants={staggerItem}>
            <Card padding="lg" aria-labelledby="staff-heading">
              <h2 id="staff-heading" className="font-display text-xl text-fg">
                Staff actions
              </h2>

              <div className="mt-4">
                {quote.state === 'DRAFT' && (
                  <div className="flex flex-col gap-2">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                      <div className="flex-1">
                        <ProofFileInput
                          label="Attach proof (optional)"
                          hint="Leave empty to send a plain quote, or attach artwork to send it straight into proofing. PDF or image, up to 3 MB."
                          value={sendProofRef}
                          valueLabel={sendProofRef === buyerDesignRef ? 'Buyer’s design' : undefined}
                          error={sendProofRefError}
                          disabled={busy}
                          onChange={(ref) => {
                            setSendProofRef(ref);
                            setSendProofRefError(undefined);
                          }}
                        />
                        {/* One-click reuse: the buyer already supplied a
                            print-usable designer artwork, so offer it as the
                            proof instead of re-uploading the same file. */}
                        {buyerDesignRef && !sendProofRef && (
                          <Button
                            variant="secondary"
                            size="sm"
                            disabled={busy}
                            className="mt-2"
                            onClick={() => {
                              setSendProofRef(buyerDesignRef);
                              setSendProofRefError(undefined);
                            }}
                          >
                            Use buyer’s design as the proof
                          </Button>
                        )}
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
                    <p className="text-xs text-fg-subtle">
                      Emails the quote to the buyer and moves it to Sent. They can then accept it or
                      request changes.
                    </p>
                  </div>
                )}

                {/* CHANGES_REQUESTED included deliberately: issuing a revised
                    proof is how an order gets out of that state. Without this
                    control the state is a dead end and the order has to be
                    cancelled and rebuilt. */}
                {(quote.state === 'ACCEPTED' ||
                  quote.state === 'PROOFING' ||
                  quote.state === 'CHANGES_REQUESTED') && (
                  <div className="flex flex-col gap-2">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                      <div className="flex-1">
                        <ProofFileInput
                          label="Proof artwork"
                          hint="PDF or image, up to 3 MB."
                          value={artworkRef}
                          valueLabel={artworkRef === buyerDesignRef ? 'Buyer’s design' : undefined}
                          error={artworkRefError}
                          disabled={busy}
                          onChange={(ref) => {
                            setArtworkRef(ref);
                            setArtworkRefError(undefined);
                          }}
                        />
                        {/* Reuse the buyer's designer artwork instead of
                            re-uploading it. Reference-photo uploads are excluded
                            upstream (buyerDesignRef), so this only appears when a
                            print-usable design exists. */}
                        {buyerDesignRef && !artworkRef && (
                          <Button
                            variant="secondary"
                            size="sm"
                            disabled={busy}
                            className="mt-2"
                            onClick={() => {
                              setArtworkRef(buyerDesignRef);
                              setArtworkRefError(undefined);
                            }}
                          >
                            Use buyer’s design as the proof
                          </Button>
                        )}
                      </div>
                      <Button
                        variant="primary"
                        loading={busy}
                        disabled={busy || !artworkRef}
                        onClick={() => {
                          void run(async () => {
                            await issueProof(quote.id, artworkRef.trim(), null);
                            setArtworkRef('');
                          }, 'Proof issued');
                        }}
                      >
                        Issue proof
                      </Button>
                    </div>
                    <p className="text-xs text-fg-subtle">
                      Sends this artwork to the buyer as a proof to review. They approve it or send it
                      back with changes.
                    </p>
                  </div>
                )}

                {/* This button was labelled "Issue invoice" and gave no hint
                    that the same transaction drives the quote through INVOICED
                    to CONFIRMED - the production gate. Staff were committing
                    the order without being told they were. Renamed to say so,
                    and confirmed before it fires. */}
                {quote.state === 'PROOF_APPROVED' && (
                  <div className="flex flex-col gap-2">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                      <div className="flex-1">
                        <Input
                          label="PO reference"
                          placeholder="PO number"
                          hint="Raises the invoice and commits the order to production."
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
                          setCommitOpen(true);
                        }}
                      >
                        Commit order
                      </Button>
                    </div>
                    <p className="text-xs text-fg-subtle">
                      Raises the invoice and confirms the order for production. After this it can no
                      longer be edited — you’ll be asked to confirm first.
                    </p>
                  </div>
                )}

                {quote.state === 'CONFIRMED' && (
                  <div className="flex flex-col gap-2">
                    <Button
                      variant="primary"
                      loading={busy}
                      disabled={busy}
                      onClick={() => run(() => procure(quote.id), 'Procurement started')}
                    >
                      Run procurement
                    </Button>
                    <p className="text-xs text-fg-subtle">
                      Checks stock for every line and opens purchasing for anything short, moving the
                      order into procurement.
                    </p>
                  </div>
                )}

                {quote.state === 'PROCURING' && (
                  <div className="flex flex-col gap-2">
                    {awaitingDecision ? (
                      <>
                        <p className="text-sm text-fg-muted">
                          One or more lines need a stock or price decision before this order can go to
                          production.
                        </p>
                        <Link
                          to="/procurement"
                          className="text-sm font-medium text-primary hover:underline focus-visible:outline-none focus-visible:underline"
                        >
                          Go to procurement desk →
                        </Link>
                      </>
                    ) : (
                      /* The production gate. Nothing reaches the floor on the
                         strength of a stock figure alone - most goods are bought
                         in after the order, so a person confirming they are here
                         is the only reliable check. Attributed, because with the
                         automatic checks advisory this is the last one left. */
                      <>
                        <p className="text-sm text-fg-muted">
                          Every line is resolved. Check the items above against what has actually
                          arrived, then release the order to production.
                        </p>
                        <ul className="my-1 flex flex-col gap-1 text-sm text-fg">
                          {quote.line_items
                            ?.filter((li) => li.line_state === 'READY')
                            .map((li) => (
                              <li key={li.id} className="tabular-nums">
                                {li.qty} × {li.product?.name ?? `Product #${li.product_id}`}
                                {/* Advisory finding from procurement. It did not
                                    stop the order — this is the moment someone
                                    is looking at the goods, so it is the moment
                                    worth showing it. */}
                                {li.procurement_note && (
                                  <span className="ml-2 text-xs text-warning">
                                    ⚠ {li.procurement_note}
                                  </span>
                                )}
                              </li>
                            ))}
                        </ul>
                        <div>
                          <Button
                            variant="primary"
                            loading={busy}
                            disabled={busy}
                            onClick={() =>
                              run(() => confirmStock(quote.id), 'Stock confirmed — sent to production')
                            }
                          >
                            Confirm stock and start production
                          </Button>
                        </div>
                        <p className="text-xs text-fg-subtle">
                          Your name and the time are recorded against this confirmation.
                        </p>
                      </>
                    )}
                  </div>
                )}

                {/* Waiting on the buyer, not on staff - say which, rather than
                    the bare "no action available" that leaves staff wondering
                    whether something is stuck. */}
                {quote.state === 'ARTWORK_APPROVED' && (
                  <p className="text-sm text-fg-muted">
                    Artwork approved. Waiting for the buyer to accept the price — nothing to do here
                    until they do.
                  </p>
                )}

                {![
                  'DRAFT',
                  'ACCEPTED',
                  'PROOFING',
                  'CHANGES_REQUESTED',
                  'ARTWORK_APPROVED',
                  'PROOF_APPROVED',
                  'CONFIRMED',
                  'PROCURING',
                ].includes(quote.state) && (
                  <p className="text-sm text-fg-muted">No staff action available for this state.</p>
                )}
              </div>

              {/* Superadmin-only, and only while a proof is open (awaiting the
                  buyer): nudge them by resending the review email, or sign the
                  proof off on their behalf. The approval is recorded against the
                  superadmin, not the buyer - see approveProof's approved_by. */}
              {isSuperadmin && latestOpenProof(quote.proofs) && (
                <div className="mt-6 flex flex-col gap-2 border-t border-border pt-4">
                  <span className="text-sm font-medium text-fg">On the buyer’s behalf</span>
                  <div className="flex flex-wrap gap-3">
                    <Button
                      variant="secondary"
                      loading={busy}
                      disabled={busy}
                      onClick={() =>
                        run(async () => {
                          const ok = await resendProof(latestOpenProof(quote.proofs)!.id);
                          if (ok) toast({ title: 'Proof email resent', tone: 'success' });
                        })
                      }
                    >
                      Resend proof email
                    </Button>
                    <Button
                      variant="primary"
                      loading={busy}
                      disabled={busy}
                      onClick={() =>
                        run(
                          () => decideProof(latestOpenProof(quote.proofs)!.id, 'approve', null),
                          'Proof approved on the buyer’s behalf',
                        )
                      }
                    >
                      Approve on behalf
                    </Button>
                  </div>
                  <p className="text-xs text-fg-subtle">
                    Resend the review email, or approve the proof yourself — the approval is
                    recorded against your name, not the buyer’s.
                  </p>
                </div>
              )}

              {/* Cancel: staff-only, available from every pre-production state. */}
              {isCancellable && (
                <div className="mt-6 flex flex-col gap-2 border-t border-border pt-4">
                  <div>
                    <Button variant="danger" disabled={busy} onClick={() => setCancelOpen(true)}>
                      Cancel quote
                    </Button>
                  </div>
                  <p className="text-xs text-fg-subtle">
                    Stops this order for good. Any stock already reserved is returned. This can’t be
                    undone.
                  </p>
                </div>
              )}
            </Card>
          </Motion>
        )}

        {/* Proofs (staff slot) - reference material for staff, so it follows the
            controls they act with rather than pushing them down. See
            `proofsCard` above. */}
        {isStaff && proofsCard}

        {/* The commitment step. Issuing the invoice also drives the order to
            CONFIRMED, which opens production - previously with no indication
            that pressing the button did anything beyond raising an invoice. */}
        {isStaff && (
          <Modal
            open={commitOpen}
            onClose={() => setCommitOpen(false)}
            title="Commit this order to production?"
            description="This raises the invoice and confirms the order. Production can begin and the order can no longer be edited."
            footer={
              <>
                <Button variant="ghost" onClick={() => setCommitOpen(false)} disabled={busy}>
                  Back
                </Button>
                <Button
                  variant="primary"
                  loading={busy}
                  disabled={busy}
                  onClick={() =>
                    void run(async () => {
                      await issueInvoice(quote.id, poRef.trim(), null);
                      if (!useQuoteStore.getState().actionError) {
                        setPoRef('');
                        setCommitOpen(false);
                      }
                    }, 'Order committed to production')
                  }
                >
                  Commit order
                </Button>
              </>
            }
          />
        )}

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

        {/* Login-free tracking link + QR - opened from the header "Track order"
            button. Buyer-only sharing affordance (scan/bookmark to follow with no
            account); staff reach the order through the console. */}
        {!isStaff && quote.tracking_link && (
          <Modal
            open={trackOpen}
            onClose={() => setTrackOpen(false)}
            title="Track this order"
            description="Scan or share this link to follow the order — no login needed."
          >
            <div className="flex flex-col items-center gap-3">
              <a href={quote.tracking_link} className="text-sm font-medium text-primary underline">
                Track your order
              </a>
              <TrackingQr link={quote.tracking_link} />
            </div>
          </Modal>
        )}
      </section>
    </Motion>
  );
}

/**
 * Renders proof artwork in place so the buyer sees what they're approving
 * without a click-through. The signed URL carries no reliable extension, so we
 * try to render it as an image and fall back to an open-in-new-tab link when it
 * isn't one (e.g. a PDF proof) or fails to load. Clicking the image still opens
 * the full-size artwork in a new tab.
 */
function ArtworkPreview({ url }: { url: string | null | undefined }) {
  const [failed, setFailed] = useState(false);

  if (!url) {
    return <p className="text-sm text-fg-muted">Artwork preview isn’t available — please contact us.</p>;
  }

  if (failed) {
    return (
      <a
        href={url}
        target="_blank"
        rel="noreferrer"
        className="inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline focus-visible:outline-none focus-visible:underline"
      >
        Open artwork ↗
      </a>
    );
  }

  return (
    <a
      href={url}
      target="_blank"
      rel="noreferrer"
      className="block overflow-hidden rounded-md border border-border bg-surface-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-bg"
      title="Open full-size artwork"
    >
      <img
        src={url}
        alt="Proof artwork"
        onError={() => setFailed(true)}
        className="mx-auto max-h-[28rem] w-full object-contain"
      />
    </a>
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
