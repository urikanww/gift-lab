import { useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useCatalogueAdminStore } from '../stores/catalogueAdminStore';
import { useAuthStore } from '../stores/authStore';
import api, { apiError, ensureCsrf } from '../lib/api';
import { safeHref } from '../lib/safeHref';
import { Badge, Button, Card, EmptyState, Input, Modal, Select, Skeleton, useToast } from '../ui';
import { ErrorState } from '../components/ui/States';
import ProductQuickView from '../components/ProductQuickView';
import ImageLightbox from '../components/ImageLightbox';
import { EyeIcon, FilterIcon } from '../components/icons';
import { CountPill, FilterChips } from '../components/admin/Filters';
import { CATEGORIES, categoryLabel } from '../lib/categories';
import { SOURCE_KIND_LABELS, type SourceKind } from '../lib/sourceKind';
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

/**
 * Human labels for the machine reason tokens emitted by the backend gates
 * (CompletenessGate + Model3dCatalogueService + resync commands). Unknown or
 * future tokens fall back to a prettified form so a raw enum never renders.
 */
const BLOCKER_LABELS: Record<string, string> = {
  missing_model_file: 'No printable model file',
  awaiting_model_file: 'Awaiting 3D model (skipped until pulled)',
  license_review: 'Licence needs review',
  multi_file_review: 'Multi-file set needs review',
  estimates_unverified: 'Filament estimates unverified',
  missing_price: 'No price from source',
  missing_dimensions: 'Missing dimensions or weight',
  not_printable: 'No print method set',
  stock_unreadable: 'Stock level unreadable',
  source_dead: 'Source listing gone',
  'needs_re-review': 'Needs re-review',
  license_blocked: 'Licence blocks commercial use',
  missing_credit: 'Creator credit missing',
};

function blockerLabel(token: string): string {
  const known = BLOCKER_LABELS[token];
  if (known) return known;
  if (token.startsWith('ip_flag:')) return `IP flag: ${token.slice('ip_flag:'.length)}`;
  // Fallback prettifier: snake/kebab token → sentence case.
  const pretty = token.replace(/[_-]+/g, ' ').trim();
  return pretty.charAt(0).toUpperCase() + pretty.slice(1);
}

/** Mirrors the render condition of Model3dRowTools (inline fix available). */
function hasInlineTools(item: AdminCatalogueItem): boolean {
  return (
    item.class === 'MODEL_3D' &&
    ((item.cannot_publish_reasons?.includes('missing_model_file') ?? false) || !item.estimates_verified)
  );
}

function ItemThumb({ item }: { item: AdminCatalogueItem }) {
  const [failed, setFailed] = useState(false);
  const [open, setOpen] = useState(false);
  const href = safeHref(item.image_url);
  if (!href || failed) {
    return (
      <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-md bg-gradient-to-br from-brand-100 to-accent-50 font-display text-lg text-brand-700">
        {item.name.charAt(0)}
      </div>
    );
  }
  // Click to inspect the product photo up close (zoom/pan) without leaving the gate.
  return (
    <>
      <button
        type="button"
        onClick={() => setOpen(true)}
        aria-label={`Zoom image of ${item.name}`}
        className="shrink-0 rounded-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
      >
        <img
          src={href}
          alt=""
          className="h-11 w-11 shrink-0 cursor-zoom-in rounded-md object-cover"
          loading="lazy"
          referrerPolicy="no-referrer"
          onError={() => setFailed(true)}
        />
      </button>
      <ImageLightbox src={href} alt={item.name} open={open} onClose={() => setOpen(false)} />
    </>
  );
}

/**
 * Inline production tools for a MODEL_3D row: confirm filament estimates
 * (clears the estimates_unverified hold) and attach the printable model file
 * (clears missing_model_file - e.g. Cults3D has no download API).
 */
function Model3dRowTools({ item }: { item: AdminCatalogueItem }) {
  const { verifyEstimates, uploadModelFile } = useCatalogueAdminStore();
  const { toast } = useToast();
  const fileInput = useRef<HTMLInputElement | null>(null);

  const [material, setMaterial] = useState(item.filament_material ?? 'PLA');
  const [color, setColor] = useState(item.filament_color ?? 'Black');
  const [grams, setGrams] = useState(item.est_grams ?? '');
  const [busy, setBusy] = useState<'verify' | 'upload' | null>(null);

  const needsFile = item.cannot_publish_reasons?.includes('missing_model_file') ?? false;
  const needsVerify = !item.estimates_verified && !needsFile;

  if (!needsFile && !needsVerify) return null;

  const submitVerify = async () => {
    const value = Number(grams);
    if (!Number.isFinite(value) || value <= 0) {
      toast({ title: 'Enter the filament grams per unit', tone: 'danger' });
      return;
    }
    setBusy('verify');
    const ok = await verifyEstimates(item.id, {
      filament_material: material.trim(),
      filament_color: color.trim(),
      est_grams: value,
    });
    setBusy(null);
    toast(
      ok
        ? { title: 'Estimates verified', description: item.name, tone: 'success' }
        : { title: 'Could not verify estimates', tone: 'danger' },
    );
  };

  const submitFile = async (file: File | undefined) => {
    if (!file) return;
    setBusy('upload');
    const ok = await uploadModelFile(item.id, file);
    setBusy(null);
    toast(
      ok
        ? { title: 'Model file attached', description: file.name, tone: 'success' }
        : { title: 'Could not attach model file', tone: 'danger' },
    );
  };

  return (
    <div className="col-span-full flex flex-col gap-3 rounded-md border border-border bg-surface-2/50 p-3 sm:flex-row sm:items-end">
      {needsVerify && (
        <>
          <div className="w-full sm:w-36">
            <Input label="Material" value={material} onChange={(e) => setMaterial(e.target.value)} />
          </div>
          <div className="w-full sm:w-36">
            <Input label="Colour" value={color} onChange={(e) => setColor(e.target.value)} />
          </div>
          <div className="w-full sm:w-32">
            <Input
              label="Grams / unit"
              inputMode="decimal"
              value={grams}
              onChange={(e) => setGrams(e.target.value)}
            />
          </div>
          <Button size="sm" loading={busy === 'verify'} disabled={busy !== null} onClick={() => void submitVerify()}>
            Verify estimates
          </Button>
        </>
      )}
      {needsFile && (
        <>
          <input
            ref={fileInput}
            type="file"
            accept=".stl,.3mf,.obj"
            className="hidden"
            onChange={(e) => void submitFile(e.target.files?.[0])}
          />
          <Button
            size="sm"
            variant="outline"
            loading={busy === 'upload'}
            disabled={busy !== null}
            onClick={() => fileInput.current?.click()}
          >
            Attach model file (.stl / .3mf / .obj)
          </Button>
        </>
      )}
    </div>
  );
}

export default function CatalogueAdminPage() {
  const { items, meta, counts, loading, error, fetch, publish, unpublish, bulkPublish, setAutoPublish, autoPublish, autoPublishSaving } =
    useCatalogueAdminStore();
  const user = useAuthStore((s) => s.user);
  const isSuperadmin = user?.role === 'superadmin';
  const { toast } = useToast();
  const navigate = useNavigate();

  const [pendingId, setPendingId] = useState<number | null>(null);
  const [quickViewId, setQuickViewId] = useState<number | null>(null);
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [bulkBusy, setBulkBusy] = useState(false);
  const [captureUrl, setCaptureUrl] = useState('');
  const [capturing, setCapturing] = useState(false);

  const [searchParams, setSearchParams] = useSearchParams();
  const classFilter = searchParams.get('class') ?? '';
  const stateFilter = searchParams.get('state') ?? '';
  const blocker = searchParams.get('blocker') ?? '';
  const source = searchParams.get('source') ?? '';
  const printMethod = searchParams.get('print_method') ?? '';
  const category = searchParams.get('category') ?? '';
  const ipFlagged = searchParams.get('ip_flagged') === '1';
  const missingLink = searchParams.get('missing_link') === '1';
  const sort = (searchParams.get('sort') as 'newest' | 'name' | 'base_cost') || 'newest';
  const dir: 'asc' | 'desc' = searchParams.get('dir') === 'asc' ? 'asc' : 'desc';
  const rawPage = Number(searchParams.get('page'));
  const page = Number.isInteger(rawPage) && rawPage > 0 ? rawPage : 1;
  const q = searchParams.get('search') ?? '';
  const [filtersOpen, setFiltersOpen] = useState(false);

  const setParam = (key: string, value: string) => {
    setSearchParams(
      (prev) => {
        const p = new URLSearchParams(prev);
        if (value) p.set(key, value);
        else p.delete(key);
        if (key !== 'page') p.delete('page');
        return p;
      },
      { replace: true },
    );
  };
  const clearAll = () => setSearchParams({}, { replace: true });

  // Filters modal stages selections locally and commits them on Apply, so picking
  // a dropdown no longer fires the API on every change (one fetch per Apply).
  const currentFilters = (): Record<string, string> => ({
    class: classFilter,
    state: stateFilter,
    blocker,
    source,
    print_method: printMethod,
    category,
    sort,
    dir,
    ip_flagged: ipFlagged ? '1' : '',
    missing_link: missingLink ? '1' : '',
  });
  const [draft, setDraft] = useState<Record<string, string>>(currentFilters);
  const setDraftKey = (key: string, value: string) => setDraft((d) => ({ ...d, [key]: value }));
  const openFilters = () => {
    setDraft(currentFilters());
    setFiltersOpen(true);
  };
  const applyDraft = (next: Record<string, string>) => {
    setSearchParams(
      (prev) => {
        const p = new URLSearchParams(prev);
        for (const k of Object.keys(next)) {
          if (next[k]) p.set(k, next[k]);
          else p.delete(k);
        }
        p.delete('page');
        return p;
      },
      { replace: true },
    );
    setFiltersOpen(false);
  };
  const clearDraft = () => {
    const cleared = { ...currentFilters(), class: '', state: '', blocker: '', source: '', print_method: '', category: '', sort: 'newest', dir: 'desc', ip_flagged: '', missing_link: '' };
    setDraft(cleared);
    applyDraft(cleared);
  };

  const [qInput, setQInput] = useState(q);
  useEffect(() => setQInput(q), [q]);
  useEffect(() => {
    if (qInput === q) return;
    const t = setTimeout(() => setParam('search', qInput.trim()), 300);
    return () => clearTimeout(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [qInput]);

  const fetchParams = {
    class: classFilter || undefined,
    state: stateFilter || undefined,
    search: q || undefined,
    blocker: blocker || undefined,
    source: source || undefined,
    print_method: printMethod || undefined,
    category: category || undefined,
    ip_flagged: ipFlagged ? '1' : undefined,
    missing_link: missingLink ? '1' : undefined,
    sort,
    dir,
    page,
  };
  const runFetch = () => void fetch(fetchParams);
  useEffect(() => {
    void fetch(fetchParams);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [fetch, classFilter, stateFilter, q, blocker, source, printMethod, category, ipFlagged, missingLink, sort, dir, page]);

  const captureBlank = async () => {
    if (!captureUrl.trim() || capturing) return;
    setCapturing(true);
    try {
      await ensureCsrf();
      await api.post('/admin/blank-candidates/capture', { url: captureUrl.trim() });
      setCaptureUrl('');
      runFetch();
      toast({ title: 'Blank captured', description: 'Complete its specs in the gate.', tone: 'success' });
    } catch (err) {
      toast({ title: 'Capture failed', description: apiError(err), tone: 'danger' });
    } finally {
      setCapturing(false);
    }
  };

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

  // Only READY_TO_APPROVE rows can be bulk-published. Keep the eligible-id set
  // and prune any stale selections (a row that dropped out of the list or
  // changed state after a refetch must not linger in `selected`).
  const eligibleIds = useMemo(
    () => items.filter((it) => it.publish_state === 'READY_TO_APPROVE').map((it) => it.id),
    [items],
  );

  const filterChips = useMemo(() => {
    const chips: { key: string; label: string }[] = [];
    if (q) chips.push({ key: 'search', label: `Search: “${q}”` });
    if (classFilter) chips.push({ key: 'class', label: `Class: ${CLASS_LABELS[classFilter as ProductClass]}` });
    if (stateFilter) chips.push({ key: 'state', label: `State: ${STATE_LABELS[stateFilter as PublishState]}` });
    if (blocker) chips.push({ key: 'blocker', label: `Blocker: ${blockerLabel(blocker)}` });
    if (source) chips.push({ key: 'source', label: `Source: ${SOURCE_KIND_LABELS[source as SourceKind] ?? source}` });
    if (printMethod) chips.push({ key: 'print_method', label: `Print: ${printMethod}` });
    if (category) chips.push({ key: 'category', label: `Category: ${categoryLabel(category)}` });
    if (ipFlagged) chips.push({ key: 'ip_flagged', label: 'IP-flagged' });
    if (missingLink) chips.push({ key: 'missing_link', label: 'Missing buy link' });
    return chips;
  }, [q, classFilter, stateFilter, blocker, source, printMethod, category, ipFlagged, missingLink]);

  useEffect(() => {
    setSelected((prev) => {
      const eligible = new Set(eligibleIds);
      const next = new Set([...prev].filter((id) => eligible.has(id)));
      return next.size === prev.size ? prev : next;
    });
  }, [eligibleIds]);

  const allEligibleSelected = eligibleIds.length > 0 && eligibleIds.every((id) => selected.has(id));

  const toggleRow = (id: number) => {
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  const toggleAllEligible = () => {
    setSelected((prev) => (prev.size === eligibleIds.length ? new Set() : new Set(eligibleIds)));
  };

  const runBulkPublish = async () => {
    if (bulkBusy || selected.size === 0) return;
    setBulkBusy(true);
    const result = await bulkPublish([...selected]);
    setBulkBusy(false);
    if (result) {
      toast({
        title: `Published ${result.published}, failed ${result.failed}`,
        tone: result.failed > 0 ? 'warning' : 'success',
      });
      setSelected(new Set());
    } else {
      toast({ title: 'Bulk publish failed', description: 'Please try again.', tone: 'danger' });
    }
  };

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

        <div className="flex gap-2">
          <Input
            type="url"
            placeholder="Paste a product URL to add a blank"
            value={captureUrl}
            onChange={(e) => setCaptureUrl(e.target.value)}
          />
          <Button variant="outline" loading={capturing} onClick={() => void captureBlank()}>
            Add blank by URL
          </Button>
        </div>

        {/* Summary stats + bulk action. Counts are the full-set breakdown from
            the server (page-independent), so total = pending + ready + published
            + blocked across the whole gate. */}
        {!loading && !error && counts && counts.total > 0 && (
          <div className="flex flex-wrap items-center gap-2">
            <Badge tone="neutral">{counts.total} total</Badge>
            {counts.pending > 0 && <Badge tone="neutral">{counts.pending} pending</Badge>}
            {counts.ready > 0 && <Badge tone="warning">{counts.ready} ready to approve</Badge>}
            {counts.published > 0 && <Badge tone="success">{counts.published} published</Badge>}
            {counts.blocked > 0 && <Badge tone="danger">{counts.blocked} blocked</Badge>}
            {eligibleIds.length > 0 && (
              <div className="ml-auto">
                <Button
                  size="sm"
                  loading={bulkBusy}
                  disabled={selected.size === 0}
                  onClick={() => void runBulkPublish()}
                >
                  Publish selected ({selected.size})
                </Button>
              </div>
            )}
          </div>
        )}

        {/* Filters entry point + chips */}
        <div className="flex flex-col gap-3">
          <div className="flex flex-col gap-2 sm:flex-row sm:items-end">
            <div className="flex-1">
              <Input
                label="Search"
                type="search"
                placeholder="Search by product name or creator…"
                value={qInput}
                onChange={(e) => setQInput(e.target.value)}
              />
            </div>
            <Button variant="outline" onClick={openFilters} className="sm:mb-0.5">
              <FilterIcon />
              Filters
              {filterChips.length > 0 && <CountPill>{filterChips.length}</CountPill>}
            </Button>
          </div>
          <FilterChips chips={filterChips} onRemove={(key) => setParam(key, '')} onClear={clearAll} />
        </div>

        <Modal
          open={filtersOpen}
          onClose={() => setFiltersOpen(false)}
          title="Filters"
          size="lg"
          footer={
            <>
              <Button variant="ghost" onClick={clearDraft}>Clear all</Button>
              <Button variant="primary" onClick={() => applyDraft(draft)}>Apply</Button>
            </>
          }
        >
          <div className="grid gap-3 sm:grid-cols-2">
            <Select label="Class" value={draft.class} onChange={(e) => setDraftKey('class', e.target.value)}>
              <option value="">All classes</option>
              <option value="SCRAPED_UV">UV Print</option>
              <option value="MODEL_3D">3D Printed</option>
            </Select>
            <Select label="State" value={draft.state} onChange={(e) => setDraftKey('state', e.target.value)}>
              <option value="">All states</option>
              <option value="PENDING">Pending</option>
              <option value="READY_TO_APPROVE">Ready to approve</option>
              <option value="PUBLISHED">Published</option>
              <option value="CANNOT_PUBLISH">Cannot publish</option>
            </Select>
            <Select label="Blocker" value={draft.blocker} onChange={(e) => setDraftKey('blocker', e.target.value)}>
              <option value="">Any blocker</option>
              {Object.entries(BLOCKER_LABELS).map(([k, v]) => (
                <option key={k} value={k}>{v}</option>
              ))}
            </Select>
            <Select label="Source" value={draft.source} onChange={(e) => setDraftKey('source', e.target.value)}>
              <option value="">All sources</option>
              {(Object.keys(SOURCE_KIND_LABELS) as SourceKind[]).map((k) => (
                <option key={k} value={k}>{SOURCE_KIND_LABELS[k]}</option>
              ))}
            </Select>
            <Select label="Print method" value={draft.print_method} onChange={(e) => setDraftKey('print_method', e.target.value)}>
              <option value="">All methods</option>
              <option value="UV">UV</option>
              <option value="FDM">FDM</option>
              <option value="RESIN">Resin</option>
            </Select>
            <Select label="Category" value={draft.category} onChange={(e) => setDraftKey('category', e.target.value)}>
              <option value="">All categories</option>
              {CATEGORIES.map((c) => (
                <option key={c.key} value={c.key}>{c.label}</option>
              ))}
            </Select>
            <Select label="Sort by" value={draft.sort} onChange={(e) => setDraftKey('sort', e.target.value)}>
              <option value="newest">Creation date</option>
              <option value="name">Name</option>
              <option value="base_cost">Base cost</option>
            </Select>
            <Select label="Direction" value={draft.dir} onChange={(e) => setDraftKey('dir', e.target.value)}>
              <option value="desc">Descending</option>
              <option value="asc">Ascending</option>
            </Select>
            <label className="inline-flex items-center gap-2 text-sm text-fg">
              <input type="checkbox" className="h-4 w-4 accent-[var(--color-primary)]" checked={draft.ip_flagged === '1'} onChange={(e) => setDraftKey('ip_flagged', e.target.checked ? '1' : '')} />
              IP-flagged only
            </label>
            <label className="inline-flex items-center gap-2 text-sm text-fg">
              <input type="checkbox" className="h-4 w-4 accent-[var(--color-primary)]" checked={draft.missing_link === '1'} onChange={(e) => setDraftKey('missing_link', e.target.checked ? '1' : '')} />
              Missing buy link
            </label>
          </div>
        </Modal>
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
          {/* Column header. Rows stack as cards below lg - the staff sidebar
              (240px, shown from md) leaves too little room for the fixed table
              columns until lg, where they'd overflow and clip the Card. */}
          <div className="hidden grid-cols-[2rem_1fr_8rem_10rem_1fr_9rem] items-center gap-4 border-b border-border bg-surface-2/60 px-4 py-3 text-2xs font-semibold uppercase tracking-wide text-fg-subtle lg:grid">
            <span>
              <input
                type="checkbox"
                className="h-4 w-4 accent-[var(--color-primary)]"
                aria-label="Select all eligible items"
                checked={allEligibleSelected}
                disabled={eligibleIds.length === 0}
                onChange={toggleAllEligible}
              />
            </span>
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
                className="grid grid-cols-1 gap-3 px-4 py-4 lg:grid-cols-[2rem_1fr_8rem_10rem_1fr_9rem] lg:items-center lg:gap-4"
              >
                <div className="flex items-center">
                  <input
                    type="checkbox"
                    className="h-4 w-4 accent-[var(--color-primary)] disabled:opacity-40"
                    aria-label={`Select ${it.name}`}
                    checked={selected.has(it.id)}
                    disabled={it.publish_state !== 'READY_TO_APPROVE'}
                    onChange={() => toggleRow(it.id)}
                  />
                </div>
                <div className="flex min-w-0 items-center gap-3">
                  <ItemThumb item={it} />
                  <div className="min-w-0 flex-1">
                    <button
                      type="button"
                      onClick={() => navigate(`/product-admin/${it.id}`, { state: { from: '/catalogue-admin' } })}
                      className="block w-full truncate text-left font-medium text-fg hover:text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                      {it.name}
                    </button>
                    {it.creator_credit && (
                      <p className="truncate text-xs text-fg-subtle">by {it.creator_credit}</p>
                    )}
                  </div>
                  <button
                    type="button"
                    onClick={() => setQuickViewId(it.id)}
                    aria-label={`Quick view ${it.name}`}
                    className="shrink-0 rounded-md p-1.5 text-fg-subtle transition-colors hover:bg-surface-2 hover:text-fg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                  >
                    <EyeIcon className="h-4 w-4" />
                  </button>
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

                <div className="flex min-w-0 flex-wrap gap-1.5">
                  {it.cannot_publish_reasons?.length ? (
                    it.cannot_publish_reasons.map((r) => (
                      <Badge key={r} tone="warning" size="sm">
                        {blockerLabel(r)}
                      </Badge>
                    ))
                  ) : (
                    <span className="text-sm text-fg-subtle">-</span>
                  )}
                </div>

                <div className="flex lg:justify-end">
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
                  {/* No-action states: tell the staffer what unblocks the row
                      instead of leaving a dead-end empty cell. */}
                  {it.publish_state === 'PENDING' && (
                    <span className="text-xs text-fg-subtle lg:text-right">
                      No action needed - resolves on next catalogue sync.
                    </span>
                  )}
                  {it.publish_state === 'CANNOT_PUBLISH' && (
                    <span className="text-xs text-fg-subtle lg:text-right">
                      {hasInlineTools(it)
                        ? 'Use the tools below to clear the blockers.'
                        : 'Fix the blockers at the source - re-checked on next sync.'}
                    </span>
                  )}
                </div>

                {it.class === 'MODEL_3D' && <Model3dRowTools item={it} />}
              </Motion>
            ))}
          </Motion>
        </Card>
      )}

      {/* Pagination */}
      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between gap-4">
          <span className="text-sm text-fg-muted">
            Page {meta.current_page} of {meta.last_page} · {meta.total} total
          </span>
          <div className="flex gap-2">
            <Button
              variant="outline"
              size="sm"
              disabled={loading || meta.current_page <= 1}
              onClick={() => setParam('page', String(Math.max(1, page - 1)))}
            >
              Prev
            </Button>
            <Button
              variant="outline"
              size="sm"
              disabled={loading || meta.current_page >= meta.last_page}
              onClick={() => setParam('page', String(page + 1))}
            >
              Next
            </Button>
          </div>
        </div>
      )}

      <ProductQuickView
        productId={quickViewId}
        isSuperadmin={isSuperadmin}
        backTo="/catalogue-admin"
        onClose={() => setQuickViewId(null)}
      />
    </div>
  );
}
