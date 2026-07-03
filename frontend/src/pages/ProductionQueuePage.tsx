import { useEffect, useState } from 'react';
import { AnimatePresence, motion } from 'framer-motion';
import { useQueueStore } from '../stores/queueStore';
import { Badge, Button, Card, EmptyState, Input, Skeleton } from '../ui';
import type { BadgeTone } from '../ui';
import { ErrorState } from '../components/ui/States';
import { Motion, fadeInUp, springSoft, useReducedMotionSafe } from '../motion';
import type { JobState } from '../types';

const NEXT_STATE: Partial<Record<JobState, { label: string; to: JobState }>> = {
  READY: { label: 'Start production', to: 'IN_PRODUCTION' },
  IN_PRODUCTION: { label: 'Mark shipped', to: 'SHIPPED' },
  SHIPPED: { label: 'Close', to: 'CLOSED' },
};

const STATE_META: Record<JobState, { label: string; tone: BadgeTone }> = {
  READY: { label: 'Ready', tone: 'info' },
  IN_PRODUCTION: { label: 'In production', tone: 'warning' },
  SHIPPED: { label: 'Shipped', tone: 'brand' },
  CLOSED: { label: 'Closed', tone: 'success' },
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

export default function ProductionQueuePage() {
  const { jobs, loading, error, fetchQueue, advance, subscribe, unsubscribe } = useQueueStore();
  const [pendingId, setPendingId] = useState<number | null>(null);
  // Shipping is confirm-gated: marking a job shipped requires a consignment ref
  // (that transition fires the buyer's "on the way" signal). This tracks which
  // card is mid-confirmation and its typed reference.
  const [shippingId, setShippingId] = useState<number | null>(null);
  const [consignment, setConsignment] = useState('');
  const animate = useReducedMotionSafe();

  useEffect(() => {
    void fetchQueue();
    subscribe(); // live via Reverb; no polling
    return () => unsubscribe();
  }, [fetchQueue, subscribe, unsubscribe]);

  // Single-flight guard against a double-click firing a duplicate advance.
  const onAdvance = async (jobId: number, to: JobState, consignmentRef?: string) => {
    if (pendingId !== null) return;
    setPendingId(jobId);
    try {
      await advance(jobId, to, consignmentRef);
      setShippingId(null);
      setConsignment('');
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
          Shared first-come queue by readiness — UV + 3D, no customer priority. Updates in real time.
        </p>
      </Motion>

      {/* Loading — animated skeletons on first load only */}
      {loading && jobs.length === 0 && <QueueSkeleton />}

      {/* Error — retry */}
      {!loading && error && <ErrorState message={error} onRetry={() => void fetchQueue()} />}

      {/* Empty */}
      {!loading && !error && jobs.length === 0 && (
        <EmptyState
          title="The queue is clear."
          description="Jobs appear here the moment a quote is confirmed and ready to make."
        />
      )}

      {/* Board — layout-animated cards; realtime add/remove/reorder via AnimatePresence + layout */}
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
                      <div>
                        <p className="font-display text-lg leading-tight text-fg">Job #{j.id}</p>
                        <p className="text-sm text-fg-muted">Quote #{j.quote_id}</p>
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
                        {j.ready_at ? new Date(j.ready_at).toLocaleString() : '—'}
                      </dd>
                    </dl>

                    {next && next.to === 'SHIPPED' && shippingId === j.id ? (
                      <div className="mt-auto flex flex-col gap-2">
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
                            onClick={() => void onAdvance(j.id, 'SHIPPED', consignment.trim())}
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
