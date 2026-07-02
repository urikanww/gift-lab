import { useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { apiError } from '../lib/api';
import { fetchCatalogue, productPath } from '../lib/catalogue';
import { CATEGORIES, categoryLabel } from '../lib/categories';
import { Badge, Button, EmptyState, Input, Select } from '../ui';
import { ErrorState } from '../components/ui/States';
import { ProductCard, CardSkeleton } from '../components/product/ProductCard';
import { Motion, fadeInUp, staggerContainer } from '../motion';
import type { Product } from '../types';

const CLASS_KEYS = new Set<string>(CATEGORIES.map((c) => c.key));

function parseClass(value: string | null): string {
  return value && CLASS_KEYS.has(value) ? value : '';
}

export default function CataloguePage() {
  const [searchParams, setSearchParams] = useSearchParams();

  const [products, setProducts] = useState<Product[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);

  // Initialize filter state from URL params (?q=, ?class=). Unknown class values
  // are ignored. Filtering/search stays client-side over the loaded page.
  const [query, setQuery] = useState(() => searchParams.get('q') ?? '');
  const [classFilter, setClassFilter] = useState<string>(() =>
    parseClass(searchParams.get('class')),
  );

  // Category filtering is server-side (the catalogue paginates at 24/page, so
  // filtering only the loaded page would hide matches on later pages). `cls`
  // defaults to the current filter so pagination stays within the category.
  const load = async (target = 1, cls: string = classFilter) => {
    setLoading(true);
    setError(null);
    try {
      const data = await fetchCatalogue({ page: target, category: cls || undefined });
      setProducts(data.data);
      setPage(data.meta?.current_page ?? target);
      setLastPage(data.meta?.last_page ?? 1);
    } catch (err) {
      setError(apiError(err));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    void load(1);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const setQueryParam = (next: string) => {
    setQuery(next);
    setSearchParams(
      (prev) => {
        const params = new URLSearchParams(prev);
        if (next.trim()) params.set('q', next);
        else params.delete('q');
        return params;
      },
      { replace: true },
    );
  };

  const setClassParam = (next: string) => {
    setClassFilter(next);
    setSearchParams(
      (prev) => {
        const params = new URLSearchParams(prev);
        if (next) params.set('class', next);
        else params.delete('class');
        return params;
      },
      { replace: true },
    );
    void load(1, next);
  };

  // Text search stays client-side over the loaded (already class-scoped) page.
  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    return products.filter((p) => {
      if (q && !p.name.toLowerCase().includes(q) && !(p.creator_credit ?? '').toLowerCase().includes(q)) {
        return false;
      }
      return true;
    });
  }, [products, query]);

  const hasActiveFilter = query.trim() !== '' || classFilter !== '';
  const clearFilters = () => {
    setQuery('');
    setClassFilter('');
    setSearchParams(
      (prev) => {
        const params = new URLSearchParams(prev);
        params.delete('q');
        params.delete('class');
        return params;
      },
      { replace: true },
    );
    void load(1, '');
  };

  return (
    <div className="flex flex-col gap-10">
      {/* Signature hero — editorial, warm, scroll-stopping. */}
      <Motion
        variants={fadeInUp}
        initial="hidden"
        animate="visible"
        className="relative overflow-hidden rounded-2xl border border-border bg-gradient-to-br from-brand-50 via-surface to-accent-50 px-6 py-12 sm:px-10 sm:py-16"
      >
        <div
          className="pointer-events-none absolute -right-16 -top-16 h-64 w-64 rounded-full bg-brand-100/50 blur-3xl"
          aria-hidden="true"
        />
        <div
          className="pointer-events-none absolute -bottom-20 -left-10 h-56 w-56 rounded-full bg-accent-100/40 blur-3xl"
          aria-hidden="true"
        />
        <div className="relative max-w-2xl">
          <Badge tone="brand" size="sm" dot>
            Custom gifting, made effortless
          </Badge>
          <h1 className="mt-4 font-display text-4xl leading-tight text-fg sm:text-5xl">
            Gifts worth remembering,
            <br className="hidden sm:block" /> crafted to your brand.
          </h1>
          <p className="mt-4 max-w-xl text-base text-fg-muted sm:text-lg">
            Browse our boutique of customisable pieces — UV-printed, 3D-crafted and
            core essentials. No account needed until you request a quote.
          </p>
        </div>
      </Motion>

      {/* Sticky filter bar */}
      <div className="sticky top-16 z-raised -mx-4 border-y border-border bg-bg/85 px-4 py-3 backdrop-blur-md sm:top-20">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
          <div className="flex-1">
            <Input
              type="search"
              label="Search catalogue"
              placeholder="Search by name or maker…"
              value={query}
              onChange={(e) => setQueryParam(e.target.value)}
              leadingIcon={<SearchIcon />}
            />
          </div>
          <div className="sm:w-56">
            <Select
              label="Category"
              value={classFilter}
              onChange={(e) => setClassParam(e.target.value)}
            >
              <option value="">All categories</option>
              {CATEGORIES.map((c) => (
                <option key={c.key} value={c.key}>
                  {categoryLabel(c.key)}
                </option>
              ))}
            </Select>
          </div>
          {hasActiveFilter && (
            <Button variant="ghost" size="md" onClick={clearFilters} className="sm:mb-0">
              Clear
            </Button>
          )}
        </div>
      </div>

      {/* Async surface: bespoke skeleton grid on load, error/empty gated below. */}
      {loading ? (
        <>
          <span className="sr-only" role="status" aria-live="polite">
            Loading catalogue…
          </span>
          <div
            className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
            aria-hidden="true"
          >
            {Array.from({ length: 8 }).map((_, i) => (
              <CardSkeleton key={i} />
            ))}
          </div>
        </>
      ) : error ? (
        <ErrorState message={error} onRetry={() => load(page)} />
      ) : products.length === 0 ? (
        <EmptyState
          title="No products published yet"
          description="Our makers are hard at work. Check back soon for new customisable gifts."
          action={
            <Button variant="outline" onClick={() => load(page)}>
              Refresh
            </Button>
          }
        />
      ) : filtered.length === 0 ? (
        <EmptyState
          title="Nothing matches your search"
          description="Try a different keyword or clear the filters to see the full catalogue."
          action={
            <Button variant="outline" onClick={clearFilters}>
              Clear filters
            </Button>
          }
        />
      ) : (
        <>
          <Motion
            variants={staggerContainer}
            initial="hidden"
            animate="visible"
            className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
          >
            {filtered.map((p) => (
              <ProductCard key={p.id} product={p} to={productPath(p)} showMeta />
            ))}
          </Motion>

          {/* Class filtering is server-side, so pagination remains valid with a
              category selected; only a client-side text query disables it. */}
          {lastPage > 1 && query.trim() === '' && (
            <nav className="flex items-center justify-center gap-4" aria-label="Pagination">
              <Button
                variant="outline"
                size="sm"
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
                size="sm"
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

function SearchIcon() {
  return (
    <svg viewBox="0 0 20 20" className="h-4 w-4" fill="none" aria-hidden="true">
      <circle cx="9" cy="9" r="5.5" stroke="currentColor" strokeWidth="1.6" />
      <path d="m13.5 13.5 3 3" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" />
    </svg>
  );
}
