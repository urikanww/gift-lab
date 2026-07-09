import { useCallback, useEffect, useMemo, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import api, { apiError } from '../lib/api';
import { AsyncBoundary } from '../components/ui/States';
import { Badge, Button, Card, Input, LinkButton, Modal, Select } from '../ui';
import { Motion, fadeInUp } from '../motion';
import { CATEGORIES, categoryLabel } from '../lib/categories';
import { useAuthStore } from '../stores/authStore';
import type { AdminProduct } from '../types';
import { classLabel, ItemThumb, LicenseTierBadge, PublishBadge } from './adminProductBadges';
import Pagination from '../components/Pagination';
import ProductQuickView from '../components/ProductQuickView';
import { EyeIcon, FilterIcon } from '../components/icons';

// Human labels for filter chips.
const PUBLISH_LABELS: Record<string, string> = {
  PENDING: 'Pending',
  READY_TO_APPROVE: 'Ready to approve',
  PUBLISHED: 'Published',
  CANNOT_PUBLISH: 'Cannot publish',
  all: 'All states',
};
const TIER_LABELS: Record<string, string> = {
  standard: 'Standard',
  extended: 'Extended',
  high_risk: 'High risk',
};

interface Meta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

const PER_PAGE_OPTIONS = [15, 30, 50, 100] as const;

type SortKey = 'newest' | 'most_sold' | 'name' | 'base_cost' | 'stock';

const SORT_KEYS = new Set<SortKey>(['newest', 'most_sold', 'name', 'base_cost', 'stock']);

/**
 * Server-driven product browser (route /product-admin). All filtering, sorting
 * and pagination happen on the API; this page just reflects the query state.
 * Create and edit live on their own pages (/product-admin/new, /:id).
 */
export default function ProductAdminPage() {
  const navigate = useNavigate();
  const isSuperadmin = useAuthStore((s) => s.user?.role === 'superadmin');

  // URL is the single source of truth for pagination + every filter, so returning
  // from a product detail (back-nav) restores the exact list state.
  const [searchParams, setSearchParams] = useSearchParams();
  const rawPage = Number(searchParams.get('page'));
  const page = Number.isInteger(rawPage) && rawPage > 0 ? rawPage : 1;
  const rawPerPage = Number(searchParams.get('per_page'));
  const perPage = (PER_PAGE_OPTIONS as readonly number[]).includes(rawPerPage) ? rawPerPage : 15;
  const status: 'active' | 'archived' = searchParams.get('status') === 'archived' ? 'archived' : 'active';
  const classFilter = searchParams.get('class') ?? '';
  // Default view: published only. "All states" is the explicit 'all' sentinel
  // (which the API ignores, so every state shows).
  const publishState = searchParams.get('publish_state') ?? 'PUBLISHED';
  const licenseTier = searchParams.get('license_tier') ?? '';
  const category = searchParams.get('category') ?? '';
  const q = searchParams.get('q') ?? '';
  const rawSort = searchParams.get('sort');
  const sort: SortKey = rawSort && SORT_KEYS.has(rawSort as SortKey) ? (rawSort as SortKey) : 'newest';
  const dir: 'asc' | 'desc' = searchParams.get('dir') === 'asc' ? 'asc' : 'desc';

  const [products, setProducts] = useState<AdminProduct[]>([]);
  const [meta, setMeta] = useState<Meta | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // Write a single param. Any filter change (not page itself) resets to page 1.
  // Defaults are written empty (deleted) to keep the URL clean.
  const setParam = useCallback(
    (key: string, value: string) => {
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
    },
    [setSearchParams],
  );

  const goToPage = useCallback(
    (target: number) => {
      setParam('page', target <= 1 ? '' : String(target));
      window.scrollTo({ top: 0, behavior: 'smooth' });
    },
    [setParam],
  );

  // Free-text search: local input for responsiveness, debounced into the URL.
  const [qInput, setQInput] = useState(q);
  useEffect(() => {
    setQInput(q);
  }, [q]);
  useEffect(() => {
    if (qInput === q) return;
    const t = setTimeout(() => setParam('q', qInput), 300);
    return () => clearTimeout(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [qInput]);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const { data } = await api.get<{ data: AdminProduct[]; meta: Meta }>('/admin/products', {
        params: {
          page,
          per_page: perPage,
          status,
          class: classFilter || undefined,
          publish_state: publishState || undefined,
          license_tier: licenseTier || undefined,
          category: category || undefined,
          q: q || undefined,
          sort,
          dir: dir || undefined,
        },
      });
      setProducts(data.data);
      setMeta(data.meta);
    } catch (err) {
      setError(apiError(err));
    } finally {
      setLoading(false);
    }
  }, [page, perPage, status, classFilter, publishState, licenseTier, category, q, sort, dir]);

  useEffect(() => {
    void load();
  }, [load]);

  const [gateCount, setGateCount] = useState<number | null>(null);

  useEffect(() => {
    let cancelled = false;
    api
      .get<{ meta?: { total?: number } }>('/admin/catalogue', { params: { per_page: 1 } })
      .then(({ data }) => {
        if (!cancelled) setGateCount(data.meta?.total ?? null);
      })
      .catch(() => {
        // Non-critical - the badge just stays hidden.
      });
    return () => {
      cancelled = true;
    };
  }, []);

  const archivedView = status === 'archived';

  const rangeLabel = useMemo(() => {
    if (!meta) return '';
    return `Page ${meta.current_page} of ${meta.last_page} · ${meta.total} total`;
  }, [meta]);

  const [filtersOpen, setFiltersOpen] = useState(false);
  const [quickViewId, setQuickViewId] = useState<number | null>(null);

  // Active filters (deviations from the default view) shown as removable chips.
  const filterChips = useMemo(() => {
    const chips: { key: string; label: string }[] = [];
    if (q) chips.push({ key: 'q', label: `Search: “${q}”` });
    if (classFilter) chips.push({ key: 'class', label: `Class: ${classLabel(classFilter as AdminProduct['class'])}` });
    if (publishState !== 'PUBLISHED') chips.push({ key: 'publish_state', label: `State: ${PUBLISH_LABELS[publishState] ?? publishState}` });
    if (isSuperadmin && licenseTier) chips.push({ key: 'license_tier', label: `Tier: ${TIER_LABELS[licenseTier] ?? licenseTier}` });
    if (category) chips.push({ key: 'category', label: `Category: ${categoryLabel(category)}` });
    if (status === 'archived') chips.push({ key: 'status', label: 'Archived' });
    return chips;
  }, [q, classFilter, publishState, licenseTier, category, status, isSuperadmin]);

  const clearAll = () => {
    setSearchParams({}, { replace: true });
    setQInput('');
  };

  return (
    <Motion variants={fadeInUp} initial="hidden" animate="visible" className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <h1 className="font-display text-3xl text-fg">Products</h1>
          <p className="mt-1 max-w-xl text-sm text-fg-muted">
            Browse every catalogue product. Create in-house blanks, edit pricing, and publish. Scraped
            and 3D items also flow through the catalogue gate.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <LinkButton to="/product-admin/new">New product</LinkButton>
          <div className="relative inline-flex">
            <LinkButton to="/catalogue-admin" variant="outline">
              Catalogue gate
            </LinkButton>
            {!!gateCount && (
              <Badge tone="brand" size="sm" className="ml-2 self-center">
                {gateCount}
              </Badge>
            )}
          </div>
        </div>
      </header>

      {/* Toolbar: search + a single Filters entry point */}
      <div className="flex flex-col gap-3">
        <div className="flex flex-col gap-2 sm:flex-row sm:items-end">
          <div className="flex-1">
            <Input
              label="Search"
              placeholder="Search by name…"
              value={qInput}
              onChange={(e) => setQInput(e.target.value)}
            />
          </div>
          <Button variant="outline" onClick={() => setFiltersOpen(true)} className="sm:mb-0.5">
            <FilterIcon />
            Filters
            {filterChips.length > 0 && (
              <Badge tone="brand" size="sm" className="ml-2">
                {filterChips.length}
              </Badge>
            )}
          </Button>
        </div>

        {/* Active filter chips (removable) */}
        {filterChips.length > 0 && (
          <div className="flex flex-wrap items-center gap-2">
            {filterChips.map((chip) => (
              <button
                key={chip.key}
                type="button"
                onClick={() => setParam(chip.key, '')}
                className="inline-flex items-center gap-1.5 rounded-full border border-border bg-surface px-3 py-1 text-xs font-medium text-fg transition-colors hover:border-danger hover:text-danger"
                aria-label={`Remove filter: ${chip.label}`}
              >
                {chip.label}
                <span aria-hidden="true">✕</span>
              </button>
            ))}
            <Button variant="ghost" size="sm" onClick={clearAll}>
              Clear all
            </Button>
          </div>
        )}
      </div>

      {/* Filters modal - live-applies to the URL as the admin picks */}
      <Modal
        open={filtersOpen}
        onClose={() => setFiltersOpen(false)}
        title="Filters"
        size="lg"
        footer={
          <>
            <Button variant="ghost" onClick={clearAll}>
              Clear all
            </Button>
            <Button variant="primary" onClick={() => setFiltersOpen(false)}>
              Done
            </Button>
          </>
        }
      >
        <div className="grid gap-3 sm:grid-cols-2">
          <Select label="Class" value={classFilter} onChange={(e) => setParam('class', e.target.value)}>
            <option value="">All classes</option>
            <option value="CORE">Core</option>
            <option value="SCRAPED_UV">UV Print</option>
            <option value="MODEL_3D">3D Printed</option>
          </Select>
          <Select
            label="Publish state"
            value={publishState}
            onChange={(e) => setParam('publish_state', e.target.value === 'PUBLISHED' ? '' : e.target.value)}
          >
            <option value="PUBLISHED">Published</option>
            <option value="all">All states</option>
            <option value="PENDING">Pending</option>
            <option value="READY_TO_APPROVE">Ready to approve</option>
            <option value="CANNOT_PUBLISH">Cannot publish</option>
          </Select>
          {isSuperadmin && (
            <Select label="Licence tier" value={licenseTier} onChange={(e) => setParam('license_tier', e.target.value)}>
              <option value="">All tiers</option>
              <option value="standard">Standard</option>
              <option value="extended">Extended</option>
              <option value="high_risk">High risk</option>
            </Select>
          )}
          <Select label="Category" value={category} onChange={(e) => setParam('category', e.target.value)}>
            <option value="">All categories</option>
            {CATEGORIES.map((c) => (
              <option key={c.key} value={c.key}>
                {c.label}
              </option>
            ))}
          </Select>
          <Select
            label="Status"
            value={status}
            onChange={(e) => setParam('status', e.target.value === 'active' ? '' : e.target.value)}
          >
            <option value="active">Active</option>
            <option value="archived">Archived</option>
          </Select>
          <Select
            label="Per page"
            value={String(perPage)}
            onChange={(e) => setParam('per_page', Number(e.target.value) === 15 ? '' : e.target.value)}
          >
            {PER_PAGE_OPTIONS.map((n) => (
              <option key={n} value={n}>
                {n}
              </option>
            ))}
          </Select>
          <Select
            label="Sort by"
            value={sort}
            onChange={(e) => setParam('sort', e.target.value === 'newest' ? '' : e.target.value)}
          >
            <option value="newest">Newest</option>
            <option value="most_sold">Most sold</option>
            <option value="name">Name</option>
            <option value="base_cost">Base cost</option>
            <option value="stock">Stock</option>
          </Select>
          <div className="flex items-end">
            <Button
              variant="outline"
              className="w-full"
              aria-label={dir === 'asc' ? 'Ascending - click for descending' : 'Descending - click for ascending'}
              onClick={() => setParam('dir', dir === 'asc' ? '' : 'asc')}
            >
              {dir === 'asc' ? '↑ Ascending' : '↓ Descending'}
            </Button>
          </div>
        </div>
      </Modal>

      <AsyncBoundary
        loading={loading}
        error={error}
        isEmpty={products.length === 0}
        emptyTitle={archivedView ? 'No archived products.' : 'No products match these filters.'}
        onRetry={load}
      >
        <Card padding="none" className="overflow-hidden">
          <div className="overflow-x-auto">
            <ul className="flex min-w-[40rem] flex-col divide-y divide-border">
              {products.map((p) => (
                <li key={p.id} className="flex items-center">
                  <button
                    type="button"
                    onClick={() => navigate(`/product-admin/${p.id}`)}
                    className="flex min-w-0 flex-1 items-center gap-4 px-4 py-3 text-left transition-colors hover:bg-surface-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset"
                  >
                    <ItemThumb name={p.name} imageUrl={p.image_url} />
                    <div className="min-w-0 flex-1">
                      <p className="truncate font-medium text-fg">{p.name}</p>
                      <div className="mt-1 flex flex-wrap items-center gap-2">
                        <Badge tone="neutral" size="sm">
                          {classLabel(p.class)}
                        </Badge>
                        <PublishBadge state={p.publish_state} />
                        {isSuperadmin && <LicenseTierBadge tier={p.license_tier} />}
                      </div>
                    </div>
                    <div className="shrink-0 text-right">
                      <p className="text-sm font-medium text-fg">
                        {p.currency} {Number(p.selling_price).toFixed(2)}
                      </p>
                      <p className="text-2xs text-fg-subtle">
                        cost {p.currency} {Number(p.base_cost).toFixed(2)}
                      </p>
                      <p className="text-xs text-fg-subtle">{p.sold_count} sold</p>
                    </div>
                  </button>
                  <button
                    type="button"
                    onClick={() => setQuickViewId(p.id)}
                    aria-label={`Quick view ${p.name}`}
                    className="mx-2 shrink-0 rounded-md p-2 text-fg-subtle transition-colors hover:bg-surface-2 hover:text-fg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                  >
                    <EyeIcon />
                  </button>
                </li>
              ))}
            </ul>
          </div>
        </Card>
      </AsyncBoundary>

      {/* Pagination */}
      {meta && meta.last_page > 1 && (
        <div className="flex flex-col items-center gap-2">
          <Pagination page={meta.current_page} lastPage={meta.last_page} onGoto={goToPage} disabled={loading} />
          <span className="text-sm text-fg-muted">{rangeLabel}</span>
        </div>
      )}

      <ProductQuickView
        productId={quickViewId}
        isSuperadmin={isSuperadmin}
        onClose={() => setQuickViewId(null)}
      />
    </Motion>
  );
}
