import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { AnimatePresence, motion } from 'framer-motion';
import { useProcurementStore } from '../stores/procurementStore';
import { Badge, Button, Card, EmptyState, Input, Skeleton, useToast } from '../ui';
import { Motion, fadeInUp, springSoft, useReducedMotionSafe } from '../motion';

/** Raw enum -> human label. Distinct from the generic quoteStatus humanizer -
 * "qty_short" needs to read "Quantity short", not "Qty short". */
const REASON_LABELS: Record<string, string> = {
  qty_short: 'Quantity short',
  price_jumped: 'Price jumped',
};

const reasonLabel = (reason: string): string => REASON_LABELS[reason] ?? reason;

/**
 * What "Accept as-is" actually does to the bill, per QuoteService::reconfirmLine's
 * approve branch: a quantity shortfall re-totals DOWN (qty is clamped to what was
 * procured); a price jump leaves qty untouched and absorbs the increase into
 * margin rather than passing it to the buyer.
 */
const ACCEPT_CONSEQUENCE: Record<string, string> = {
  qty_short: 'Bill only what we can supply (quantity reduced, total lowered).',
  price_jumped: 'Absorb the price rise (buyer pays the quoted price).',
};

interface ResolvedNotice {
  lineItemId: number;
  quoteReference: string | null;
  message: string;
}

function AlertsSkeleton() {
  return (
    <ul className="flex list-none flex-col gap-3 p-0" aria-hidden="true">
      {Array.from({ length: 3 }).map((_, i) => (
        <li key={i}>
          <Card padding="md" className="flex flex-col gap-3">
            <div className="flex items-center justify-between">
              <Skeleton width="40%" height={18} />
              <Skeleton width={90} height={22} />
            </div>
            <Skeleton width="100%" height={40} />
            <Skeleton width="60%" height={36} />
          </Card>
        </li>
      ))}
    </ul>
  );
}

export default function ProcurementPage() {
  const { alerts, loading, error, fetchAlerts, subscribe, unsubscribe, reconfirm } =
    useProcurementStore();
  const [amend, setAmend] = useState<Record<number, { qty: number; unit_price: number }>>({});
  const [pendingId, setPendingId] = useState<number | null>(null);
  const [pendingAction, setPendingAction] = useState<'amend' | 'approve' | 'drop' | null>(null);
  const [lastResolved, setLastResolved] = useState<ResolvedNotice | null>(null);
  const { toast } = useToast();
  const animate = useReducedMotionSafe();

  useEffect(() => {
    // Fetch AND subscribe. The subscription alone only ever showed lines that
    // broke while this page happened to be open.
    void fetchAlerts();
    subscribe(); // live awaiting-reconfirm alerts via Reverb
    return () => unsubscribe();
  }, [fetchAlerts, subscribe, unsubscribe]);

  const setAmendField = (id: number, field: 'qty' | 'unit_price', value: number) =>
    setAmend((s) => {
      const prev = s[id] ?? { qty: 0, unit_price: 0 };
      return { ...s, [id]: { ...prev, [field]: value } };
    });

  // Single-flight guard so rapid double-clicks can't fire a mutation twice.
  //
  // Bug fixed: this used to infer success from `alerts.length` shrinking, but
  // the live `.awaiting-reconfirm` subscription can add an unrelated alert
  // mid-await, which made a genuine success show a red "Could not resolve"
  // toast. The toast is now driven by reconfirm()'s real HTTP outcome.
  const run = async (
    lineItemId: number,
    action: 'amend' | 'approve' | 'drop',
    payload?: { qty: number; unit_price: number },
  ) => {
    if (pendingId !== null) return;
    setPendingId(lineItemId);
    setPendingAction(action);
    try {
      const result = await reconfirm(lineItemId, action, payload);
      if (!result.success) {
        toast({ title: 'Could not resolve line', description: result.error, tone: 'danger' });
      } else if (result.line_state === 'AWAITING_RECONFIRM') {
        // An amend re-procure can fail its own re-check immediately; the line
        // stays on the desk, so say so rather than claiming success.
        toast({
          title: `Line #${lineItemId} still needs a decision`,
          description: 'The re-procure came back short again - amend, accept as-is, or drop.',
          tone: 'warning',
        });
        setLastResolved(null);
      } else {
        const verb = action === 'drop' ? 'dropped' : action === 'approve' ? 'accepted' : 're-procured';
        const nextStep =
          action === 'drop'
            ? 'If every line on this order is now dropped, it auto-cancels. Otherwise it still needs stock confirmation before it releases to the floor.'
            : 'The order still needs stock confirmation before it releases to the floor.';
        toast({ title: `Line #${lineItemId} ${verb}.`, description: nextStep, tone: 'success' });
        setLastResolved({
          lineItemId,
          quoteReference: result.quote_reference ?? null,
          message: nextStep,
        });
      }
    } finally {
      setPendingId(null);
      setPendingAction(null);
    }
  };

  const busy = (id: number, action: 'amend' | 'approve' | 'drop') =>
    pendingId === id && pendingAction === action;

  return (
    <section className="flex flex-col gap-6">
      <Motion variants={fadeInUp} initial="hidden" animate="visible">
        <div className="flex flex-wrap items-center gap-3">
          <h1 className="font-display text-3xl text-fg sm:text-4xl">Procurement desk</h1>
          {alerts.length > 0 && <Badge tone="warning">{alerts.length} awaiting</Badge>}
        </div>
        <p className="mt-1 text-sm text-fg-muted">
          Resolve lines flagged during the stock/price re-check. One line never blocks the rest.
        </p>
      </Motion>

      {error && (
        <p className="rounded-md border border-danger/30 bg-danger-bg px-3 py-2 text-sm text-danger" role="alert">
          {error}
        </p>
      )}

      {lastResolved && (
        <div
          className="flex flex-wrap items-center gap-2 rounded-md border border-border bg-surface-2 px-3 py-2 text-sm text-fg-muted"
          role="status"
        >
          <span>
            Line #{lastResolved.lineItemId} resolved.
            {lastResolved.quoteReference && (
              <>
                {' '}
                <Link
                  to={`/orders/${lastResolved.quoteReference}`}
                  className="font-medium text-brand underline underline-offset-2"
                >
                  View order {lastResolved.quoteReference}
                </Link>
              </>
            )}{' '}
            {lastResolved.message}
          </span>
          <button
            type="button"
            className="ml-auto text-xs text-fg-subtle underline"
            onClick={() => setLastResolved(null)}
          >
            Dismiss
          </button>
        </div>
      )}

      {/* Loading - skeletons on first load only, so a background refetch doesn't
          flash the whole desk away. */}
      {loading && alerts.length === 0 && <AlertsSkeleton />}

      {/* Empty - only once we know it's actually empty (not loading, no error). */}
      {!loading && !error && alerts.length === 0 && (
        <EmptyState
          title="No lines awaiting reconfirmation."
          description="Quantity shortfalls and price jumps from stock re-checks appear here in real time."
        />
      )}

      {alerts.length > 0 && (
        <ul className="flex list-none flex-col gap-3 p-0">
          <AnimatePresence initial={false} mode="popLayout">
            {alerts.map((a) => {
              const draft = amend[a.line_item_id];
              const canAmend = Boolean(draft?.qty) && Boolean(draft?.unit_price);
              const anyBusy = pendingId !== null;
              // The backend rejects approve outright when nothing was sourced
              // (reconfirmLine throws for procured_qty < 1) - disable up front
              // and point staff at Drop instead of letting the request 422.
              const canAcceptAsIs = (a.procured_qty ?? 0) >= 1;
              return (
                <motion.li
                  key={a.line_item_id}
                  layout={animate}
                  initial={animate ? { opacity: 0, y: 12 } : false}
                  animate={{ opacity: 1, y: 0 }}
                  exit={animate ? { opacity: 0, x: 24 } : undefined}
                  transition={springSoft}
                >
                  <Card padding="md" className="flex flex-col gap-4">
                    <div className="flex flex-wrap items-start justify-between gap-2">
                      <div>
                        <p className="font-display text-lg leading-tight text-fg">
                          Line #{a.line_item_id}
                        </p>
                        <p className="text-sm text-fg-muted">Order {a.quote_reference}</p>
                      </div>
                      <Badge tone="warning">{reasonLabel(a.reason)}</Badge>
                    </div>

                    <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm sm:grid-cols-4">
                      <div>
                        <dt className="text-fg-subtle">Ordered qty</dt>
                        <dd className="font-medium text-fg">{a.ordered_qty}</dd>
                      </div>
                      <div>
                        <dt className="text-fg-subtle">Procurable</dt>
                        <dd className="font-medium text-fg">{a.procured_qty ?? '-'}</dd>
                      </div>
                      <div>
                        <dt className="text-fg-subtle">Quoted price</dt>
                        <dd className="font-medium text-fg">{a.unit_price}</dd>
                      </div>
                      <div>
                        <dt className="text-fg-subtle">Re-checked</dt>
                        <dd className="font-medium text-fg">{a.procured_price ?? '-'}</dd>
                      </div>
                    </dl>

                    <div className="flex flex-col gap-3 border-t border-border pt-4">
                      <div className="grid grid-cols-1 gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                        <Input
                          type="number"
                          min={1}
                          label="Amend qty"
                          placeholder="qty"
                          value={draft?.qty ?? ''}
                          disabled={anyBusy}
                          onChange={(e) => setAmendField(a.line_item_id, 'qty', Number(e.target.value))}
                        />
                        <Input
                          type="number"
                          min={0}
                          step="0.01"
                          label="Unit price"
                          placeholder="unit price"
                          value={draft?.unit_price ?? ''}
                          disabled={anyBusy}
                          onChange={(e) =>
                            setAmendField(a.line_item_id, 'unit_price', Number(e.target.value))
                          }
                        />
                        <Button
                          variant="outline"
                          loading={busy(a.line_item_id, 'amend')}
                          disabled={anyBusy || !canAmend}
                          onClick={() => void run(a.line_item_id, 'amend', draft)}
                        >
                          Amend &amp; re-procure
                        </Button>
                      </div>

                      <div className="flex flex-col gap-1.5">
                        <p className="text-xs text-fg-subtle">
                          {canAcceptAsIs
                            ? ACCEPT_CONSEQUENCE[a.reason] ??
                              'Accept the line as procured; the quoted total stands.'
                            : 'Nothing could be sourced for this line - use Drop instead.'}
                        </p>
                        <div className="flex flex-wrap gap-2">
                          <Button
                            loading={busy(a.line_item_id, 'approve')}
                            disabled={
                              !canAcceptAsIs || (anyBusy && !busy(a.line_item_id, 'approve'))
                            }
                            onClick={() => void run(a.line_item_id, 'approve')}
                          >
                            Accept as-is
                          </Button>
                          <Button
                            variant="ghost"
                            loading={busy(a.line_item_id, 'drop')}
                            disabled={anyBusy && !busy(a.line_item_id, 'drop')}
                            onClick={() => void run(a.line_item_id, 'drop')}
                          >
                            Drop line
                          </Button>
                        </div>
                      </div>
                    </div>
                  </Card>
                </motion.li>
              );
            })}
          </AnimatePresence>
        </ul>
      )}
    </section>
  );
}
