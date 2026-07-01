import { useEffect, useMemo, useState } from 'react';
import { useCatalogueAdminStore } from '../stores/catalogueAdminStore';
import { useAuthStore } from '../stores/authStore';
import { safeHref } from '../lib/safeHref';
import { Badge, Button, Card, EmptyState, Select, Skeleton, useToast } from '../ui';
import { ErrorState } from '../components/ui/States';
import { Motion, fadeInUp, staggerContainer, staggerItem } from '../motion';
import type { AdminCatalogueItem, ProductClass, PublishState } from '../types';

const CLASS_LABELS: Record<ProductClass, string> = {
  CORE: 'Core',
  SCRAPED_UV: 'UV Print',
  MODEL_3D: '3D Printed',
};

const STATE_TONE: Record<PublishState, 'neutral' | 'brand' | 'success' | 'danger' | 'warning'> = {
  PENDING: 'neutral',
  READY_TO_APPROVE: 'warning',
  PUBLISHED: 'success',
  CANNOT_PUBLISH: 'danger',
};

const STATE_LABELS: Record<PublishState, string> = {
  PENDING: 'Pending',
  READY_TO_APPROVE: 'Ready to approve',
  PUBLISHED: 'Published',
  CANNOT_PUBLISH: 'Cannot publish',
};

function ItemThumb({ item }: { item: AdminCatalogueItem }) {
  const [failed, setFailed] = useState(false);
  const href = safeHref(item.image_url);
  if (!href || failed) {
    return (
      <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-md bg-gradient-to-br from-brand-100 to-accent-50 font-display text-lg text-brand-700">
        {item.name.charAt(0)}
      </div>
    );
  }
  return (
    <img
      src={href}
      alt=""
      className="h-11 w-11 shrink-0 rounded-md object-cover"
      loading="lazy"
      referrerPolicy="no-referrer"
      onError={() => setFailed(true)}
    />
  );
}

export default function CatalogueAdminPage() {
  const { items, loading, error, fetch, publish, unpublish, setAutoPublish, autoPublish, autoPublishSaving } =
    useCatalogueAdminStore();
  const user = useAuthStore((s) => s.user);
  const isSuperadmin = user?.role === 'superadmin';
  const { toast } = useToast();

  const [pendingId, setPendingId] = useState<number | null>(null);
  const [classFilter, setClassFilter] = useState<'' | ProductClass>('');
  const [stateFilter, setStateFilter] = useState<'' | PublishState>('');

  const runFetch = () =>
    void fetch({
      class: classFilter || undefined,
      state: stateFilter || undefined,
    });

  useEffect(() => {
    void fetch({ class: classFilter || undefined, state: stateFilter || undefined });
    // Re-fetch when server-side filters change.
  }, [fetch, classFilter, stateFilter]);

  const toggleAutoPublish = async () => {
    const next = !autoPublish;
    const ok = await setAutoPublish(next);
    toast(
      ok
        ? { title: `Auto-publish ${next ? 'enabled' : 'disabled'}`, tone: 'success' }
        : { title: 'Could not update auto-publish', description: 'Please try again.', tone: 'danger' },
    );
  };

  // Single-flight guard so a rapid double-click can't fire publish/unpublish
  // twice on the same row.
  const runRow = async (id: number, label: string, fn: (id: number) => Promise<void>) => {
    if (pendingId !== null) return;
    setPendingId(id);
    try {
      await fn(id);
      const failed = useCatalogueAdminStore.getState().error;
      toast(
        failed
          ? { title: `Could not ${label.toLowerCase()} item`, description: failed, tone: 'danger' }
          : { title: `Item ${label.toLowerCase()}ed`, tone: 'success' },
      );
    } finally {
      setPendingId(null);
    }
  };

  const counts = useMemo(() => {
    const c = { total: items.length, ready: 0, published: 0, blocked: 0 };
    items.forEach((it) => {
      if (it.publish_state === 'READY_TO_APPROVE') c.ready += 1;
      if (it.publish_state === 'PUBLISHED') c.published += 1;
      if (it.publish_state === 'CANNOT_PUBLISH') c.blocked += 1;
    });
    return c;
  }, [items]);

  return (
    <div className="flex flex-col gap-6">
      <Motion variants={fadeInUp} initial="hidden" animate="visible" className="flex flex-col gap-4">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
          <div>
            <h1 className="font-display text-3xl text-fg">Catalogue gate</h1>
            <p className="mt-1 max-w-xl text-sm text-fg-muted">
              Review scraped-UV and 3D items. Publish complete, licence-cleared pieces; pull drifted ones.
            </p>
          </div>
          {isSuperadmin && (
            <label className="inline-flex cursor-pointer select-none items-center gap-2 rounded-md border border-border bg-surface px-3 py-2 text-sm text-fg shadow-xs">
              <input
                type="checkbox"
                className="h-4 w-4 accent-[var(--color-primary)]"
                checked={autoPublish}
                disabled={autoPublishSaving}
                onChange={() => void toggleAutoPublish()}
              />
              Auto-publish complete items
            </label>
          )}
        </div>

        {/* Summary stats */}
        {!loading && !error && items.length > 0 && (
          <div className="flex flex-wrap gap-2">
            <Badge tone="neutral">{counts.total} in review</Badge>
            {counts.ready > 0 && <Badge tone="warning">{counts.ready} ready to approve</Badge>}
            {counts.published > 0 && <Badge tone="success">{counts.published} published</Badge>}
            {counts.blocked > 0 && <Badge tone="danger">{counts.blocked} blocked</Badge>}
          </div>
        )}

        {/* Filters */}
        <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
          <div className="sm:w-52">
            <Select
              label="Class"
              value={classFilter}
              onChange={(e) => setClassFilter(e.target.value as '' | ProductClass)}
            >
              <option value="">All classes</option>
              <option value="SCRAPED_UV">UV Print</option>
              <option value="MODEL_3D">3D Printed</option>
            </Select>
          </div>
          <div className="sm:w-52">
            <Select
              label="State"
              value={stateFilter}
              onChange={(e) => setStateFilter(e.target.value as '' | PublishState)}
            >
              <option value="">All states</option>
              <option value="PENDING">Pending</option>
              <option value="READY_TO_APPROVE">Ready to approve</option>
              <option value="PUBLISHED">Published</option>
              <option value="CANNOT_PUBLISH">Cannot publish</option>
            </Select>
          </div>
        </div>
      </Motion>

      {loading ? (
        <>
          <span className="sr-only" role="status" aria-live="polite">
            Loading catalogue items…
          </span>
          <Card padding="none">
            <div className="flex flex-col divide-y divide-border" aria-hidden="true">
              {Array.from({ length: 5 }).map((_, i) => (
                <div key={i} className="flex items-center gap-4 p-4">
                  <Skeleton className="h-11 w-11 rounded-md" />
                  <div className="flex flex-1 flex-col gap-2">
                    <Skeleton height={16} width="40%" />
                    <Skeleton height={12} width="24%" />
                  </div>
                  <Skeleton height={32} width={96} />
                </div>
              ))}
            </div>
          </Card>
        </>
      ) : error ? (
        <ErrorState message={error} onRetry={runFetch} />
      ) : items.length === 0 ? (
        <EmptyState
          title="Nothing awaiting review"
          description="Scraped and 3D items appear here once synced. Adjust the filters or check back after the next sync."
          action={
            <Button variant="outline" onClick={runFetch}>
              Refresh
            </Button>
          }
        />
      ) : (
        <Card padding="none" className="overflow-hidden">
          {/* Column header (hidden on mobile where rows stack) */}
          <div className="hidden grid-cols-[1fr_8rem_10rem_1fr_9rem] gap-4 border-b border-border bg-surface-2/60 px-4 py-3 text-2xs font-semibold uppercase tracking-wide text-fg-subtle md:grid">
            <span>Item</span>
            <span>Class</span>
            <span>State</span>
            <span>Blockers</span>
            <span className="text-right">Action</span>
          </div>

          <Motion
            variants={staggerContainer}
            initial="hidden"
            animate="visible"
            className="flex flex-col divide-y divide-border"
          >
            {items.map((it) => (
              <Motion
                key={it.id}
                variants={staggerItem}
                className="grid grid-cols-1 gap-3 px-4 py-4 md:grid-cols-[1fr_8rem_10rem_1fr_9rem] md:items-center md:gap-4"
              >
                <div className="flex items-center gap-3">
                  <ItemThumb item={it} />
                  <div className="min-w-0">
                    <p className="truncate font-medium text-fg">{it.name}</p>
                    {it.creator_credit && (
                      <p className="truncate text-xs text-fg-subtle">by {it.creator_credit}</p>
                    )}
                  </div>
                </div>

                <div>
                  <Badge tone="neutral" size="sm">
                    {CLASS_LABELS[it.class]}
                  </Badge>
                </div>

                <div>
                  <Badge tone={STATE_TONE[it.publish_state]} size="sm" dot>
                    {STATE_LABELS[it.publish_state]}
                  </Badge>
                </div>

                <div className="flex flex-wrap gap-1.5">
                  {it.cannot_publish_reasons?.length ? (
                    it.cannot_publish_reasons.map((r) => (
                      <Badge key={r} tone="warning" size="sm">
                        {r}
                      </Badge>
                    ))
                  ) : (
                    <span className="text-sm text-fg-subtle">—</span>
                  )}
                </div>

                <div className="flex md:justify-end">
                  {it.publish_state === 'READY_TO_APPROVE' && (
                    <Button
                      size="sm"
                      loading={pendingId === it.id}
                      disabled={pendingId !== null && pendingId !== it.id}
                      onClick={() => void runRow(it.id, 'Publish', publish)}
                    >
                      Publish
                    </Button>
                  )}
                  {it.publish_state === 'PUBLISHED' && (
                    <Button
                      size="sm"
                      variant="ghost"
                      loading={pendingId === it.id}
                      disabled={pendingId !== null && pendingId !== it.id}
                      onClick={() => void runRow(it.id, 'Unpublish', unpublish)}
                    >
                      Unpublish
                    </Button>
                  )}
                </div>
              </Motion>
            ))}
          </Motion>
        </Card>
      )}
    </div>
  );
}
