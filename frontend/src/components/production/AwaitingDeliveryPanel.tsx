import { useEffect, useState } from 'react';
import { useQueueStore } from '../../stores/queueStore';
import { Badge, Button, Card, Modal, Textarea, useToast } from '../../ui';
import type { ProductionJob } from '../../types';

/**
 * Staff fallback for a delivery whose NinjaVan webhook never arrived: lists
 * jobs that are SHIPPED-but-not-yet-delivered and lets staff confirm delivery
 * by hand (closing the job + order and firing the buyer's "delivered" email,
 * exactly as the webhook would).
 *
 * Deliberately separate from the FCFS production board above it - these jobs
 * have left the floor and are in the courier's hands; this is the one place to
 * nudge them to Delivered when the courier goes quiet. Returned/failed parcels
 * are NOT here (they route to the returned-parcel resolution instead), so the
 * only action offered is a clean "mark delivered".
 */
export default function AwaitingDeliveryPanel() {
  const inTransit = useQueueStore((s) => s.inTransit);
  const fetchInTransit = useQueueStore((s) => s.fetchInTransit);
  const markDelivered = useQueueStore((s) => s.markDelivered);
  const { toast } = useToast();

  const [confirmJob, setConfirmJob] = useState<ProductionJob | null>(null);
  const [note, setNote] = useState('');
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    void fetchInTransit();
  }, [fetchInTransit]);

  if (inTransit.length === 0) return null;

  const open = (job: ProductionJob) => {
    setConfirmJob(job);
    setNote('');
  };

  const confirm = async () => {
    if (!confirmJob || busy) return;
    setBusy(true);
    const ok = await markDelivered(confirmJob.id, note.trim() || undefined);
    setBusy(false);
    if (ok) {
      toast({ title: `Marked delivered — ${confirmJob.quote_reference ?? 'order'}`, tone: 'success' });
      setConfirmJob(null);
    } else {
      toast({ title: 'Could not mark delivered', description: 'Please try again.', tone: 'danger' });
    }
  };

  return (
    <Card padding="md" className="flex flex-col gap-3 border-l-4 border-l-brand">
      <div className="flex flex-wrap items-center gap-2">
        <h2 className="font-display text-xl text-fg">Awaiting delivery</h2>
        <Badge tone="brand" size="sm">
          {inTransit.length}
        </Badge>
      </div>
      <p className="text-sm text-fg-muted">
        Shipped parcels not yet confirmed delivered. Delivery normally closes automatically from the
        courier — use “Mark delivered” only if that update doesn’t arrive.
      </p>

      <ul className="flex list-none flex-col divide-y divide-border p-0">
        {inTransit.map((j) => (
          <li key={j.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
            <div className="min-w-0">
              <p className="font-medium text-fg">{j.quote_reference ?? `Order #${j.quote_id}`}</p>
              <p className="mt-0.5 text-xs text-fg-subtle">
                {j.carrier_label ?? j.carrier ?? 'Courier'}
                {j.consignment_ref ? (
                  <>
                    {' · '}
                    {j.tracking_url ? (
                      <a
                        href={j.tracking_url}
                        target="_blank"
                        rel="noreferrer"
                        className="underline hover:text-fg"
                      >
                        {j.consignment_ref}
                      </a>
                    ) : (
                      <span className="tabular-nums">{j.consignment_ref}</span>
                    )}
                  </>
                ) : null}
                {j.last_courier_status ? ` · ${j.last_courier_status}` : ''}
              </p>
            </div>
            <Button variant="secondary" size="sm" onClick={() => open(j)}>
              Mark delivered
            </Button>
          </li>
        ))}
      </ul>

      <Modal
        open={confirmJob !== null}
        onClose={() => (busy ? undefined : setConfirmJob(null))}
        title={`Mark ${confirmJob?.quote_reference ?? 'order'} delivered?`}
        footer={
          <>
            <Button variant="ghost" disabled={busy} onClick={() => setConfirmJob(null)}>
              Cancel
            </Button>
            <Button variant="primary" loading={busy} onClick={() => void confirm()}>
              Mark delivered
            </Button>
          </>
        }
      >
        <div className="flex flex-col gap-3">
          <p className="text-sm text-fg-muted">
            This closes the order and tells the buyer it was delivered. Use it only when the courier’s
            own delivery update hasn’t come through. If the parcel was returned or failed, use the
            returned-parcel resolution instead.
          </p>
          <Textarea
            label="Note (optional)"
            placeholder="e.g. Buyer confirmed receipt by phone."
            value={note}
            onChange={(e) => setNote(e.target.value)}
            rows={2}
          />
        </div>
      </Modal>
    </Card>
  );
}
