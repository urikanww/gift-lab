import { useEffect, useState } from 'react';
import { useQueueStore } from '../../stores/queueStore';
import { Badge, Button, Card, Modal, Textarea, useToast } from '../../ui';
import type { ProductionJob } from '../../types';

type Disposition = 'reship' | 'close' | 'cancel_credit';

/**
 * Returned/failed parcels (F10). Each shows the courier status and three
 * resolutions calling the existing POST /production-jobs/{job}/resolve-return:
 *   - Reship: re-queue for a fresh consignment (job → IN_PRODUCTION).
 *   - Close (write off): accept the loss, close the job.
 *   - Cancel & credit: void this parcel's share + credit what was collected
 *     (money-moving, so gated behind a confirm dialog).
 * Renders nothing when there are no returned parcels.
 */
export default function NeedsAttentionPanel() {
  const needsAttention = useQueueStore((s) => s.needsAttention);
  const fetchNeedsAttention = useQueueStore((s) => s.fetchNeedsAttention);
  const resolveReturn = useQueueStore((s) => s.resolveReturn);
  const { toast } = useToast();

  const [confirm, setConfirm] = useState<{ job: ProductionJob; disposition: Disposition } | null>(null);
  const [note, setNote] = useState('');
  const [busyId, setBusyId] = useState<number | null>(null);

  useEffect(() => {
    void fetchNeedsAttention();
  }, [fetchNeedsAttention]);

  if (needsAttention.length === 0) return null;

  const run = async (job: ProductionJob, disposition: Disposition, withNote?: string) => {
    if (busyId !== null) return;
    setBusyId(job.id);
    const ok = await resolveReturn(job.id, disposition, withNote || undefined);
    setBusyId(null);
    if (ok) {
      toast({
        title: `Parcel ${disposition === 'reship' ? 'reshipped' : disposition === 'close' ? 'closed' : 'cancelled & credited'} — ${job.quote_reference ?? 'order'}`,
        tone: 'success',
      });
      setConfirm(null);
      setNote('');
    } else {
      toast({ title: 'Could not resolve parcel', description: 'Please try again.', tone: 'danger' });
    }
  };

  return (
    <Card padding="md" className="flex flex-col gap-3 border-l-4 border-l-danger">
      <div className="flex flex-wrap items-center gap-2">
        <h2 className="font-display text-xl text-fg">Needs attention</h2>
        <Badge tone="danger" size="sm">{needsAttention.length}</Badge>
      </div>
      <p className="text-sm text-fg-muted">
        Parcels the courier returned or couldn’t deliver. Reship for a fresh consignment, close to
        write off, or cancel &amp; credit the buyer.
      </p>

      <ul className="flex list-none flex-col divide-y divide-border p-0">
        {needsAttention.map((j) => (
          <li key={j.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
            <div className="min-w-0">
              <p className="font-medium text-fg">{j.quote_reference ?? `Order #${j.quote_id}`}</p>
              <p className="mt-0.5 text-xs text-fg-subtle">
                {j.carrier_label ?? j.carrier ?? 'Courier'}
                {j.consignment_ref ? ` · ${j.consignment_ref}` : ''}
                {j.last_courier_status ? ` · ${j.last_courier_status}` : ''}
              </p>
            </div>
            <div className="flex flex-wrap gap-2">
              <Button variant="secondary" size="sm" loading={busyId === j.id} disabled={busyId !== null && busyId !== j.id} onClick={() => void run(j, 'reship')}>
                Reship
              </Button>
              <Button variant="ghost" size="sm" disabled={busyId !== null} onClick={() => void run(j, 'close')}>
                Close (write off)
              </Button>
              <Button variant="ghost" size="sm" disabled={busyId !== null} onClick={() => { setConfirm({ job: j, disposition: 'cancel_credit' }); setNote(''); }}>
                Cancel &amp; credit
              </Button>
            </div>
          </li>
        ))}
      </ul>

      <Modal
        open={confirm !== null}
        onClose={() => (busyId !== null ? undefined : setConfirm(null))}
        title={`Cancel & credit — ${confirm?.job.quote_reference ?? 'order'}?`}
        footer={
          <>
            <Button variant="ghost" disabled={busyId !== null} onClick={() => setConfirm(null)}>
              Cancel
            </Button>
            <Button
              variant="primary"
              loading={busyId !== null}
              onClick={() => confirm && void run(confirm.job, 'cancel_credit', note.trim())}
            >
              Confirm
            </Button>
          </>
        }
      >
        <div className="flex flex-col gap-3">
          <p className="text-sm text-fg-muted">
            Voids this parcel’s share and credits what was collected. On a multi-parcel order only this
            parcel is affected; the order stays live. This can’t be undone.
          </p>
          <Textarea label="Note (optional)" value={note} onChange={(e) => setNote(e.target.value)} rows={2} />
        </div>
      </Modal>
    </Card>
  );
}
