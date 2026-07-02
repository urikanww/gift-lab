import { useEffect, useState, type FormEvent } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Button, EmptyState, Input } from '../ui';
import { ErrorState } from '../components/ui/States';
import { CardSkeleton, ProductCard } from '../components/product/ProductCard';
import { Motion, fadeInUp, staggerContainer } from '../motion';
import { fetchCatalogue, productPath } from '../lib/catalogue';
import { CATEGORIES } from '../lib/categories';
import type { Product } from '../types';

const MAX_POPULAR = 10;
const MAX_NEW = 8;

export default function HomePage() {
  const navigate = useNavigate();
  const [popular, setPopular] = useState<Product[]>([]);
  const [fresh, setFresh] = useState<Product[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = async (isActive: () => boolean = () => true) => {
    setLoading(true);
    setError(null);
    try {
      const [pop, latest] = await Promise.all([
        fetchCatalogue({}),
        fetchCatalogue({ sort: 'newest' }),
      ]);
      if (!isActive()) return;
      setPopular(pop.data.slice(0, MAX_POPULAR));
      setFresh(latest.data.slice(0, MAX_NEW));
    } catch {
      if (!isActive()) return;
      setError('We could not load products right now.');
    } finally {
      if (isActive()) setLoading(false);
    }
  };

  useEffect(() => {
    let active = true;
    void load(() => active);
    return () => {
      active = false;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const onSearch = (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const value = new FormData(e.currentTarget).get('q')?.toString().trim() ?? '';
    navigate(value ? `/products?q=${encodeURIComponent(value)}` : '/products');
  };

  return (
    <div className="flex flex-col gap-8 sm:gap-10">
      {/* ── Compact search-first hero ─────────────────────────────────────── */}
      <Motion
        variants={fadeInUp}
        initial="hidden"
        animate="visible"
        className="relative overflow-hidden rounded-2xl border border-border bg-gradient-to-br from-brand-50 via-surface to-accent-50 px-6 py-8 sm:px-10 sm:py-10"
      >
        <div
          className="pointer-events-none absolute -right-16 -top-16 h-56 w-56 rounded-full bg-brand-100/50 blur-3xl"
          aria-hidden="true"
        />
        <div className="relative max-w-2xl">
          <h1 className="font-display text-3xl font-bold leading-tight text-fg sm:text-4xl">
            The marketplace for personalised gifts.
          </h1>
          <form onSubmit={onSearch} role="search" className="mt-4 flex max-w-xl gap-2">
            <div className="flex-1">
              <Input
                name="q"
                type="search"
                aria-label="Search gifts"
                placeholder="Search mugs, totes, figurines…"
              />
            </div>
            <Button type="submit" variant="primary" size="md">
              Search
            </Button>
          </form>
          <div className="mt-3 flex flex-wrap gap-1.5 text-sm">
            <span className="text-fg-subtle">Popular:</span>
            {CATEGORIES.slice(0, 4).map((c) => (
              <Link
                key={c.key}
                to={`/products?category=${c.key}`}
                className="text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              >
                {c.label}
              </Link>
            ))}
          </div>
        </div>
      </Motion>

      {/* ── Shop by category — 8 marketplace tiles ────────────────────────── */}
      <section aria-labelledby="home-categories">
        <h2 id="home-categories" className="font-display text-xl text-fg sm:text-2xl">
          Shop by category
        </h2>
        <div className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
          {CATEGORIES.map((c) => (
            <Link
              key={c.key}
              to={`/products?category=${c.key}`}
              className="group flex items-center gap-3 rounded-xl border border-border bg-surface p-4 shadow-card transition-all duration-base ease-standard hover:-translate-y-0.5 hover:border-primary/50 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-bg motion-reduce:hover:translate-y-0"
            >
              <span className="text-3xl" aria-hidden="true">
                {c.icon}
              </span>
              <span>
                <span className="block font-display text-sm text-fg transition-colors duration-fast group-hover:text-primary sm:text-base">
                  {c.label}
                </span>
                <span className="hidden text-xs text-fg-muted sm:block">{c.blurb}</span>
              </span>
            </Link>
          ))}
        </div>
      </section>

      {/* ── New arrivals — horizontal snap rail ───────────────────────────── */}
      <section aria-labelledby="home-new">
        <div className="flex items-end justify-between gap-4">
          <h2 id="home-new" className="font-display text-xl text-fg sm:text-2xl">
            New arrivals
          </h2>
          <Link
            to="/products?sort=newest"
            className="text-sm font-medium text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          >
            View all
          </Link>
        </div>
        <div className="mt-4">
          {loading ? (
            <div className="flex gap-4 overflow-hidden" aria-hidden="true">
              {Array.from({ length: 4 }).map((_, i) => (
                <div key={i} className="w-52 shrink-0">
                  <CardSkeleton />
                </div>
              ))}
            </div>
          ) : fresh.length > 0 ? (
            <div className="-mx-4 flex snap-x gap-4 overflow-x-auto px-4 pb-2 sm:-mx-6 sm:px-6">
              {fresh.map((p) => (
                <div key={p.id} className="w-52 shrink-0 snap-start">
                  <ProductCard product={p} to={productPath(p)} showMeta />
                </div>
              ))}
            </div>
          ) : null}
        </div>
      </section>

      {/* ── Popular right now — dense grid ────────────────────────────────── */}
      <section aria-labelledby="home-popular">
        <div className="flex items-end justify-between gap-4">
          <h2 id="home-popular" className="font-display text-xl text-fg sm:text-2xl">
            Popular right now
          </h2>
          <Link
            to="/products"
            className="text-sm font-medium text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          >
            View all
          </Link>
        </div>
        <div className="mt-4">
          {loading ? (
            <>
              <span className="sr-only" role="status" aria-live="polite">
                Loading products…
              </span>
              <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5" aria-hidden="true">
                {Array.from({ length: 5 }).map((_, i) => (
                  <CardSkeleton key={i} />
                ))}
              </div>
            </>
          ) : error ? (
            <ErrorState message={error} onRetry={() => void load()} />
          ) : popular.length === 0 ? (
            <EmptyState
              title="No products published yet"
              description="Our makers are hard at work. Check back soon for new customisable gifts."
              action={
                <Button variant="outline" onClick={() => void load()}>
                  Refresh
                </Button>
              }
            />
          ) : (
            <Motion
              variants={staggerContainer}
              initial="hidden"
              animate="visible"
              className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5"
            >
              {popular.map((p) => (
                <ProductCard key={p.id} product={p} to={productPath(p)} showMeta />
              ))}
            </Motion>
          )}
        </div>
      </section>
    </div>
  );
}
