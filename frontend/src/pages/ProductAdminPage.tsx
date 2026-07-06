import { useCallback, useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api, { apiError } from '../lib/api';
import { AsyncBoundary } from '../components/ui/States';
import { Badge, Button, Card, Input, LinkButton, Select } from '../ui';
import { Motion, fadeInUp } from '../motion';
import { CATEGORIES } from '../lib/categories';
import { useAuthStore } from '../stores/authStore';
import type { AdminProduct } from '../types';
import { classLabel, ItemThumb, LicenseTierBadge, PublishBadge } from './adminProductBadges';

interface Meta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

const PER_PAGE = 50;

type SortKey = 'newest' | 'most_sold' | 'name' | 'base_cost' | 'stock';

/**
 * Server-driven product browser (route /product-admin). All filtering, sorting
 * and pagination happen on the API; this page just reflects the query state.
 * Create and edit live on their own pages (/product-admin/new, /:id).
 */
export default function ProductAdminPage() {
  const navigate = useNavigate();
  const isSuperadmin = useAuthStore((s) => s.user?.role === 'superadmin');

  const [products, setProducts] = useState<AdminProduct[]>([]);
  const [meta, setMeta] = useState<Meta | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [page, setPage] = useState(1);
  const [status, setStatus] = useState<'active' | 'archived'>('active');
  const [classFilter, setClassFilter] = useState('');
  const [publishState, setPublishState] = useState('');
  const [licenseTier, setLicenseTier] = useState('');
  const [category, setCategory] = useState('');
  const [q, setQ] = useState('');
  const [debouncedQ, setDebouncedQ] = useState('');
  const [sort, setSort] = useState<SortKey>('newest');
  const [dir, setDir] = useState<'asc' | 'desc'>('desc');

  // Debounce the free-text search so typing doesn't fire a request per keystroke.
  useEffect(() => {
    const t = setTimeout(() => setDebouncedQ(q), 300);
    return () => clearTimeout(t);
  }, [q]);

  // Any filter/sort change resets to page 1 (a filtered set has fewer pages).
  useEffect(() => {
    setPage(1);
  }, [status, classFilter, publishState, licenseTier, category, debouncedQ, sort, dir]);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const { data } = await api.get<{ data: AdminProduct[]; meta: Meta }>('/admin/products', {
        params: {
          page,
          per_page: PER_PAGE,
          status,
          class: classFilter || undefined,
          publish_state: publishState || undefined,
          license_tier: licenseTier || undefined,
          category: category || undefined,
          q: debouncedQ || undefined,
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
  }, [page, status, classFilter, publishState, licenseTier, category, debouncedQ, sort, dir]);

  useEffect(() => {
    void load();
  }, [load]);

  const archivedView = status === 'archived';

  const rangeLabel = useMemo(() => {
    if (!meta) return '';
    return `Page ${meta.current_page} of ${meta.last_page} · ${meta.total} total`;
  }, [meta]);

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
        <div className="flex flex-wrap gap-2">
          <LinkButton to="/product-admin/new">New product</LinkButton>
          <LinkButton to="/catalogue-admin" variant="outline">
            Catalogue gate
          </LinkButton>
        </div>
      </header>

      {/* Controls */}
      <Card padding="lg" className="flex flex-col gap-4">
        <Input
          label="Search"
          placeholder="Search by name…"
          value={q}
          onChange={(e) => setQ(e.target.value)}
        />
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
          <Select label="Class" value={classFilter} onChange={(e) => setClassFilter(e.target.value)}>
            <option value="">All classes</option>
            <option value="CORE">Core</option>
            <option value="SCRAPED_UV">UV Print</option>
            <option value="MODEL_3D">3D Printed</option>
          </Select>
          <Select label="Publish state" value={publishState} onChange={(e) => setPublishState(e.target.value)}>
            <option value="">All states</option>
            <option value="PENDING">Pending</option>
            <option value="READY_TO_APPROVE">Ready to approve</option>
            <option value="PUBLISHED">Published</option>
            <option value="CANNOT_PUBLISH">Cannot publish</option>
          </Select>
          {isSuperadmin && (
            <Select label="Licence tier" value={licenseTier} onChange={(e) => setLicenseTier(e.target.value)}>
              <option value="">All tiers</option>
              <option value="standard">Standard</option>
              <option value="extended">Extended</option>
              <option value="high_risk">High risk</option>
            </Select>
          )}
          <Select label="Category" value={category} onChange={(e) => setCategory(e.target.value)}>
            <option value="">All categories</option>
            {CATEGORIES.map((c) => (
              <option key={c.key} value={c.key}>
                {c.label}
              </option>
            ))}
          </Select>
          <Select label="Status" value={status} onChange={(e) => setStatus(e.target.value as 'active' | 'archived')}>
            <option value="active">Active</option>
            <option value="archived">Archived</option>
          </Select>
          <div className="flex items-end gap-2">
            <div className="flex-1">
              <Select label="Sort by" value={sort} onChange={(e) => setSort(e.target.value as SortKey)}>
                <option value="newest">Newest</option>
                <option value="most_sold">Most sold</option>
                <option value="name">Name</option>
                <option value="base_cost">Base cost</option>
                <option value="stock">Stock</option>
              </Select>
            </div>
            <Button
              variant="outline"
              size="sm"
              className="mb-0.5"
              aria-label={dir === 'asc' ? 'Ascending — click for descending' : 'Descending — click for ascending'}
              onClick={() => setDir((d) => (d === 'asc' ? 'desc' : 'asc'))}
            >
              {dir === 'asc' ? '↑ Asc' : '↓ Desc'}
            </Button>
          </div>
        </div>
      </Card>

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
                <li key={p.id}>
                  <button
                    type="button"
                    onClick={() => navigate(`/product-admin/${p.id}`)}
                    className="flex w-full items-center gap-4 px-4 py-3 text-left transition-colors hover:bg-surface-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
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
                        {p.currency} {Number(p.base_cost).toFixed(2)}
                      </p>
                      <p className="text-xs text-fg-subtle">{p.sold_count} sold</p>
                    </div>
                  </button>
                </li>
              ))}
            </ul>
          </div>
        </Card>
      </AsyncBoundary>

      {/* Pagination */}
      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between gap-4">
          <span className="text-sm text-fg-muted">{rangeLabel}</span>
          <div className="flex gap-2">
            <Button
              variant="outline"
              size="sm"
              disabled={loading || meta.current_page <= 1}
              onClick={() => setPage((n) => Math.max(1, n - 1))}
            >
              Prev
            </Button>
            <Button
              variant="outline"
              size="sm"
              disabled={loading || meta.current_page >= meta.last_page}
              onClick={() => setPage((n) => n + 1)}
            >
              Next
            </Button>
          </div>
        </div>
      )}
    </Motion>
  );
}
