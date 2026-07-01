import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api, { apiError } from '../lib/api';
import { safeHref } from '../lib/safeHref';
import { Badge, Button, EmptyState, Input, Select, Skeleton } from '../ui';
import { ErrorState } from '../components/ui/States';
import { Motion, fadeInUp, staggerContainer, staggerItem } from '../motion';
import type { Paginated, Product, ProductClass } from '../types';

/**
 * Scraped image URLs are external and untrusted: route through safeHref (drops
 * javascript:/data: etc.), fall back to a monogram placeholder on load error,
 * and suppress the referrer on the outbound request.
 */
function CardImage({ product }: { product: Product }) {
  const [failed, setFailed] = useState(false);
  const href = safeHref(product.image_url);

  if (!href || failed) {
    return (
      <div
        className="flex h-full w-full items-center justify-center bg-gradient-to-br from-brand-100 to-accent-50 font-display text-5xl text-brand-700"
        aria-hidden="true"
      >
        {product.name.charAt(0)}
      </div>
    );
  }

  return (
    <img
      src={href}
      alt=""
      className="h-full w-full object-cover transition-transform duration-slow ease-out group-hover:scale-[1.04] motion-reduce:transition-none motion-reduce:group-hover:scale-100"
      loading="lazy"
      referrerPolicy="no-referrer"
      onError={() => setFailed(true)}
    />
  );
}

const CLASS_LABELS: Record<ProductClass, string> = {
  CORE: 'Core',
  SCRAPED_UV: 'UV Print',
  MODEL_3D: '3D Printed',
};

const CLASS_TONE: Record<ProductClass, 'brand' | 'info' | 'success'> = {
  CORE: 'brand',
  SCRAPED_UV: 'info',
  MODEL_3D: 'success',
};

function ProductCard({ product }: { product: Product }) {
  return (
    <Motion variants={staggerItem} className="h-full">
      <Link
        to={`/catalogue/${product.id}`}
        className="group flex h-full flex-col overflow-hidden rounded-lg border border-border bg-surface shadow-card transition-shadow duration-base ease-standard hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-bg"
      >
        <div className="relative aspect-[4/3] w-full overflow-hidden bg-surface-2">
          <CardImage product={product} />
          {product.class && CLASS_LABELS[product.class] && (
            <div className="absolute left-3 top-3">
              <Badge tone={CLASS_TONE[product.class]} size="sm">
                {CLASS_LABELS[product.class]}
              </Badge>
            </div>
          )}
        </div>
        <div className="flex flex-1 flex-col gap-1 p-4">
          <h3 className="font-display text-lg leading-snug text-fg transition-colors duration-fast group-hover:text-primary">
            {product.name}
          </h3>
          {product.creator_credit && (
            <p className="text-xs text-fg-subtle">by {product.creator_credit}</p>
          )}
          <p className="mt-auto pt-2 text-sm text-fg-muted">
            <span className="text-2xs uppercase tracking-wide text-fg-subtle">from </span>
            <span className="font-medium text-fg">
              {product.currency} {product.from_price.toFixed(2)}
            </span>
          </p>
        </div>
      </Link>
    </Motion>
  );
}

function CardSkeleton() {
  return (
    <div className="overflow-hidden rounded-lg border border-border bg-surface shadow-card">
      <Skeleton className="aspect-[4/3] w-full rounded-none" />
      <div className="flex flex-col gap-2 p-4">
        <Skeleton height={18} width="70%" />
        <Skeleton height={14} width="40%" />
      </div>
    </div>
  );
}

export default function CataloguePage() {
  const [products, setProducts] = useState<Product[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);

  const [query, setQuery] = useState('');
  const [classFilter, setClassFilter] = useState<'' | ProductClass>('');

  const load = async (target = 1) => {
    setLoading(true);
    setError(null);
    try {
      const { data } = await api.get<Paginated<Product>>('/catalogue', { params: { page: target } });
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
  }, []);

  // Categories present on the current page — client-side refinement over the
  // loaded set (no API contract change).
  const classesOnPage = useMemo(() => {
    const set = new Set<ProductClass>();
    products.forEach((p) => {
      if (p.class && CLASS_LABELS[p.class]) set.add(p.class);
    });
    return Array.from(set);
  }, [products]);

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    return products.filter((p) => {
      if (classFilter && p.class !== classFilter) return false;
      if (q && !p.name.toLowerCase().includes(q) && !(p.creator_credit ?? '').toLowerCase().includes(q)) {
        return false;
      }
      return true;
    });
  }, [products, query, classFilter]);

  const hasActiveFilter = query.trim() !== '' || classFilter !== '';
  const clearFilters = () => {
    setQuery('');
    setClassFilter('');
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
              onChange={(e) => setQuery(e.target.value)}
              leadingIcon={<SearchIcon />}
            />
          </div>
          <div className="sm:w-56">
            <Select
              label="Category"
              value={classFilter}
              onChange={(e) => setClassFilter(e.target.value as '' | ProductClass)}
            >
              <option value="">All categories</option>
              {classesOnPage.map((c) => (
                <option key={c} value={c}>
                  {CLASS_LABELS[c]}
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
              <ProductCard key={p.id} product={p} />
            ))}
          </Motion>

          {lastPage > 1 && !hasActiveFilter && (
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
