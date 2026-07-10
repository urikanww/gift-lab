import { useEffect, useState } from 'react';
import { AnimatePresence, motion } from 'framer-motion';
import { useQueueStore } from '../stores/queueStore';
import api, { apiError } from '../lib/api';
import { Badge, Button, Card, EmptyState, Input, Skeleton, useToast } from '../ui';
import type { BadgeTone } from '../ui';
import { ErrorState } from '../components/ui/States';
import Model3dDecalPreview from '../components/Model3dDecalPreview';
import { fetchArtworkPreviewUrl } from '../lib/uploadArtwork';
import { Motion, fadeInUp, springSoft, useReducedMotionSafe } from '../motion';
import type { JobLineItem, JobState, ModelPart } from '../types';
import type { PrintZone } from '../lib/printZone';

const NEXT_STATE: Partial<Record<JobState, { label: string; to: JobState }>> = {
  READY: { label: 'Start production', to: 'IN_PRODUCTION' },
  IN_PRODUCTION: { label: 'Mark shipped', to: 'SHIPPED' },
  // CLOSED is the buyer-facing "Delivered" stage (Quote::trackingStage maps a
  // closed job to DELIVERED). Label the action for that outcome, not the raw
  // state name, so the floor control reads as the handover it triggers.
  SHIPPED: { label: 'Mark delivered', to: 'CLOSED' },
};

const STATE_META: Record<JobState, { label: string; tone: BadgeTone }> = {
  READY: { label: 'Ready', tone: 'info' },
  IN_PRODUCTION: { label: 'In production', tone: 'warning' },
  SHIPPED: { label: 'Shipped', tone: 'brand' },
  CLOSED: { label: 'Delivered', tone: 'success' },
};

function QueueSkeleton() {
  return (
    <ul className="grid list-none grid-cols-1 gap-3 p-0 sm:grid-cols-2 xl:grid-cols-3" aria-hidden="true">
      {Array.from({ length: 6 }).map((_, i) => (
        <li key={i}>
          <Card padding="md" className="flex flex-col gap-3">
            <div className="flex items-center justify-between">
              <Skeleton width="40%" height={18} />
              <Skeleton width={72} height={22} />
            </div>
            <Skeleton width="60%" height={14} />
            <Skeleton width="50%" height={14} />
            <Skeleton width="100%" height={36} />
          </Card>
        </li>
      ))}
    </ul>
  );
}

/** Pull the server-set filename out of a Content-Disposition header, if present. */
function filenameFromDisposition(header: unknown): string | null {
  if (typeof header !== 'string') return null;
  const match = /filename="?([^"]+)"?/i.exec(header);
  return match ? match[1] : null;
}

export default function ProductionQueuePage() {
  const { jobs, loading, error, fetchQueue, advance, advanceBatch, subscribe, unsubscribe } = useQueueStore();
  const { toast } = useToast();
  const [pendingId, setPendingId] = useState<number | null>(null);
  const [downloadingId, setDownloadingId] = useState<number | null>(null);
  // Shipping is confirm-gated: marking a job shipped requires a consignment ref
  // (that transition fires the buyer's "on the way" signal). This tracks which
  // card is mid-confirmation and its typed reference.
  const [shippingId, setShippingId] = useState<number | null>(null);
  const [consignment, setConsignment] = useState('');
  const [carrier, setCarrier] = useState('');
  // Which job's customization/final-product panel is expanded (view-only).
  const [expandedId, setExpandedId] = useState<number | null>(null);
  // Multi-select for bulk floor actions (start / close many jobs at once).
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const toggleSelected = (id: number) =>
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  const animate = useReducedMotionSafe();

  useEffect(() => {
    void fetchQueue();
    subscribe(); // live via Reverb; no polling
    return () => unsubscribe();
  }, [fetchQueue, subscribe, unsubscribe]);

  // Download the job's print-ready file. The endpoint is Sanctum-gated, so the
  // fetch goes through the authed axios client (cookie + XSRF) as a blob rather
  // than a bare anchor, then a transient object URL triggers the save.
  const onDownloadPrintFile = async (jobId: number) => {
    if (downloadingId !== null) return;
    setDownloadingId(jobId);
    try {
      const res = await api.get(`/production-jobs/${jobId}/print-file`, { responseType: 'blob' });
      const url = URL.createObjectURL(res.data as Blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = filenameFromDisposition(res.headers['content-disposition']) ?? `job-${jobId}-print-file`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
    } catch (err) {
      toast({ title: 'Download failed', description: apiError(err), tone: 'danger' });
    } finally {
      setDownloadingId(null);
    }
  };

  // Single-flight guard against a double-click firing a duplicate advance.
  const onAdvance = async (jobId: number, to: JobState, consignmentRef?: string, carrierVal?: string) => {
    if (pendingId !== null) return;
    setPendingId(jobId);
    try {
      await advance(jobId, to, consignmentRef, carrierVal);
      setShippingId(null);
      setConsignment('');
      setCarrier('');
    } finally {
      setPendingId(null);
    }
  };

  return (
    <section className="flex flex-col gap-6">
      <Motion variants={fadeInUp} initial="hidden" animate="visible">
        <div className="flex flex-wrap items-center gap-3">
          <h1 className="font-display text-3xl text-fg sm:text-4xl">Production queue</h1>
          <Badge tone="success" dot>
            Live
          </Badge>
        </div>
        <p className="mt-1 text-sm text-fg-muted">
          Shared first-come queue by readiness - UV + 3D, no customer priority. Updates in real time.
        </p>
      </Motion>

      {/* Loading - animated skeletons on first load only */}
      {loading && jobs.length === 0 && <QueueSkeleton />}

      {/* Error - retry */}
      {!loading && error && <ErrorState message={error} onRetry={() => void fetchQueue()} />}

      {/* Empty */}
      {!loading && !error && jobs.length === 0 && (
        <EmptyState
          title="The queue is clear."
          description="Jobs appear here the moment a quote is confirmed and ready to make."
        />
      )}

      {/* Bulk floor actions - only while a selection exists */}
      {selected.size > 0 && (
        <div className="flex flex-wrap items-center gap-2 rounded-lg border border-border bg-surface-2 p-3">
          <span className="text-sm text-fg">{selected.size} selected</span>
          <Button
            size="sm"
            variant="secondary"
            onClick={async () => {
              const r = await advanceBatch([...selected], 'IN_PRODUCTION');
              if (r.skipped.length) toast({ title: `${r.skipped.length} skipped (not ready)`, tone: 'warning' });
              setSelected(new Set());
            }}
          >
            Start selected
          </Button>
          <Button
            size="sm"
            variant="secondary"
            onClick={async () => {
              const r = await advanceBatch([...selected], 'CLOSED');
              if (r.skipped.length) toast({ title: `${r.skipped.length} skipped (not shipped)`, tone: 'warning' });
              setSelected(new Set());
            }}
          >
            Close selected
          </Button>
          <Button size="sm" variant="ghost" onClick={() => setSelected(new Set())}>
            Clear
          </Button>
        </div>
      )}

      {/* Board - layout-animated cards; realtime add/remove/reorder via AnimatePresence + layout */}
      {jobs.length > 0 && (
        <motion.ul
          layout={animate}
          className="grid list-none grid-cols-1 gap-3 p-0 sm:grid-cols-2 xl:grid-cols-3"
        >
          <AnimatePresence initial={false} mode="popLayout">
            {jobs.map((j) => {
              const next = NEXT_STATE[j.state];
              const meta = STATE_META[j.state];
              const isPending = pendingId === j.id;
              return (
                <motion.li
                  key={j.id}
                  layout={animate}
                  initial={animate ? { opacity: 0, scale: 0.96 } : false}
                  animate={{ opacity: 1, scale: 1 }}
                  exit={animate ? { opacity: 0, scale: 0.94 } : undefined}
                  transition={springSoft}
                >
                  <Card padding="md" className="flex h-full flex-col gap-3">
                    <div className="flex items-start justify-between gap-2">
                      <div className="flex items-start gap-2">
                        <input
                          type="checkbox"
                          className="mt-1 h-4 w-4 shrink-0"
                          checked={selected.has(j.id)}
                          onChange={() => toggleSelected(j.id)}
                          aria-label={`Select job ${j.id}`}
                        />
                        <div>
                          <p className="font-display text-lg leading-tight text-fg">Job #{j.id}</p>
                          <p className="text-sm text-fg-muted">Quote #{j.quote_id}</p>
                        </div>
                      </div>
                      <Badge tone={meta.tone}>{meta.label}</Badge>
                    </div>

                    <dl className="grid grid-cols-2 gap-x-3 gap-y-1.5 text-sm">
                      <dt className="text-fg-subtle">Track</dt>
                      <dd className="text-right font-medium text-fg">
                        <Badge tone="neutral" size="sm">
                          {j.track}
                        </Badge>
                      </dd>
                      <dt className="text-fg-subtle">Qty</dt>
                      <dd className="text-right font-medium text-fg">{j.qty}</dd>
                      <dt className="text-fg-subtle">Ready at</dt>
                      <dd className="text-right font-medium text-fg">
                        {j.ready_at ? new Date(j.ready_at).toLocaleString() : '-'}
                      </dd>
                    </dl>

                    {!!j.line_items?.length && (
                      <Button
                        variant="ghost"
                        size="sm"
                        fullWidth
                        onClick={() => setExpandedId((v) => (v === j.id ? null : j.id))}
                      >
                        {expandedId === j.id ? 'Hide customization' : 'View customization & final look'}
                      </Button>
                    )}

                    {expandedId === j.id && j.line_items && (
                      <div className="flex flex-col gap-3 border-t border-border pt-3">
                        {j.line_items.map((li) => (
                          <JobLineDetail key={li.id} line={li} />
                        ))}
                      </div>
                    )}

                    {j.artwork_ref && (
                      <Button
                        variant="ghost"
                        size="sm"
                        fullWidth
                        loading={downloadingId === j.id}
                        disabled={downloadingId !== null && downloadingId !== j.id}
                        onClick={() => void onDownloadPrintFile(j.id)}
                      >
                        Download print file
                      </Button>
                    )}

                    {next && next.to === 'SHIPPED' && shippingId === j.id ? (
                      <div className="mt-auto flex flex-col gap-2">
                        <label className="text-sm text-fg-muted">
                          Carrier
                          <select
                            className="mt-1 w-full rounded-md border border-border bg-bg p-2 text-sm text-fg"
                            value={carrier}
                            onChange={(e) => setCarrier(e.target.value)}
                          >
                            <option value="">Select carrier…</option>
                            <option value="SINGPOST">SingPost</option>
                            <option value="NINJAVAN">Ninja Van</option>
                            <option value="JNT">J&amp;T Express</option>
                            <option value="QXPRESS">Qxpress</option>
                            <option value="DHL">DHL</option>
                            <option value="FEDEX">FedEx</option>
                            <option value="OTHER">Other</option>
                          </select>
                        </label>
                        <Input
                          label="Consignment / tracking ref"
                          placeholder="e.g. SP123456789SG"
                          value={consignment}
                          maxLength={128}
                          autoFocus
                          onChange={(e) => setConsignment(e.target.value)}
                        />
                        <div className="flex gap-2">
                          <Button
                            variant="primary"
                            size="sm"
                            fullWidth
                            loading={isPending}
                            disabled={!consignment.trim() || (pendingId !== null && !isPending)}
                            onClick={() => void onAdvance(j.id, 'SHIPPED', consignment.trim(), carrier || undefined)}
                          >
                            Confirm shipped
                          </Button>
                          <Button
                            variant="ghost"
                            size="sm"
                            disabled={isPending}
                            onClick={() => {
                              setShippingId(null);
                              setConsignment('');
                              setCarrier('');
                            }}
                          >
                            Cancel
                          </Button>
                        </div>
                      </div>
                    ) : (
                      next && (
                        <Button
                          variant="secondary"
                          size="sm"
                          fullWidth
                          className="mt-auto"
                          loading={isPending}
                          disabled={pendingId !== null && !isPending}
                          onClick={() =>
                            next.to === 'SHIPPED'
                              ? setShippingId(j.id)
                              : void onAdvance(j.id, next.to)
                          }
                        >
                          {next.label}
                        </Button>
                      )
                    )}
                  </Card>
                </motion.li>
              );
            })}
          </AnimatePresence>
        </motion.ul>
      )}
    </section>
  );
}

const FILAMENT_DOT: Record<string, string> = {
  Black: '#2e2e2e',
  White: '#f1f1ee',
  Grey: '#9c9c9c',
};

/**
 * One production line's saved customization, plus a visualization of the final
 * product: for a 3D item with a stored mesh + print zone, the decorated model
 * (buyer's artwork projected onto the zone in the chosen filament colour);
 * otherwise the saved artwork image. View-only - the floor inspects, edits
 * nothing. The artwork ref is re-signed to a short-lived preview URL on demand.
 */
function JobLineDetail({ line }: { line: JobLineItem }) {
  const product = line.product;
  const c = line.customization;
  const [artworkUrl, setArtworkUrl] = useState<string | null>(null);

  useEffect(() => {
    let active = true;
    const ref = c?.artwork_ref;
    if (!ref) {
      setArtworkUrl(null);
      return;
    }
    void fetchArtworkPreviewUrl(ref).then((url) => {
      if (active) setArtworkUrl(url);
    });
    return () => {
      active = false;
    };
  }, [c?.artwork_ref]);

  const filament = c?.filament_color ?? null;
  const show3d = !!product && product.class === 'MODEL_3D' && product.has_model && !!product.print_zone;

  const { toast } = useToast();
  const parts = product?.model_parts ?? [];
  const [dlPart, setDlPart] = useState<number | null>(null);

  // Download one part's STL for the floor. The admin part stream is staff-gated,
  // so fetch through the authed axios client (cookie + XSRF) as a blob, then a
  // transient object URL triggers the save - same pattern as the print file.
  const downloadPart = async (part: ModelPart) => {
    if (!product || dlPart !== null) return;
    setDlPart(part.id);
    try {
      const res = await api.get(`/admin/products/${product.id}/parts/${part.id}/model`, {
        responseType: 'blob',
      });
      const url = URL.createObjectURL(res.data as Blob);
      const a = document.createElement('a');
      a.href = url;
      const safe = (part.label ?? `part-${part.sort + 1}`).replace(/[^\w.-]+/g, '_');
      a.download = `${product.slug ?? product.id}-${safe}.stl`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
    } catch (err) {
      toast({ title: 'Download failed', description: apiError(err), tone: 'danger' });
    } finally {
      setDlPart(null);
    }
  };

  return (
    <div className="flex flex-col gap-2 rounded-lg bg-surface-2 p-3">
      <div className="flex items-center justify-between gap-2">
        <p className="truncate text-sm font-medium text-fg">{product?.name ?? 'Item'}</p>
        <span className="shrink-0 text-xs text-fg-subtle">&times;{line.qty}</span>
      </div>

      {show3d && product ? (
        <Model3dDecalPreview
          productKey={product.slug ?? String(product.id)}
          // Staff-gated stream: renders even when the product is unpublished.
          modelSrc={`/admin/products/${product.id}/model`}
          filamentColor={filament ?? 'Grey'}
          zone={product.print_zone as PrintZone}
          artworkDataUrl={artworkUrl}
          className="h-56 w-full overflow-hidden rounded-md bg-bg"
        />
      ) : artworkUrl ? (
        <img
          src={artworkUrl}
          alt="Saved artwork"
          className="max-h-56 w-full rounded-md bg-bg object-contain"
        />
      ) : null}

      {c ? (
        <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs">
          {filament && (
            <>
              <dt className="text-fg-subtle">Filament</dt>
              <dd className="flex items-center gap-1.5 text-fg">
                <span
                  className="inline-block h-3 w-3 rounded-full border border-border"
                  style={{ background: FILAMENT_DOT[filament] ?? '#9c9c9c' }}
                />
                {filament}
              </dd>
            </>
          )}
          {c.text && (
            <>
              <dt className="text-fg-subtle">Text</dt>
              <dd className="break-words text-fg">{c.text}</dd>
            </>
          )}
          {c.logo_size && (
            <>
              <dt className="text-fg-subtle">Logo size</dt>
              <dd className="text-fg">{c.logo_size}</dd>
            </>
          )}
          {c.placement_notes && (
            <>
              <dt className="text-fg-subtle">Notes</dt>
              <dd className="break-words text-fg">{c.placement_notes}</dd>
            </>
          )}
          {c.mode && (
            <>
              <dt className="text-fg-subtle">Mode</dt>
              <dd className="text-fg">{c.mode}</dd>
            </>
          )}
        </dl>
      ) : (
        <p className="text-xs text-fg-subtle">No customization on this line.</p>
      )}

      {parts.length > 0 && (
        <div className="flex flex-col gap-1 border-t border-border pt-2">
          <p className="text-xs font-medium text-fg-subtle">Printable parts ({parts.length})</p>
          <ul className="flex flex-col divide-y divide-border/60">
            {parts.map((part) => (
              <li key={part.id} className="flex items-center justify-between gap-2 py-1.5">
                <span className="min-w-0 flex-1 truncate text-xs text-fg">
                  {part.label ?? `Part ${part.sort + 1}`}
                  {part.is_primary && <span className="ml-1 text-2xs text-fg-subtle">(primary)</span>}
                </span>
                <Button
                  variant="ghost"
                  size="sm"
                  loading={dlPart === part.id}
                  disabled={dlPart !== null && dlPart !== part.id}
                  onClick={() => void downloadPart(part)}
                >
                  Download
                </Button>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
}
