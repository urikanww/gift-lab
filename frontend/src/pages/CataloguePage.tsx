import { useEffect, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { apiError } from '../lib/api';
import { fetchCatalogue, productPath, type CatalogueSort } from '../lib/catalogue';
import { CATEGORIES } from '../lib/categories';
import { Button, EmptyState, Input, Select, cn } from '../ui';
import { ErrorState } from '../components/ui/States';
import { ProductCard, CardSkeleton } from '../components/product/ProductCard';
import { Motion, staggerContainer } from '../motion';
import type { Product } from '../types';

const SORTS: { value: CatalogueSort; label: string }[] = [
  { value: 'name', label: 'Name (A–Z)' },
  { value: 'newest', label: 'Newest' },
  { value: 'price_asc', label: 'Price: low to high' },
  { value: 'price_desc', label: 'Price: high to low' },
];

const CATEGORY_KEYS = new Set(CATEGORIES.map((c) => c.key));
const SORT_KEYS = new Set<string>(SORTS.map((s) => s.value));

const GRID = 'grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5';

export default function CataloguePage() {
  const [searchParams, setSearchParams] = useSearchParams();

  // URL is the single source of truth for all filters (shareable/bookmarkable).
  const query = searchParams.get('q') ?? '';
  const rawCategory = searchParams.get('category');
  const category = rawCategory && CATEGORY_KEYS.has(rawCategory) ? rawCategory : '';
  const rawSort = searchParams.get('sort');
  const sort: CatalogueSort = rawSort && SORT_KEYS.has(rawSort) ? (rawSort as CatalogueSort) : 'name';

  const [products, setProducts] = useState<Product[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);

  // Monotonic request token: only the latest initiated load may commit state,
  // so pagination and debounced search can never clobber each other.
  const requestSeq = useRef(0);

  const load = async (target: number) => {
    const seq = ++requestSeq.current;
    const isCurrent = () => seq === requestSeq.current;
    setLoading(true);
    setError(null);
    try {
      const data = await fetchCatalogue({ page: target, category: category || undefined, q: query, sort });
      if (!isCurrent()) return;
      setProducts(data.data);
      setPage(data.meta?.current_page ?? target);
      setLastPage(data.meta?.last_page ?? 1);
      setTotal(data.meta?.total ?? data.data.length);
    } catch (err) {
      if (isCurrent()) setError(apiError(err));
    } finally {
      if (isCurrent()) setLoading(false);
    }
  };

  // Server-side search/filter/sort: reload page 1 whenever any input changes.
  // Text input is debounced so we don't fire a request per keystroke.
  useEffect(() => {
    const timer = setTimeout(() => void load(1), query ? 250 : 0);
    return () => {
      clearTimeout(timer);
      // Invalidate any in-flight load from the superseded filter state.
      requestSeq.current++;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [query, category, sort]);

  const setParam = (key: string, value: string) => {
    setSearchParams(
      (prev) => {
        const params = new URLSearchParams(prev);
        if (value) params.set(key, value);
        else params.delete(key);
        return params;
      },
      { replace: true },
    );
  };

  const hasActiveFilter = query.trim() !== '' || category !== '' || sort !== 'name';
  const clearFilters = () => setSearchParams({}, { replace: true });

  return (
    <div className="flex flex-col gap-5">
      {/* ── Slim toolbar: title + count + search + sort ──────────────────── */}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h1 className="font-display text-2xl text-fg sm:text-3xl">Marketplace</h1>
          <p className="text-sm text-fg-muted">
            {loading ? 'Loading…' : `${total} customisable gift${total === 1 ? '' : 's'}`}
          </p>
        </div>
        <div className="flex flex-col gap-2 sm:flex-row sm:items-end">
          <div className="sm:w-64">
            <Input
              type="search"
              label="Search"
              placeholder="Search all gifts…"
              value={query}
              onChange={(e) => setParam('q', e.target.value)}
            />
          </div>
          <div className="sm:w-48">
            <Select label="Sort by" value={sort} onChange={(e) => setParam('sort', e.target.value)}>
              {SORTS.map((s) => (
                <option key={s.value} value={s.value}>
                  {s.label}
                </option>
              ))}
            </Select>
          </div>
          {hasActiveFilter && (
            <Button variant="ghost" size="md" onClick={clearFilters}>
              Clear
            </Button>
          )}
        </div>
      </div>

      {/* ── Category chip rail (sticky, horizontally scrollable) ─────────── */}
      <div className="sticky top-16 z-sticky -mx-4 border-y border-border bg-bg/85 px-4 py-2 backdrop-blur-md sm:-mx-6 sm:px-6">
        <div
          className="flex gap-2 overflow-x-auto pb-0.5 [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
          role="group"
          aria-label="Shop by category"
        >
          <CategoryChip label="All" active={category === ''} onClick={() => setParam('category', '')} />
          {CATEGORIES.map((c) => (
            <CategoryChip
              key={c.key}
              icon={c.icon}
              label={c.label}
              active={category === c.key}
              onClick={() => setParam('category', c.key)}
            />
          ))}
        </div>
      </div>

      {/* ── Results ──────────────────────────────────────────────────────── */}
      {loading ? (
        <>
          <span className="sr-only" role="status" aria-live="polite">
            Loading catalogue…
          </span>
          <div className={GRID} aria-hidden="true">
            {Array.from({ length: 10 }).map((_, i) => (
              <CardSkeleton key={i} />
            ))}
          </div>
        </>
      ) : error ? (
        <ErrorState message={error} onRetry={() => void load(page)} />
      ) : products.length === 0 ? (
        <EmptyState
          title={hasActiveFilter ? 'Nothing matches your filters' : 'No products published yet'}
          description={
            hasActiveFilter
              ? 'Try a different keyword or category to see more gifts.'
              : 'Our makers are hard at work. Check back soon for new customisable gifts.'
          }
          action={
            <Button variant="outline" onClick={hasActiveFilter ? clearFilters : () => void load(1)}>
              {hasActiveFilter ? 'Clear filters' : 'Refresh'}
            </Button>
          }
        />
      ) : (
        <>
          <Motion variants={staggerContainer} initial="hidden" animate="visible" className={GRID}>
            {products.map((p) => (
              <ProductCard key={p.id} product={p} to={productPath(p)} showMeta />
            ))}
          </Motion>

          {lastPage > 1 && (
            <nav className="flex items-center justify-center gap-4" aria-label="Pagination">
              <Button
                variant="outline"
                size="md"
                className="min-h-[44px]"
                disabled={loading || page <= 1}
                onClick={() => void load(page - 1)}
              >
                Previous
              </Button>
              <span className="text-sm text-fg-muted">
                Page {page} of {lastPage}
              </span>
              <Button
                variant="outline"
                size="md"
                className="min-h-[44px]"
                disabled={loading || page >= lastPage}
                onClick={() => void load(page + 1)}
              >
                Next
              </Button>
            </nav>
          )}
        </>
      )}
    </div>
  );
}

function CategoryChip({
  label,
  icon,
  active,
  onClick,
}: {
  label: string;
  icon?: string;
  active: boolean;
  onClick: () => void;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={active}
      className={cn(
        'flex min-h-[44px] shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full border px-3.5 py-1.5 text-sm font-medium transition-colors duration-fast',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-bg',
        active
          ? 'border-primary bg-primary text-primary-fg'
          : 'border-border bg-surface text-fg-muted hover:border-primary/50 hover:text-fg',
      )}
    >
      {icon && <span aria-hidden="true">{icon}</span>}
      {label}
    </button>
  );
}
