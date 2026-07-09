import { useEffect, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { apiError } from '../lib/api';
import { fetchCatalogue, productPath, type CatalogueSort } from '../lib/catalogue';
import { CATEGORIES } from '../lib/categories';
import { Button, EmptyState, Input, Select, cn } from '../ui';
import { ErrorState } from '../components/ui/States';
import { ProductCard, CardSkeleton } from '../components/product/ProductCard';
import Pagination from '../components/Pagination';
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
  const rawPage = Number(searchParams.get('page'));
  const page = Number.isInteger(rawPage) && rawPage > 0 ? rawPage : 1;

  const [products, setProducts] = useState<Product[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
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
      setLastPage(data.meta?.last_page ?? 1);
      setTotal(data.meta?.total ?? data.data.length);
    } catch (err) {
      if (isCurrent()) setError(apiError(err));
    } finally {
      if (isCurrent()) setLoading(false);
    }
  };

  // URL-driven: reload whenever query/filter/sort/page changes. Page lives in the
  // URL so returning from a product detail (back-nav) restores the same page.
  // Text input is debounced so we don't fire a request per keystroke.
  useEffect(() => {
    const timer = setTimeout(() => void load(page), query ? 250 : 0);
    return () => {
      clearTimeout(timer);
      // Invalidate any in-flight load from the superseded filter state.
      requestSeq.current++;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [query, category, sort, page]);

  const setParam = (key: string, value: string) => {
    setSearchParams(
      (prev) => {
        const params = new URLSearchParams(prev);
        if (value) params.set(key, value);
        else params.delete(key);
        // Any filter change resets to page 1.
        if (key !== 'page') params.delete('page');
        return params;
      },
      { replace: true },
    );
  };

  const goToPage = (target: number) => {
    setSearchParams(
      (prev) => {
        const params = new URLSearchParams(prev);
        if (target <= 1) params.delete('page');
        else params.set('page', String(target));
        return params;
      },
      // Push a history entry so browser back steps through pages.
      { replace: false },
    );
    // A page change is a search-param change (same pathname), so the global
    // ScrollToTop doesn't fire - scroll the new page up to the results top.
    window.scrollTo({ top: 0, behavior: 'smooth' });
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

          <Pagination page={page} lastPage={lastPage} onGoto={goToPage} disabled={loading} />
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
