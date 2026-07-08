import { useEffect, useState } from 'react';
import { AnimatePresence, motion } from 'framer-motion';
import { useProcurementStore } from '../stores/procurementStore';
import { Badge, Button, Card, EmptyState, Input, useToast } from '../ui';
import { Motion, fadeInUp, springSoft, useReducedMotionSafe } from '../motion';

export default function ProcurementPage() {
  const { alerts, error, subscribe, unsubscribe, reconfirm } = useProcurementStore();
  const [amend, setAmend] = useState<Record<number, { qty: number; unit_price: number }>>({});
  const [pendingId, setPendingId] = useState<number | null>(null);
  const [pendingAction, setPendingAction] = useState<'amend' | 'approve' | 'drop' | null>(null);
  const { toast } = useToast();
  const animate = useReducedMotionSafe();

  useEffect(() => {
    subscribe(); // live awaiting-reconfirm alerts via Reverb
    return () => unsubscribe();
  }, [subscribe, unsubscribe]);

  const setAmendField = (id: number, field: 'qty' | 'unit_price', value: number) =>
    setAmend((s) => {
      const prev = s[id] ?? { qty: 0, unit_price: 0 };
      return { ...s, [id]: { ...prev, [field]: value } };
    });

  // Single-flight guard so rapid double-clicks can't fire a mutation twice.
  const run = async (
    lineItemId: number,
    action: 'amend' | 'approve' | 'drop',
    payload?: { qty: number; unit_price: number },
  ) => {
    if (pendingId !== null) return;
    setPendingId(lineItemId);
    setPendingAction(action);
    const before = useProcurementStore.getState().alerts.length;
    try {
      await reconfirm(lineItemId, action, payload);
      // The store removes the alert only on success; use that to signal outcome.
      const after = useProcurementStore.getState().alerts.length;
      if (after < before) {
        const verb = action === 'drop' ? 'dropped' : action === 'approve' ? 'accepted' : 're-procured';
        toast({ title: `Line #${lineItemId} ${verb}.`, tone: 'success' });
      } else {
        toast({
          title: 'Could not resolve line',
          description: useProcurementStore.getState().error ?? undefined,
          tone: 'danger',
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

      {alerts.length === 0 ? (
        <EmptyState
          title="No lines awaiting reconfirmation."
          description="Quantity shortfalls and price jumps from stock re-checks appear here in real time."
        />
      ) : (
        <ul className="flex list-none flex-col gap-3 p-0">
          <AnimatePresence initial={false} mode="popLayout">
            {alerts.map((a) => {
              const draft = amend[a.line_item_id];
              const canAmend = Boolean(draft?.qty) && Boolean(draft?.unit_price);
              const anyBusy = pendingId !== null;
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
                        <p className="text-sm text-fg-muted">Quote #{a.quote_id}</p>
                      </div>
                      <Badge tone="warning">{a.reason}</Badge>
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

                      <div className="flex flex-wrap gap-2">
                        <Button
                          loading={busy(a.line_item_id, 'approve')}
                          disabled={anyBusy && !busy(a.line_item_id, 'approve')}
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
