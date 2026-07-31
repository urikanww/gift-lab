import { useId, useState, type ReactNode } from 'react';
import { Badge, Card, SkeletonText } from '../../ui';
import { humanizeState, quoteStateTone } from '../../lib/quoteStatus';
import type { QuoteHistoryEntry } from '../../lib/quotes';
import type { QuoteHistory } from '../../lib/useQuoteHistory';
import type { QuoteState } from '../../types';

/**
 * Merged order-status card: the at-a-glance position (current state, what's
 * next, step N of 9) with the recorded who/when trail folded in behind a
 * disclosure. Replaces the old two cards - a stepper and a separate "Status
 * history" - which, once the stepper gained per-step dates, said much the same
 * thing twice. The glance is the summary; expand for the ledger, which is the
 * one that can show loops, off-path hops and the actor behind each change.
 */

/**
 * Ordered happy-path lifecycle, for the glance position only. INVOICED is
 * deliberately omitted (M4): it's an atomic pass-through the order never rests
 * in (issueInvoice transitions Invoiced→Confirmed in one step), so listing it
 * made the buyer's counter jump 5→7 with step 6 never rendering. The remaining
 * eight are states an order can actually sit in.
 */
const TIMELINE: QuoteState[] = [
  'DRAFT',
  'SENT',
  'ACCEPTED',
  'PROOFING',
  'PROOF_APPROVED',
  'CONFIRMED',
  'PROCURING',
  'READY',
];

/**
 * The day transition logging shipped. Orders created before it have no trail and
 * never will, so the empty state names the boundary rather than implying the
 * order never moved. A fixed historical fact - see the old StatusHistory note.
 */
const TRACKING_STARTED = '20 July 2026';

function isOnPath(state: QuoteState): boolean {
  return TIMELINE.indexOf(state) !== -1;
}

/**
 * Buyer-facing progress (F4). The eight internal states collapse to four plain
 * stages a customer understands; the raw state name + "step N of 8" counter are
 * staff-only. -1 = off-track (CANCELLED), rendered as a plain note instead.
 *
 * A plain-stock order (no line needs a proof) skips proofing entirely — Accepted
 * auto-advances straight past it — so its "Proof" stage is dropped rather than
 * left dangling as a step the order will never reach (#5).
 */
const BUYER_STAGES = ['Quote', 'Proof', 'Production', 'Delivered'] as const;

function buyerStageIndex(state: QuoteState): number {
  switch (state) {
    case 'DRAFT':
    case 'SENT':
    case 'ACCEPTED':
    case 'ARTWORK_APPROVED':
      return 0;
    case 'PROOFING':
      return 1;
    case 'PROOF_APPROVED':
    case 'CONFIRMED':
    case 'PROCURING':
    case 'READY':
      return 2;
    case 'CLOSED':
      return 3;
    default:
      return -1;
  }
}

function BuyerProgress({ state, needsProof }: { state: QuoteState; needsProof: boolean }) {
  // Drop the Proof stage for a plain-stock order. Everything after it shifts
  // down one, so the four-stage index maps onto the three-stage list.
  const stages = needsProof ? BUYER_STAGES : BUYER_STAGES.filter((s) => s !== 'Proof');
  const full = buyerStageIndex(state);
  const current = needsProof ? full : full <= 0 ? 0 : full - 1;
  const complete = state === 'CLOSED';
  return (
    <ol className="mt-3 flex items-center gap-2" aria-label="Order progress">
      {stages.map((label, i) => {
        const done = i < current || complete;
        const active = i === current && !complete;
        return (
          <li key={label} className="flex flex-1 items-center gap-2">
            <span
              aria-hidden="true"
              className={
                'h-2 w-2 shrink-0 rounded-full ' +
                (done || active ? 'bg-primary' : 'bg-border-strong')
              }
            />
            <span
              className={
                'text-xs ' +
                (active ? 'font-medium text-fg' : done ? 'text-fg-muted' : 'text-fg-subtle')
              }
            >
              {label}
            </span>
            {i < stages.length - 1 && (
              <span
                aria-hidden="true"
                className={'h-px flex-1 ' + (done ? 'bg-primary' : 'bg-border')}
              />
            )}
          </li>
        );
      })}
    </ol>
  );
}

/** The state that honestly follows, or null (off-path, or READY as the last). */
function nextState(state: QuoteState): QuoteState | null {
  const i = TIMELINE.indexOf(state);
  if (i === -1 || i === TIMELINE.length - 1) return null;
  return TIMELINE[i + 1];
}

/** Actor label. A transition with no human behind it is a real thing. */
function actorLabel(entry: QuoteHistoryEntry): string {
  return entry.actor_name?.trim() || 'System';
}

/**
 * Date AND time. Orders move through several states in one day - the endpoint
 * orders by (created_at, id) precisely because transitions can share a second -
 * so a bare date would render consecutive entries identically.
 */
function formatChangedAt(changedAt: string | null): string | null {
  if (!changedAt) return null;
  const at = new Date(changedAt);
  if (Number.isNaN(at.getTime())) return null;
  return at.toLocaleString(undefined, {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

export default function OrderStatus({
  state,
  history,
  note,
  description,
  trailing,
  children,
  audience = 'staff',
  needsProof = true,
}: {
  state: QuoteState;
  history: QuoteHistory;
  /** Whether this order has a proofing stage. Plain-stock orders (no proof-
   *  needing line) drop the buyer stepper's "Proof" stage. Defaults true so a
   *  caller without the aggregate keeps the stage. */
  needsProof?: boolean;
  /**
   * Who's looking. 'staff' gets the full internal glance (next state, step N of
   * 8, status-history trail); 'buyer' gets the friendly stage progress only -
   * never the raw state machine or the who/when audit ledger (F4).
   */
  audience?: 'staff' | 'buyer';
  /** Optional passive status note (e.g. buyer's "what happens next" copy),
      rendered under the badge row so the glance and the note share one card. */
  note?: string;
  /** Staff-facing state description, rendered under the badge in place of the
      buyer note. Distinct from `note` so the two audiences never collide. */
  description?: ReactNode;
  /** Right-edge slot on the badge row (e.g. the staff Cancel control), sitting
      before the history toggle. */
  trailing?: ReactNode;
  /** Staff workflow controls + notification panel, folded into this card so the
      old separate "Staff actions" card is gone. Rendered under the description. */
  children?: ReactNode;
}) {
  const [expanded, setExpanded] = useState(false);
  const listId = useId();
  const { entries, loading, failed } = history;

  const onPath = isOnPath(state);
  const next = nextState(state);
  const cancelled = state === 'CANCELLED';
  const complete = state === 'CLOSED';
  const idx = TIMELINE.indexOf(state);
  const isStaffView = audience === 'staff';

  // API order is oldest first; the most recent change is what a reader wants.
  const newestFirst = [...entries].reverse();

  return (
    <Card padding="md" aria-labelledby="order-status-heading">
      <h2 id="order-status-heading" className="sr-only">
        Order status
      </h2>

      <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
        <Badge tone={quoteStateTone(state)} dot>
          {humanizeState(state)}
        </Badge>

        {/* Internal glance — staff only. Buyers must never see the raw next
            state or the "step N of 8" counter (F4); their friendly progress
            renders below the badge row instead. */}
        {isStaffView && next && (
          <span className="text-sm text-fg-muted">
            <span aria-hidden="true">→</span> next: {humanizeState(next)}
          </span>
        )}

        {cancelled && (
          <span className="text-sm text-fg-muted">
            This {isStaffView ? 'quote' : 'order'} was cancelled.
          </span>
        )}

        {isStaffView && onPath && (
          <span className="text-xs text-fg-subtle">
            step {idx + 1} of {TIMELINE.length}
          </span>
        )}

        {/* CLOSED sits off the path, so it has no slot to count. */}
        {isStaffView && complete && <span className="text-xs text-fg-subtle">All steps complete</span>}

        {/* Right edge: the staff Cancel control (when supplied) sits furthest
            right, with the history toggle beside it. `ml-auto` on the group
            pushes both to the edge regardless of which are present. The status
            history is a staff audit ledger (actor names + raw states), so the
            toggle is staff-only. */}
        {isStaffView && (
          <div className="ml-auto flex items-center gap-3">
            <button
              type="button"
              aria-expanded={expanded}
              aria-controls={listId}
              onClick={() => setExpanded((v) => !v)}
              className="rounded-md text-xs font-medium text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-bg"
            >
              {expanded ? 'Hide history' : 'Show history'}
            </button>
            {trailing}
          </div>
        )}
      </div>

      {/* Buyer-facing friendly progress (F4), in place of the internal counter. */}
      {!isStaffView && !cancelled && <BuyerProgress state={state} needsProof={needsProof} />}

      {/* Under the badge: the buyer's passive note OR the staff state
          description - never both, as a page passes one or the other. */}
      {note && <p className="mt-3 text-sm text-fg-muted">{note}</p>}
      {description && <div className="mt-3 text-sm text-fg-muted">{description}</div>}

      {/* Staff controls + notification panel, folded into this card. */}
      {children && <div className="mt-4">{children}</div>}

      {isStaffView && expanded && (
        <div
          id={listId}
          role="region"
          aria-label="Status history"
          aria-busy={loading}
          className="mt-4 border-t border-border pt-4"
        >
          <h3 className="text-sm font-medium text-fg">Status history</h3>

          {loading ? (
            <SkeletonText lines={2} className="mt-3" />
          ) : newestFirst.length > 0 ? (
            /* Vertical timeline: a marker per change with a connector running
               between them, so the trail reads as a sequence rather than a flat
               table. Newest at the top - its marker is filled to mark "now". */
            <ol className="mt-4 flex flex-col">
              {newestFirst.map((entry, i) => {
                const when = formatChangedAt(entry.changed_at);
                const isLatest = i === 0;
                const isLast = i === newestFirst.length - 1;
                return (
                  <li
                    key={`${entry.changed_at ?? 'unknown'}-${entry.to ?? 'unknown'}-${i}`}
                    className="relative flex gap-4 pb-5 last:pb-0"
                  >
                    {/* Connector: from this marker down to the next. Hidden on the
                        last row so the line stops at the oldest entry. */}
                    {!isLast && (
                      <span
                        aria-hidden="true"
                        className="absolute left-[5px] top-3 h-full w-px bg-border"
                      />
                    )}
                    {/* Marker. The latest change is filled (brand); older ones are
                        hollow, so the eye lands on where the order is now. */}
                    <span
                      aria-hidden="true"
                      className={`relative z-10 mt-1.5 h-[11px] w-[11px] shrink-0 rounded-full border-2 ${
                        isLatest ? 'border-primary bg-primary' : 'border-border-strong bg-surface'
                      }`}
                    />
                    <div className="flex flex-1 flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
                      <span className="font-medium text-fg">
                        {entry.to ? humanizeState(entry.to) : 'Unknown status'}
                      </span>
                      <span className="flex flex-wrap items-baseline gap-x-2 text-sm text-fg-muted">
                        {when && <time dateTime={entry.changed_at ?? undefined}>{when}</time>}
                        <span aria-hidden="true" className="text-fg-subtle">
                          ·
                        </span>
                        <span>{actorLabel(entry)}</span>
                      </span>
                    </div>
                  </li>
                );
              })}
            </ol>
          ) : failed ? (
            <p className="mt-3 text-sm text-fg-muted">Couldn’t load the status history.</p>
          ) : (
            <p className="mt-3 text-sm text-fg-muted">
              Status tracking started on {TRACKING_STARTED}. Changes before then were not recorded.
            </p>
          )}
        </div>
      )}
    </Card>
  );
}
