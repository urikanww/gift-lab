import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useQuoteStore } from '../stores/quoteStore';
import { useAuthStore } from '../stores/authStore';
import { Badge, Button, Card, Input, Modal, Skeleton, Textarea, useToast } from '../ui';
import { EmptyState as LegacyEmpty, ErrorState } from '../components/ui/States';
import { Motion, staggerContainer, staggerItem } from '../motion';
import { safeHref } from '../lib/safeHref';
import { hasPermission, isStaffRole } from '../lib/roles';
import { humanizeState, proofStateTone } from '../lib/quoteStatus';
import TrackingQr from '../components/TrackingQr';
import Breadcrumb from '../components/Breadcrumb';
import OrderStatus from '../components/quote/OrderStatus';
import { useQuoteHistory } from '../lib/useQuoteHistory';
import QuoteLineItems, { PricingSummary } from '../components/quote/QuoteLineItems';
import QuoteLineEditor from '../components/quote/QuoteLineEditor';
import AmendmentHistory from '../components/quote/AmendmentHistory';
import ArtworkPicker, { type ArtworkOption } from '../components/quote/ArtworkPicker';
import LineProofRow from '../components/quote/LineProofRow';
import BuyerProofItem from '../components/quote/BuyerProofItem';
import BuyerNotifications from '../components/quote/BuyerNotifications';
import type { LineItem, PaymentState, Proof, QuoteState } from '../types';

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
  INVOICED: 'Your invoice has been issued. We’re confirming your order for production.',
  CONFIRMED: 'Your order is confirmed. It will be scheduled for production shortly.',
  PROCURING: 'Your order is being prepared for production.',
  READY: 'Your order is ready. We’ll be in touch about delivery.',
  CLOSED: 'This order is complete. Thanks for working with us.',
};

/** Badge tone + label for the B2B invoice payment_state, staff-only. */
const PAYMENT_STATE_TONE: Record<PaymentState, 'neutral' | 'success' | 'warning' | 'danger'> = {
  UNPAID: 'neutral',
  PARTIAL: 'warning',
  PAID: 'success',
  VOID: 'danger',
};
const PAYMENT_STATE_LABEL: Record<PaymentState, string> = {
  UNPAID: 'Unpaid',
  PARTIAL: 'Partial',
  PAID: 'Paid',
  VOID: 'Void',
};

/**
 * Small clickable preview of a proof version's artwork, shown on every row so
 * versions read at a glance (parity with the change-request attachment thumbs).
 * Files an <img> cannot render (a PDF proof) hide themselves - the "View
 * artwork" link stays as the way in.
 */
function ProofArtworkThumb({ url, version }: { url: string; version: number }) {
  const [failed, setFailed] = useState(false);
  if (failed) return null;
  return (
    <a
      href={url}
      target="_blank"
      rel="noreferrer"
      className="block h-16 w-16 overflow-hidden rounded-md border border-border bg-surface focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
      title={`Open proof v${version} artwork`}
    >
      <img
        src={url}
        alt={`Proof v${version} artwork`}
        referrerPolicy="no-referrer"
        onError={() => setFailed(true)}
        className="h-full w-full object-cover"
      />
    </a>
  );
}

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
    stageProof,
    sendProofs,
    setApprovalOrder,
    decideProof,
    approveAllProofs,
    resendProof,
    issueInvoice,
    reconcilePayment,
    confirmStock,
    markJobDelivered,
    payNow,
    cancelQuote,
  } = useQuoteStore();
  const user = useAuthStore((s) => s.user);
  const isStaff = isStaffRole(user?.role);
  const isSuperadmin = user?.role === 'superadmin';
  // Mirrors the backend's own gate (route middleware `permission:quotes.edit`
  // plus the `manageProduction` policy): a staff_admin without this specific
  // permission would only hit a 403, so the control stays hidden for them
  // rather than offering a button that always fails.
  const canReconcilePayment = isStaff && hasPermission(user, 'quotes.edit');
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

  // Staff-only amendment-log dialog, opened from the "History" button
  // beside "Edit items" in the Items card header.
  const [historyOpen, setHistoryOpen] = useState(false);

  // Which line the existing-artwork picker stages onto while open; null = closed.
  // Proofs are per-line now, so the picker targets a specific line rather than a
  // single shared proof field.
  const [pickerLineId, setPickerLineId] = useState<number | null>(null);

  const [poRef, setPoRef] = useState('');
  const [poRefError, setPoRefError] = useState<string | undefined>();
  const [busy, setBusy] = useState(false);
  // Staff-only cancel confirm modal.
  // Committing is irreversible in practice (it opens production), so it is
  // confirmed rather than fired straight from the button.
  const [commitOpen, setCommitOpen] = useState(false);
  // Set only from the commit modal's own confirm action, so a failure raised
  // there is visible in the modal body itself - the modal used to have no
  // body at all, so a duplicate PO reference only ever surfaced on the
  // page-top banner, behind the modal overlay staff were still looking at.
  const [commitError, setCommitError] = useState<string | null>(null);
  const [cancelOpen, setCancelOpen] = useState(false);
  const [cancelReason, setCancelReason] = useState('');
  // Staff-only invoice payment reconciliation. Void is confirmed (terminal -
  // no further reconciliation is possible afterwards), same reasoning as the
  // cancel-order modal; Paid/Partial fire straight from their buttons.
  const [voidOpen, setVoidOpen] = useState(false);
  const [voidNote, setVoidNote] = useState('');
  // H3/M21: PARTIAL now captures the collected amount, so "Mark partial" opens
  // an amount field rather than firing blind - a later cancel refunds only what
  // was actually received.
  const [partialOpen, setPartialOpen] = useState(false);
  const [partialAmount, setPartialAmount] = useState('');
  // Set only from the void modal's own confirm action, mirroring commitError:
  // a rejected void should be visible in the modal body itself, not only
  // behind it on the page banner.
  const [voidError, setVoidError] = useState<string | null>(null);
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

  // Every artwork already on the order that staff can attach as the proof
  // instead of re-uploading: the buyer's line designs (print-usable designer
  // PNGs - `buyer_uploaded` reference photos are excluded, they are a
  // finished-look intent, not print-ready), images the buyer attached to
  // change requests (newest bounce first), and previously issued proof
  // versions (newest first). Deduped by ref so the same file lists once under
  // its first (most authoritative) source.
  const seenArtworkRefs = new Set<string>();
  const artworkOptions: ArtworkOption[] = [];
  const addArtworkOption = (option: ArtworkOption) => {
    if (!option.ref || seenArtworkRefs.has(option.ref)) return;
    seenArtworkRefs.add(option.ref);
    artworkOptions.push(option);
  };
  for (const li of quote.line_items ?? []) {
    const ref =
      li.customization?.mode !== 'buyer_uploaded' ? li.customization?.artwork_ref ?? null : null;
    if (!ref) continue;
    addArtworkOption({
      ref,
      name: `Buyer’s design — ${li.product?.name ?? `Line #${li.id}`}`,
      detail: 'From the order line',
      productImageUrl: li.product?.image_url ?? null,
    });
  }
  const proofsNewestFirst = [...(quote.proofs ?? [])].sort((a, b) => b.version - a.version);
  for (const p of proofsNewestFirst) {
    for (const attachment of p.change_attachments ?? []) {
      addArtworkOption({
        ref: attachment.ref,
        url: attachment.url,
        name: `Change request image (v${p.version})`,
        detail: 'Attached by the buyer',
      });
    }
  }
  for (const p of proofsNewestFirst) {
    addArtworkOption({
      ref: p.artwork_version_ref,
      url: p.artwork_url,
      name: `Proof v${p.version} artwork`,
      detail: 'Previously issued',
    });
  }
  // Latest proof per line (highest version wins), so each row reads its own
  // state. Proofs are per-line now, keyed on line_item_id.
  const latestProofByLine = new Map<number, Proof>();
  for (const p of quote.proofs ?? []) {
    const existing = latestProofByLine.get(p.line_item_id);
    if (!existing || p.version > existing.version) latestProofByLine.set(p.line_item_id, p);
  }

  // A line needs a proof when it carries a real customization (an in-app design,
  // an uploaded artwork ref, or buyer reference photos) and has not been dropped.
  // L20: prefer the server's authoritative needs_proof (LineItem::needsProof) so
  // there is one definition; the local derivation is only a fallback for a
  // payload that predates the field. Not keyed on `mode` alone: a real designer
  // line can arrive with artwork_ref set but no mode key (order 9BWVKWCDXH).
  const lineNeedsProof = (li: LineItem): boolean => {
    if (li.needs_proof !== undefined) return li.needs_proof;
    if (li.line_state === 'DROPPED') return false;
    const c = li.customization;
    if (!c) return false;
    return Boolean(c.mode || c.artwork_ref || (c.reference_refs && c.reference_refs.length > 0));
  };
  const proofLines = (quote.line_items ?? []).filter(lineNeedsProof);

  // Reuse options for a line's picker, defaulting the line's own design first so
  // the common case (proof this line from its own artwork) is one click.
  const optionsForLine = (li: LineItem): ArtworkOption[] => {
    const own =
      li.customization?.mode !== 'buyer_uploaded' ? li.customization?.artwork_ref ?? null : null;
    if (!own) return artworkOptions;
    const idx = artworkOptions.findIndex((o) => o.ref === own);
    if (idx <= 0) return artworkOptions;
    return [
      artworkOptions[idx],
      ...artworkOptions.slice(0, idx),
      ...artworkOptions.slice(idx + 1),
    ];
  };

  // Blocker breakdown across the lines that need a proof. A DRAFT (staged but
  // unsent) proof is deliberately counted as "Not prepared" - the buyer has not
  // seen it yet, so it is not resolved.
  const perLineProofs = proofLines.map((li) => ({
    line: li,
    proof: latestProofByLine.get(li.id) ?? null,
  }));
  const stagedCount = perLineProofs.filter((x) => x.proof?.state === 'DRAFT').length;
  const awaitingBuyerCount = perLineProofs.filter((x) => x.proof?.state === 'SENT').length;
  const inChangesCount = perLineProofs.filter((x) => x.proof?.state === 'CHANGES_REQUESTED').length;
  const approvedProofCount = perLineProofs.filter((x) => x.proof?.state === 'APPROVED').length;
  const notPreparedCount = perLineProofs.filter(
    (x) => !x.proof || x.proof.state === 'DRAFT',
  ).length;
  // A line still needing a staff decision blocks the production gate: the gate
  // is a confirmation that everything is in hand, which is not yet true.
  const awaitingDecision =
    quote.line_items?.some((li) => li.line_state === 'AWAITING_RECONFIRM') ?? false;

  /**
   * Buyer per-line proof sign-off - the primary call to action on the whole page
   * while any line is awaiting the buyer. Each artwork line's current proof is
   * reviewed independently (artwork inline, per-item approve / request-changes),
   * with a progress banner and an "Approve all remaining" shortcut. Sits high on
   * the page (rendered straight after the status card) so the decision is the
   * first thing the buyer meets, never buried below pricing.
   *
   * Only rendered for a buyer with ≥1 line awaiting them or being revised; staff
   * and the read-only history use `proofsCard` below.
   */
  // Every artwork line (a real customization, not dropped) paired with its
  // latest proof, so each line reads its own review state.
  const buyerReviewLines = isStaff
    ? []
    : (quote.line_items ?? [])
        .filter((li) => li.line_state !== 'DROPPED' && li.customization)
        .map((li) => ({ line: li, proof: latestProofByLine.get(li.id) ?? null }))
        .filter((x): x is { line: LineItem; proof: Proof } => x.proof !== null);
  // Counts across those lines drive the progress banner.
  const buyerApprovedCount = buyerReviewLines.filter((x) => x.proof.state === 'APPROVED').length;
  const buyerAwaitingCount = buyerReviewLines.filter((x) => x.proof.state === 'SENT').length;
  const buyerRevisingCount = buyerReviewLines.filter(
    (x) => x.proof.state === 'CHANGES_REQUESTED',
  ).length;
  // The lines the buyer can still act on (or watch being revised) - the ones we
  // render a card for. Approved lines are summarised by the banner instead.
  const buyerActionableLines = buyerReviewLines.filter(
    (x) => x.proof.state === 'SENT' || x.proof.state === 'CHANGES_REQUESTED',
  );

  const buyerProgressBanner = [
    `${buyerApprovedCount} of ${buyerReviewLines.length} approved`,
    buyerAwaitingCount > 0 ? `${buyerAwaitingCount} awaiting you` : null,
    buyerRevisingCount > 0 ? `${buyerRevisingCount} being revised` : null,
  ]
    .filter(Boolean)
    .join(' · ');

  // Buyer_uploaded lines where staff haven't sent a proof yet (no proof, or one
  // still in DRAFT). The buyer briefed us and is now waiting on our artwork, so
  // tell them a proof is coming - otherwise the order looks stalled between
  // checkout and the first proof landing.
  const buyerDesignPending =
    !isStaff &&
    quote.state !== 'CANCELLED' &&
    (quote.line_items ?? []).some((li) => {
      if (li.line_state === 'DROPPED') return false;
      if (li.customization?.mode !== 'buyer_uploaded') return false;
      const p = latestProofByLine.get(li.id);
      return !p || p.state === 'DRAFT';
    });

  const buyerDesignPendingNotice = buyerDesignPending && (
    <Motion variants={staggerItem}>
      <Card padding="lg" aria-labelledby="design-pending-heading">
        <h2 id="design-pending-heading" className="font-display text-xl text-fg">
          Design in progress
        </h2>
        <p className="mt-1 text-sm text-fg-muted">
          Our design team is preparing your proof from the references you sent. You’ll review and
          approve it here before we print — nothing needed from you yet.
        </p>
      </Card>
    </Motion>
  );

  const buyerProofReview = !isStaff && buyerActionableLines.length > 0 && (
    <Motion variants={staggerItem}>
      <Card padding="lg" aria-labelledby="proof-review-heading">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <h2 id="proof-review-heading" className="font-display text-xl text-fg">
            Review your proof
          </h2>
        </div>
        <p className="mt-1 text-sm text-fg-muted">
          Check each artwork below. Approve it to move ahead, or request changes.
        </p>

        {/* Progress across every artwork line, so a multi-line order reads at a
            glance without counting cards. */}
        <p className="mt-3 rounded-md border border-border bg-surface-2/50 px-3 py-2 text-sm font-medium text-fg">
          {buyerProgressBanner}
        </p>

        {/* One-click sign-off for every line still awaiting the buyer. */}
        {buyerAwaitingCount > 0 && (
          <div className="mt-4">
            <Button
              variant="primary"
              loading={busy}
              disabled={busy}
              onClick={() =>
                run(async () => {
                  const ok = await approveAllProofs(quote.id);
                  if (ok) toast({ title: 'All proofs approved', tone: 'success' });
                })
              }
            >
              Approve all {buyerAwaitingCount} remaining
            </Button>
          </div>
        )}

        <div className="mt-4 flex flex-col gap-4">
          {buyerActionableLines.map(({ proof }) => (
            <BuyerProofItem
              key={proof.id}
              proof={proof}
              busy={busy}
              onApprove={() =>
                run(() => decideProof(proof.id, 'approve', null), 'Proof approved')
              }
              onRequestChanges={(notes, attachmentRef) =>
                run(
                  () =>
                    decideProof(
                      proof.id,
                      'request_changes',
                      notes,
                      attachmentRef ? [attachmentRef] : undefined,
                    ),
                  'Changes requested',
                )
              }
            />
          ))}
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
  // The signed-off version - what actually goes to print. Across several
  // change-request rounds this is the one row that matters, so it is called out
  // above the list AND highlighted in place, rather than reading as just another
  // version with a green pill.
  const approvedProof = quote.proofs?.find((p) => p.state === 'APPROVED') ?? null;
  const approvedProofHref = approvedProof
    ? approvedProof.artwork_url ?? safeHref(approvedProof.artwork_version_ref)
    : null;

  const proofsCard = (
    <Motion variants={staggerItem}>
      <Card padding="lg" aria-labelledby="proofs-heading">
        <h2 id="proofs-heading" className="font-display text-xl text-fg">
          Proofs
        </h2>

        {/* Approved-artwork callout: which version is print-ready, so nobody has
            to scan the version list to find the one that ships. */}
        {approvedProof && (
          <div className="mt-4 flex flex-wrap items-center gap-3 rounded-md border border-success/40 bg-success-bg p-3">
            {approvedProof.artwork_url && (
              <ProofArtworkThumb url={approvedProof.artwork_url} version={approvedProof.version} />
            )}
            <div className="min-w-0 flex-1">
              <p className="flex items-center gap-2 text-sm font-medium text-fg">
                <span aria-hidden="true" className="text-success">
                  ✓
                </span>
                Approved artwork: v{approvedProof.version}
              </p>
              <p className="mt-0.5 text-xs text-fg-muted">
                This is the signed-off version — the one that goes to production.
              </p>
            </div>
            {approvedProofHref && (
              <a
                href={approvedProofHref}
                target="_blank"
                rel="noreferrer"
                className="text-sm font-medium text-primary hover:underline focus-visible:outline-none focus-visible:underline"
              >
                View artwork
              </a>
            )}
          </div>
        )}

        {quote.proofs && quote.proofs.length > 0 ? (
          <ul className="mt-4 flex flex-col divide-y divide-border">
            {quote.proofs.map((p) => {
              const isApproved = p.state === 'APPROVED';
              return (
              <li
                key={p.id}
                className={`flex flex-col gap-2 py-3 first:pt-0 ${
                  isApproved
                    ? 'rounded-md border border-success/40 bg-success-bg/40 px-3'
                    : ''
                }`}
              >
                <div className="flex flex-wrap items-center justify-between gap-3">
                  <span className="flex items-center gap-3">
                    <span className="font-medium text-fg">v{p.version}</span>
                    <Badge tone={proofStateTone(p.state)} size="sm">
                      {humanizeState(p.state)}
                    </Badge>
                    {/* In-list echo of the callout above, so the approved row is
                        unmistakable even mid-list among rejected versions. */}
                    {isApproved && (
                      <span className="text-xs font-medium text-success">Use for production</span>
                    )}
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

                {/* Artwork preview on every version, so the list reads at a
                    glance without opening each link. Unrenderable files (PDF
                    proofs) hide themselves; View artwork stays the way in. */}
                {p.artwork_url && <ProofArtworkThumb url={p.artwork_url} version={p.version} />}

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
              );
            })}
          </ul>
        ) : (
          <p className="mt-3 text-sm text-fg-muted">No proofs issued yet.</p>
        )}
      </Card>
    </Motion>
  );

  // Right-edge Cancel control for the status card. Compact (the badge row is
  // tight); the full "what happens" instruction lives in the confirm modal.
  const cancelControl = isCancellable ? (
    <Button variant="danger" size="sm" disabled={busy} onClick={() => setCancelOpen(true)}>
      Cancel order
    </Button>
  ) : undefined;

  // Per-line proof staging: a row per line that needs a proof, above a blocker
  // breakdown so staff see at a glance what is still outstanding. `showSend`
  // adds the one Send button (all DRAFT->SENT in a single buyer email) plus an
  // unsent-DRAFT warning - shown in the proofing states, not on a plain DRAFT
  // quote where the buyer has not even accepted yet.
  const perLineProofPanel = (showSend: boolean) => (
    <div className="flex flex-col gap-3">
      <p className="text-xs text-fg-muted">
        <span className="font-medium text-fg">Proof status:</span> Awaiting buyer{' '}
        {awaitingBuyerCount} · In changes {inChangesCount} · Not prepared {notPreparedCount} ·
        Approved {approvedProofCount}
      </p>

      <div className="flex flex-col divide-y divide-border">
        {perLineProofs.map(({ line, proof }) => (
          <LineProofRow
            key={line.id}
            line={line}
            proof={proof}
            artworkOptions={optionsForLine(line)}
            busy={busy}
            onStage={(ref) => void run(() => stageProof(quote.id, line.id, ref), 'Proof staged')}
            onPickExisting={() => setPickerLineId(line.id)}
            // Dropping a line only works while the line editor is reachable
            // (`canEditLines`) - past that (e.g. a plain staff_admin once the
            // order has been sent) the control used to be a silent no-op, so
            // it is hidden rather than newly permitted.
            onDrop={canEditLines ? () => setEditingLines(true) : undefined}
          />
        ))}
      </div>

      {showSend && (
        <>
          {stagedCount > 0 && (
            <p className="rounded-md border border-warning/30 bg-warning-bg p-2 text-xs text-fg-muted">
              {stagedCount} staged proof{stagedCount === 1 ? '' : 's'}{' '}
              {stagedCount === 1 ? 'has' : 'have'} not been sent to the buyer yet.
            </p>
          )}
          <div>
            <Button
              variant="primary"
              loading={busy}
              disabled={busy || stagedCount === 0}
              onClick={() => void run(() => sendProofs(quote.id), 'Proofs sent to buyer')}
            >
              Send proofs to buyer ({stagedCount} staged)
            </Button>
          </div>
        </>
      )}
    </div>
  );

  // Staff workflow panel, folded INTO the status card (the old standalone "Staff
  // actions" card is gone). The per-state controls carry their own description,
  // which now reads directly under the status badge. The buyer-notification
  // panel closes it out so staff can see what the buyer was told and when they
  // will next hear from us.
  const staffPanel = isStaff ? (
    <div className="flex flex-col gap-5">
      {/* Feature A: which approval the buyer gives first. Editable only while the
          quote is a DRAFT (once sent, the ordering is locked in). The backend
          still enforces the superadmin override, so no client-side signal for
          that is surfaced here - staff just see the control disabled. */}
      <div className="flex flex-col gap-2">
        <span className="text-sm font-medium text-fg">Approval order</span>
        <div role="radiogroup" aria-label="Approval order" className="flex gap-2">
          {(['price_first', 'proof_first'] as const).map((order) => {
            const active = quote.approval_order === order;
            return (
              <Button
                key={order}
                role="radio"
                aria-checked={active}
                size="sm"
                variant={active ? 'primary' : 'outline'}
                disabled={quote.state !== 'DRAFT' || busy}
                onClick={() => {
                  if (!active) void run(() => setApprovalOrder(quote.id, order), 'Approval order updated');
                }}
              >
                {order === 'price_first' ? 'Price first' : 'Proof first'}
              </Button>
            );
          })}
        </div>
      </div>
      <div>
        {quote.state === 'DRAFT' && (
          <div className="flex flex-col gap-4">
            {/* Optional pre-staging: proofs are per-line and can be prepared
                before the buyer accepts. Sending the quote below is a separate,
                param-less send - it does NOT send proofs (those go out once the
                order is in proofing). */}
            {proofLines.length > 0 && (
              <div className="flex flex-col gap-2">
                <span className="text-sm font-medium text-fg">Prepare proofs (optional)</span>
                {perLineProofPanel(false)}
                <p className="text-xs text-fg-subtle">
                  Stage a proof for each customised line now if you like — you can also do this after
                  the buyer accepts. Sending the quote does not send proofs.
                </p>
              </div>
            )}
            <div className="flex flex-col gap-2">
              <div>
                <Button
                  variant="primary"
                  loading={busy}
                  disabled={busy}
                  onClick={() => {
                    // proof_first WITH customised lines sends the proof round;
                    // otherwise (price_first, or plain stock with no proofs) it
                    // is the ordinary param-less quote send.
                    if (quote.approval_order === 'proof_first' && proofLines.length > 0) {
                      void run(() => sendProofs(quote.id), 'Proofs sent to buyer');
                    } else {
                      void run(() => send(quote.id), 'Sent to buyer');
                    }
                  }}
                >
                  Send to buyer
                </Button>
              </div>
              <p className="text-xs text-fg-subtle">
                Emails the quote to the buyer and moves it to Sent. They accept the price to move into
                proofing — changes are requested later, against the proof.
              </p>
            </div>
          </div>
        )}

        {/* CHANGES_REQUESTED included deliberately: staging a revised proof and
            re-sending is how an order gets out of that state. Without this the
            state is a dead end and the order has to be cancelled and rebuilt. */}
        {(quote.state === 'ACCEPTED' ||
          quote.state === 'PROOFING' ||
          quote.state === 'CHANGES_REQUESTED') && (
          <div className="flex flex-col gap-2">
            {proofLines.length > 0 ? (
              perLineProofPanel(true)
            ) : (
              <p className="text-sm text-fg-muted">
                No customised lines on this order to proof.
              </p>
            )}
            <p className="text-xs text-fg-subtle">
              Prepare a proof for each line, then send them to the buyer together. They approve each,
              or send it back with changes.
            </p>
          </div>
        )}

        {/* Renamed from "Issue invoice": the same transaction drives the quote
            through INVOICED to CONFIRMED - the production gate - so say so, and
            confirm before it fires. */}
        {quote.state === 'PROOF_APPROVED' && (
          <div className="flex flex-col gap-2">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
              <div className="flex-1">
                <Input
                  label="PO reference"
                  required
                  placeholder="PO number"
                  hint="Required to raise the invoice and commit the order to production."
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
                  setCommitError(null);
                  setCommitOpen(true);
                }}
              >
                Commit order
              </Button>
            </div>
            <p className="text-xs text-fg-subtle">
              Raises the invoice and confirms the order for production. After this it can no longer be
              edited — you’ll be asked to confirm first.
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
              Checks stock for every line and opens purchasing for anything short, moving the order
              into procurement.
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
              /* The production gate. Nothing reaches the floor on a stock figure
                 alone - most goods are bought in after the order, so a person
                 confirming they are here is the only reliable check. */
              <>
                <p className="text-sm text-fg-muted">
                  Every line is resolved. Check the items above against what has actually arrived,
                  then release the order to production.
                </p>
                <ul className="my-1 flex flex-col gap-1 text-sm text-fg">
                  {quote.line_items
                    ?.filter((li) => li.line_state === 'READY')
                    .map((li) => (
                      <li key={li.id} className="tabular-nums">
                        {li.qty} × {li.product?.name ?? `Product #${li.product_id}`}
                        {li.procurement_note && (
                          <span className="ml-2 text-xs text-warning">⚠ {li.procurement_note}</span>
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

        {/* Waiting on the buyer, not on staff - say which, rather than a bare
            "no action available" that leaves staff wondering whether it's
            stuck. */}
        {quote.state === 'ARTWORK_APPROVED' && (
          <p className="text-sm text-fg-muted">
            Artwork approved. Waiting for the buyer to accept the price — nothing to do here until
            they do.
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

        {/* Shipment visibility on the order page: after a parcel ships, staff
            can see the carrier + consignment and confirm delivery here without
            hunting for the production queue's awaiting-delivery panel. */}
        {quote.shipments && quote.shipments.some((s) => s.state === 'SHIPPED') && (
          <div className="mt-4 flex flex-col gap-3 border-t border-border pt-4">
            <h3 className="text-sm font-semibold text-fg">Shipments</h3>
            <p className="text-xs text-fg-subtle">
              Delivery normally closes automatically from the courier — use “Mark delivered” only if
              that update doesn’t arrive.
            </p>
            {quote.shipments
              .filter((s) => s.state === 'SHIPPED')
              .map((s) => (
                <div key={s.job_id} className="flex flex-wrap items-center justify-between gap-2">
                  <span className="text-sm text-fg-muted">
                    {s.carrier ?? 'Carrier'} · {s.consignment_ref ?? '—'}
                  </span>
                  <Button
                    variant="secondary"
                    size="sm"
                    loading={busy}
                    disabled={busy}
                    onClick={() => run(() => markJobDelivered(quote.id, s.job_id), 'Marked delivered')}
                  >
                    Mark delivered
                  </Button>
                </div>
              ))}
          </div>
        )}
      </div>

      {/* B2B invoice payment reconciliation - staff-only, and only once an
          invoice exists (issueInvoice has fired). There is no Stripe path for
          B2B, so this is how a bank transfer / cheque / cash payment actually
          gets recorded against the order. Sits outside the per-state block
          above because the invoice, once raised, persists across every state
          from INVOICED onward - it is not tied to one step in the flow. */}
      {isStaff && quote.invoice && (
        <div className="flex flex-col gap-3 border-t border-border pt-4">
          <div className="flex items-center gap-2">
            <span className="text-sm font-medium text-fg">Invoice payment</span>
            <Badge tone={PAYMENT_STATE_TONE[quote.invoice.payment_state]}>
              {PAYMENT_STATE_LABEL[quote.invoice.payment_state]}
            </Badge>
          </div>
          {/* LT14: a delivered (CLOSED) order whose invoice is still owed is
              easy to lose - flag it loudly so the money gets chased. */}
          {quote.state === 'CLOSED' &&
            quote.invoice.payment_state !== 'PAID' &&
            quote.invoice.payment_state !== 'VOID' && (
              <p className="rounded-md bg-danger/10 px-3 py-2 text-sm font-medium text-danger">
                Delivered — payment outstanding: {quote.invoice.currency}{' '}
                {(quote.invoice.balance_owed || Number(quote.invoice.amount)).toFixed(2)} still owed.
              </p>
            )}
          {/* H3/M21: show what's been collected and what's still owed, so a
              PARTIAL invoice - or a delivered-but-unpaid order (LT14) - reads
              its own outstanding balance instead of just a status word. */}
          {quote.invoice.balance_owed > 0 &&
            quote.invoice.payment_state !== 'VOID' &&
            quote.invoice.payment_state !== 'UNPAID' && (
              <p className="text-xs text-warning">
                {quote.invoice.currency} {Number(quote.invoice.amount_paid ?? 0).toFixed(2)} collected
                {' · '}
                <span className="font-medium">
                  {quote.invoice.currency} {quote.invoice.balance_owed.toFixed(2)} still owed
                </span>
              </p>
            )}
          {canReconcilePayment && quote.invoice.payment_state !== 'VOID' && (
            <div className="flex flex-col gap-2">
              <div className="flex flex-wrap gap-2">
                <Button
                  variant="secondary"
                  size="sm"
                  loading={busy}
                  disabled={busy || quote.invoice.payment_state === 'PAID'}
                  onClick={() =>
                    run(async () => {
                      await reconcilePayment(quote.id, 'PAID');
                    }, 'Invoice marked paid')
                  }
                >
                  Mark paid
                </Button>
                <Button
                  variant="secondary"
                  size="sm"
                  disabled={busy}
                  onClick={() => {
                    setPartialAmount('');
                    setPartialOpen((o) => !o);
                  }}
                >
                  {partialOpen ? 'Cancel' : 'Record partial payment'}
                </Button>
                <Button
                  variant="danger"
                  size="sm"
                  disabled={busy}
                  onClick={() => {
                    setVoidError(null);
                    setVoidNote('');
                    setVoidOpen(true);
                  }}
                >
                  Void invoice
                </Button>
              </div>
              {partialOpen && (
                <div className="flex flex-wrap items-end gap-2 rounded-md border border-border bg-surface-subtle p-3">
                  <label className="flex flex-col gap-1 text-xs text-fg-muted">
                    Amount received ({quote.invoice.currency})
                    <input
                      type="number"
                      min="0.01"
                      step="0.01"
                      max={Number(quote.invoice.amount) - 0.01}
                      value={partialAmount}
                      onChange={(e) => setPartialAmount(e.target.value)}
                      className="h-10 w-40 rounded-md border border-border-strong bg-surface px-2 text-sm text-fg tabular-nums focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                      placeholder="0.00"
                      aria-label="Partial amount received"
                    />
                  </label>
                  <Button
                    variant="primary"
                    size="sm"
                    loading={busy}
                    disabled={busy || !(Number(partialAmount) > 0 && Number(partialAmount) < Number(quote.invoice.amount))}
                    onClick={() =>
                      run(async () => {
                        const ok = await reconcilePayment(
                          quote.id,
                          'PARTIAL',
                          undefined,
                          Number(partialAmount),
                        );
                        if (ok) setPartialOpen(false);
                      }, 'Partial payment recorded')
                    }
                  >
                    Record
                  </Button>
                  <p className="w-full text-2xs text-fg-subtle">
                    Enter the amount actually received. Must be less than the invoice total of{' '}
                    {quote.invoice.currency} {Number(quote.invoice.amount).toFixed(2)} — a full
                    payment is “Mark paid”. A later cancellation refunds only what was collected.
                  </p>
                </div>
              )}
              <p className="text-xs text-fg-subtle">
                Records the real-world payment outcome for PO {quote.invoice.po_ref} — there is no
                automatic B2B payment capture.
              </p>
            </div>
          )}
          {canReconcilePayment && quote.invoice.payment_state === 'VOID' && (
            <p className="text-xs text-fg-subtle">
              This invoice is void and cannot be reconciled to another state.
            </p>
          )}
        </div>
      )}

      {/* Superadmin-only, and only while a proof is open (awaiting the buyer):
          nudge them by resending the review email, or sign the proof off on
          their behalf. The approval is recorded against the superadmin. */}
      {isSuperadmin && latestOpenProof(quote.proofs) && (
        <div className="flex flex-col gap-2 border-t border-border pt-4">
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
            Resend the review email, or approve the proof yourself — the approval is recorded against
            your name, not the buyer’s.
          </p>
        </div>
      )}

      {/* What the buyer has been told, and when they will next hear from us. */}
      <BuyerNotifications quote={quote} history={history.entries} />
    </div>
  ) : undefined;

  // Buyer "Next step" action card, pinned above the Items list (P4) so a buyer
  // never has to scroll past the order detail to find what to do next.
  const buyerActionsCard = !isStaff &&
    (quote.state === 'DRAFT' ||
      quote.state === 'SENT' ||
      quote.state === 'ARTWORK_APPROVED' ||
      quote.state === 'PROOF_APPROVED') && (
    <Motion variants={staggerItem}>
      <Card padding="lg">
        <h2 className="font-display text-xl text-fg">Next step</h2>
        {/* A buyer can land on a DRAFT after reordering a past order. They
            can't action it themselves - staff review and send a quote - so
            tell them what happens next rather than leaving them guessing. */}
        {quote.state === 'DRAFT' && (
          <p className="mt-4 text-sm text-fg-muted">
            This is a draft order. Our team will review it and send you a quote to approve —
            we’ll email you when it’s ready.
          </p>
        )}
        {quote.state === 'SENT' && (
          <div className="mt-4">
            <p className="mb-3 text-sm text-fg-muted">
              Review the pricing on this page, then accept to move into proofing.
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
            Approving artwork no longer implies agreeing the price, so this is
            the second of the two approvals. */}
        {quote.state === 'ARTWORK_APPROVED' && (
          <div className="mt-4">
            <p className="mb-3 text-sm text-fg-muted">
              Your artwork is approved. Review the pricing on this page and accept it to confirm
              your order.
            </p>
            <Button
              variant="primary"
              loading={busy}
              disabled={busy}
              onClick={() => run(() => accept(quote.id), 'Quote accepted')}
            >
              Accept pricing
            </Button>
          </div>
        )}
        {/* Payment is off for this tenant: say what happens instead, rather
            than offering a button that always fails. */}
        {quote.state === 'PROOF_APPROVED' && !quote.pay_now_enabled && (
          <p className="mt-4 text-sm text-fg-muted">
            Your proof is approved. We’ll send your invoice and confirm your order for production.
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

        {/* Staff: the breadcrumb above is buyer-only, and /orders/:reference
            never matches the /quotes sidebar item, so this was staff's only way
            back to the console list without the browser Back button. */}
        {isStaff && (
          <Motion variants={staggerItem}>
            <Link
              to="/quotes"
              className="inline-flex w-fit items-center gap-1 text-sm text-fg-muted hover:text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-bg"
            >
              <span aria-hidden="true">←</span> Back to Quotes
            </Link>
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
              {/* The state badge itself lives just below, on the OrderStatus
                  card, which carries the richer next-step/step-N-of-9 context -
                  a second plain badge here only repeated the same label. */}
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
            who/when trail folded in behind a disclosure, plus the buyer's
            passive status note folded into the same card so the ball is never
            silently in our court. The note only shows for buyer-facing states
            with no buyer action (states WITH an action surface it through
            their own card); PROOFING's "being prepared" copy is contradictory
            once a proof is actually open for review - the sign-off card below
            covers that case. */}
        <Motion variants={staggerItem}>
          <OrderStatus
            state={quote.state}
            history={history}
            note={
              !isStaff && !(quote.state === 'PROOFING' && latestOpenProof(quote.proofs))
                ? BUYER_STATUS_NOTE[quote.state]
                : undefined
            }
            // Staff: the old "Staff actions" card is folded in here - controls
            // and the buyer-notification panel below the badge, Cancel pinned to
            // the badge row's right edge.
            trailing={isStaff ? cancelControl : undefined}
          >
            {staffPanel}
          </OrderStatus>
        </Motion>

        {/* Buyer proof sign-off - the page's primary action when a proof is
            open, so it sits right under the status glance with the artwork shown
            inline. See `buyerProofReview` above. */}
        {buyerDesignPendingNotice}
        {buyerProofReview}
        {/* Buyer next-step actions pinned above the order detail (P4). */}
        {buyerActionsCard}

        {/* Line items */}
        <Motion variants={staggerItem}>
          <Card padding="none" className="overflow-hidden">
            <div className="flex items-center justify-between gap-3 border-b border-border px-5 py-4">
              <h2 className="font-display text-xl text-fg">Items</h2>
              <div className="flex items-center gap-2">
                {/* Staff-only edit trail for DRAFT amendments, opened as a
                    dialog. The log is only present in staff payloads, so this
                    never shows for a buyer; hidden too when the order was
                    never amended. */}
                {isStaff && quote.amendment_log && quote.amendment_log.length > 0 && (
                  <Button variant="ghost" size="sm" onClick={() => setHistoryOpen(true)}>
                    History
                  </Button>
                )}
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

        {/* Existing-artwork picker (portal) - which artwork stages onto the line
            it was opened for. Picking stages the proof straight away. */}
        <ArtworkPicker
          options={
            pickerLineId !== null
              ? optionsForLine(proofLines.find((l) => l.id === pickerLineId) ?? ({} as LineItem))
              : artworkOptions
          }
          open={pickerLineId !== null}
          onClose={() => setPickerLineId(null)}
          onSelect={(ref) => {
            const lineId = pickerLineId;
            setPickerLineId(null);
            if (lineId !== null) void run(() => stageProof(quote.id, lineId, ref), 'Proof staged');
          }}
        />

        {/* Amendment-log dialog (portal) - triggered from the Items header. */}
        {isStaff && quote.amendment_log && quote.amendment_log.length > 0 && (
          <AmendmentHistory
            entries={quote.amendment_log}
            currency={quote.currency}
            open={historyOpen}
            onClose={() => setHistoryOpen(false)}
          />
        )}

        {/* Proofs history (buyer slot) - reference only, shown once there's no
            line awaiting the buyer; the per-line sign-off lives in the review
            card near the top of the page. See `proofsCard`/`buyerProofReview`. */}
        {!isStaff && !buyerProofReview && quote.proofs && quote.proofs.length > 0 && proofsCard}

        {/* Buyer next-step actions render above Items (P4) - see buyerActionsCard. */}

        {/* Proofs (staff slot) - reference material for staff, so it follows the
            merged status/actions card. See `proofsCard`/`staffPanel` above. */}
        {isStaff && proofsCard}

        {/* The commitment step. Issuing the invoice also drives the order to
            CONFIRMED, which opens production - previously with no indication
            that pressing the button did anything beyond raising an invoice. */}
        {isStaff && (
          <Modal
            open={commitOpen}
            onClose={() => {
              setCommitOpen(false);
              setCommitError(null);
            }}
            title="Commit this order to production?"
            description="This raises the invoice and confirms the order. Production can begin and the order can no longer be edited."
            footer={
              <>
                <Button
                  variant="ghost"
                  onClick={() => {
                    setCommitOpen(false);
                    setCommitError(null);
                  }}
                  disabled={busy}
                >
                  Back
                </Button>
                <Button
                  variant="primary"
                  loading={busy}
                  disabled={busy}
                  onClick={() =>
                    void run(async () => {
                      await issueInvoice(quote.id, poRef.trim(), null);
                      const err = useQuoteStore.getState().actionError;
                      if (!err) {
                        setPoRef('');
                        setCommitOpen(false);
                        setCommitError(null);
                      } else {
                        setCommitError(err);
                      }
                    }, 'Order committed to production')
                  }
                >
                  Commit order
                </Button>
              </>
            }
          >
            {commitError && (
              <p className="rounded-md border border-danger/30 bg-danger-bg p-3 text-sm text-fg">
                {commitError}
              </p>
            )}
          </Modal>
        )}

        {/* Voiding an invoice is terminal - it can never be reconciled to
            another state afterwards - so it is confirmed, like cancelling the
            order itself. */}
        {isStaff && quote.invoice && (
          <Modal
            open={voidOpen}
            onClose={() => {
              setVoidOpen(false);
              setVoidError(null);
              setVoidNote('');
            }}
            title="Void this invoice?"
            description="This marks the invoice void. It can no longer be reconciled to Paid or Partial afterwards — this cannot be undone."
            footer={
              <>
                <Button
                  variant="ghost"
                  onClick={() => {
                    setVoidOpen(false);
                    setVoidError(null);
                  }}
                  disabled={busy}
                >
                  Keep invoice
                </Button>
                <Button
                  variant="danger"
                  loading={busy}
                  disabled={busy}
                  onClick={() =>
                    void run(async () => {
                      const ok = await reconcilePayment(quote.id, 'VOID', voidNote.trim() || undefined);
                      if (ok) {
                        setVoidOpen(false);
                        setVoidError(null);
                        setVoidNote('');
                      } else {
                        setVoidError(useQuoteStore.getState().actionError);
                      }
                    }, 'Invoice voided')
                  }
                >
                  Void invoice
                </Button>
              </>
            }
          >
            {voidError && (
              <p className="rounded-md border border-danger/30 bg-danger-bg p-3 text-sm text-fg">
                {voidError}
              </p>
            )}
            <Textarea
              label="Note"
              hint="Optional — recorded in the audit trail."
              rows={3}
              maxLength={500}
              value={voidNote}
              onChange={(e) => setVoidNote(e.target.value)}
              placeholder="e.g. Buyer disputed the charge."
            />
          </Modal>
        )}

        {isStaff && isCancellable && (
          <Modal
            open={cancelOpen}
            onClose={() => {
              setCancelOpen(false);
              setCancelReason('');
            }}
            title="Cancel this order?"
            description="This stops the order for good and returns any stock already reserved for it. The buyer is emailed that their order was cancelled. This cannot be undone."
            footer={
              <>
                <Button variant="ghost" disabled={busy} onClick={() => setCancelOpen(false)}>
                  Keep order
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
