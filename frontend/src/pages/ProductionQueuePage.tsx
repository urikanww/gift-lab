import { useEffect, useRef, useState, type ChangeEvent } from 'react';
import { AnimatePresence, motion } from 'framer-motion';
import { useQueueStore } from '../stores/queueStore';
import JobLabel from '../components/JobLabel';
import api, { apiError } from '../lib/api';
import { Badge, Button, Card, EmptyState, Input, Skeleton, Textarea, useToast } from '../ui';
import type { BadgeTone } from '../ui';
import { ErrorState } from '../components/ui/States';
import Model3dDecalPreview from '../components/Model3dDecalPreview';
import { fetchArtworkPreviewUrl } from '../lib/uploadArtwork';
import { Motion, fadeInUp, springSoft, useReducedMotionSafe } from '../motion';
import type { JobLineItem, JobState, ModelPart, ShippingAddressInput } from '../types';
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
  const {
    jobs,
    loading,
    error,
    fetchQueue,
    advance,
    advanceBatch,
    advanceNext,
    createShipment,
    subscribe,
    unsubscribe,
  } = useQueueStore();
  const { toast } = useToast();
  const [pendingId, setPendingId] = useState<number | null>(null);
  const [downloadingId, setDownloadingId] = useState<number | null>(null);
  // Which job's delivery-address panel is expanded (staff editor).
  const [addressPanelId, setAddressPanelId] = useState<number | null>(null);
  // Quote ids known to have a delivery address (loaded or just saved) - gates the
  // automated NinjaVan create-shipment button so we don't fire a doomed 422.
  const [addressReady, setAddressReady] = useState<Set<number>>(new Set());
  // Single-flight guard for the automated NinjaVan shipment booking.
  const [creatingShipmentId, setCreatingShipmentId] = useState<number | null>(null);
  const markAddressReady = (quoteId: number) =>
    setAddressReady((prev) => (prev.has(quoteId) ? prev : new Set(prev).add(quoteId)));
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
  // Scan-to-advance: hardware keyboard-wedge (Enter on the input) or camera.
  const [scanValue, setScanValue] = useState('');
  const [cameraOn, setCameraOn] = useState(false);
  const [labelJobId, setLabelJobId] = useState<number | null>(null);
  const stopCameraRef = useRef<null | (() => Promise<void>)>(null);
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

  // Release the camera if the page unmounts while a scan is running.
  useEffect(() => () => void stopCameraRef.current?.(), []);

  // Resolve a scanned/typed value to a queued job and advance it. Surfaces the
  // store error (e.g. the backend's 422 SHIPPED-guard) as a toast.
  const onScan = async (raw: string) => {
    const id = Number(String(raw).trim());
    if (!Number.isInteger(id) || id <= 0) return;
    if (!jobs.some((j) => j.id === id)) {
      toast({ title: `Job #${id} not on the queue`, tone: 'warning' });
      return;
    }
    await advanceNext(id);
    const err = useQueueStore.getState().error;
    if (err) toast({ title: err, tone: 'warning' });
    setScanValue('');
  };

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

  // Automated NinjaVan path (separate from the manual consignment-entry flow).
  // The store action throws on error and refetches on success (flipping the row
  // to SHIPPED); we toast the tracking ref, or the 422/502 message on failure.
  const onCreateShipment = async (jobId: number) => {
    if (creatingShipmentId !== null) return;
    setCreatingShipmentId(jobId);
    try {
      const res = await createShipment(jobId);
      const trackingLine = res.consignment_ref ? `Tracking ${res.consignment_ref}` : 'Shipment created';
      toast({
        title: 'Shipment created',
        description: res.tracking_url ? `${trackingLine} - ${res.tracking_url}` : trackingLine,
        tone: 'success',
      });
    } catch (err) {
      toast({ title: 'Could not create shipment', description: apiError(err), tone: 'danger' });
    } finally {
      setCreatingShipmentId(null);
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

      {/* Scan-to-advance: hardware wedge scanner (Enter) or rear camera */}
      <div className="flex flex-wrap items-end gap-2">
        <Input
          label="Scan to advance"
          placeholder="Scan or type job #, then Enter"
          value={scanValue}
          onChange={(e) => setScanValue(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === 'Enter') void onScan(scanValue);
          }}
        />
        <Button
          variant="ghost"
          size="sm"
          onClick={async () => {
            if (cameraOn) {
              await stopCameraRef.current?.();
              stopCameraRef.current = null;
              setCameraOn(false);
            } else {
              setCameraOn(true);
              const { startCameraScan } = await import('../lib/scan');
              stopCameraRef.current = await startCameraScan('qr-reader', (v) => void onScan(v));
            }
          }}
        >
          {cameraOn ? 'Stop camera' : 'Scan with camera'}
        </Button>
      </div>
      {cameraOn && <div id="qr-reader" className="w-full max-w-xs" />}

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

                    <Button variant="ghost" size="sm" fullWidth onClick={() => setLabelJobId(j.id)}>
                      Print label
                    </Button>

                    <Button
                      variant="ghost"
                      size="sm"
                      fullWidth
                      onClick={() => setAddressPanelId((v) => (v === j.id ? null : j.id))}
                    >
                      {addressPanelId === j.id ? 'Hide delivery address' : 'Delivery address'}
                    </Button>

                    {addressPanelId === j.id && (
                      <DeliveryAddressPanel
                        quoteId={j.quote_id}
                        onLoaded={(hasAddress) => hasAddress && markAddressReady(j.quote_id)}
                        onSaved={() => markAddressReady(j.quote_id)}
                      />
                    )}

                    {j.state === 'IN_PRODUCTION' && (
                      <Button
                        variant="secondary"
                        size="sm"
                        fullWidth
                        loading={creatingShipmentId === j.id}
                        disabled={
                          !addressReady.has(j.quote_id) ||
                          (creatingShipmentId !== null && creatingShipmentId !== j.id)
                        }
                        onClick={() => void onCreateShipment(j.id)}
                      >
                        Create NinjaVan shipment
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

      {labelJobId !== null && <JobLabel jobId={labelJobId} onClose={() => setLabelJobId(null)} />}
    </section>
  );
}

/**
 * Staff delivery-address editor for a quote, shown inline on the job card. On
 * expand it loads the saved (or company-defaulted) address and prefills a small
 * form; saving PUTs the writable fields. `onLoaded`/`onSaved` let the parent
 * know an address exists so the automated create-shipment button can enable.
 */
function DeliveryAddressPanel({
  quoteId,
  onLoaded,
  onSaved,
}: {
  quoteId: number;
  onLoaded: (hasAddress: boolean) => void;
  onSaved: () => void;
}) {
  const fetchShippingAddress = useQueueStore((s) => s.fetchShippingAddress);
  const saveShippingAddress = useQueueStore((s) => s.saveShippingAddress);
  const { toast } = useToast();
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [form, setForm] = useState<Record<keyof ShippingAddressInput, string>>({
    recipient_name: '',
    phone: '',
    line1: '',
    postal_code: '',
    email: '',
    line2: '',
    city: '',
    state: '',
    country: '',
    notes: '',
  });

  useEffect(() => {
    let active = true;
    setLoading(true);
    void fetchShippingAddress(quoteId)
      .then(({ address: addr, saved }) => {
        if (!active) return;
        setForm({
          recipient_name: addr.recipient_name ?? '',
          phone: addr.phone ?? '',
          line1: addr.line1 ?? '',
          postal_code: addr.postal_code ?? '',
          email: addr.email ?? '',
          line2: addr.line2 ?? '',
          city: addr.city ?? '',
          state: addr.state ?? '',
          country: addr.country ?? '',
          notes: addr.notes ?? '',
        });
        // Gate the create-shipment button on a persisted row, not a defaulted
        // line1 - the company free-text default is not a shippable structured address.
        onLoaded(saved);
      })
      .catch((err) => {
        if (active) {
          toast({ title: 'Could not load delivery address', description: apiError(err), tone: 'danger' });
        }
      })
      .finally(() => {
        if (active) setLoading(false);
      });
    return () => {
      active = false;
    };
    // Reload only when the target quote changes; parent callbacks are stable enough.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [quoteId]);

  const update =
    (k: keyof ShippingAddressInput) => (e: ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) =>
      setForm((f) => ({ ...f, [k]: e.target.value }));

  const required = ['recipient_name', 'phone', 'line1', 'postal_code'] as const;
  const missingRequired = required.some((k) => !form[k].trim());

  const onSave = async () => {
    if (saving || missingRequired) return;
    setSaving(true);
    try {
      // Required fields always sent; optional ones only when non-empty so we
      // don't overwrite a defaulted value with a blank string.
      const optional: (keyof ShippingAddressInput)[] = ['email', 'line2', 'city', 'state', 'country', 'notes'];
      const payload: ShippingAddressInput = {
        recipient_name: form.recipient_name.trim(),
        phone: form.phone.trim(),
        line1: form.line1.trim(),
        postal_code: form.postal_code.trim(),
      };
      for (const k of optional) {
        const v = form[k].trim();
        if (v) payload[k] = v;
      }
      await saveShippingAddress(quoteId, payload);
      toast({ title: 'Delivery address saved', tone: 'success' });
      onSaved();
    } catch (err) {
      toast({ title: 'Could not save delivery address', description: apiError(err), tone: 'danger' });
    } finally {
      setSaving(false);
    }
  };

  if (loading) {
    return (
      <div className="flex flex-col gap-2 border-t border-border pt-3">
        <Skeleton width="60%" height={14} />
        <Skeleton width="100%" height={36} />
        <Skeleton width="100%" height={36} />
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-2 border-t border-border pt-3">
      <Input label="Recipient name" required value={form.recipient_name} onChange={update('recipient_name')} />
      <Input label="Phone" required value={form.phone} onChange={update('phone')} />
      <Input label="Email" type="email" value={form.email} onChange={update('email')} />
      <Input label="Address line 1" required value={form.line1} onChange={update('line1')} />
      <Input label="Address line 2" value={form.line2} onChange={update('line2')} />
      <div className="grid grid-cols-2 gap-2">
        <Input label="City" value={form.city} onChange={update('city')} />
        <Input label="State" value={form.state} onChange={update('state')} />
      </div>
      <div className="grid grid-cols-2 gap-2">
        <Input label="Postal code" required value={form.postal_code} onChange={update('postal_code')} />
        <Input label="Country" placeholder="SG" maxLength={2} value={form.country} onChange={update('country')} />
      </div>
      <Textarea label="Notes" rows={2} value={form.notes} onChange={update('notes')} />
      <Button
        variant="primary"
        size="sm"
        fullWidth
        loading={saving}
        disabled={missingRequired}
        onClick={() => void onSave()}
      >
        Save delivery address
      </Button>
    </div>
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
  const [dlProduction, setDlProduction] = useState(false);

  // The file the floor actually prints: the H2S production file, falling back to
  // the canonical STL. Both refs are backend-serialized (added separately), so
  // until they arrive this is null and the button below simply doesn't render.
  const productionRef = product?.production_file_ref ?? product?.model_file_ref ?? null;

  // Download the print-floor production file (H2S `.3mf`, fallback STL). Staff-
  // gated, so fetch through the authed axios client as a blob then save via a
  // transient object URL - same pattern as the part/print-file downloads.
  const downloadProductionFile = async () => {
    if (!product || dlProduction) return;
    setDlProduction(true);
    try {
      // TODO(phase7): backend endpoint for production file. No route exists yet;
      // when the backend serves production_file_ref (fallback model_file_ref),
      // point this at it. Guarded by `productionRef` above so it no-ops (button
      // hidden) until the field is wired to the API.
      const res = await api.get(`/admin/products/${product.id}/production-file`, {
        responseType: 'blob',
      });
      const url = URL.createObjectURL(res.data as Blob);
      const a = document.createElement('a');
      a.href = url;
      a.download =
        filenameFromDisposition(res.headers['content-disposition']) ??
        `${product.slug ?? product.id}-production`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
    } catch (err) {
      toast({ title: 'Download failed', description: apiError(err), tone: 'danger' });
    } finally {
      setDlProduction(false);
    }
  };

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

      {productionRef && (
        <Button
          variant="secondary"
          size="sm"
          fullWidth
          loading={dlProduction}
          onClick={() => void downloadProductionFile()}
        >
          {product?.production_file_ref ? 'Download production file (.3mf)' : 'Download print file (STL)'}
        </Button>
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
