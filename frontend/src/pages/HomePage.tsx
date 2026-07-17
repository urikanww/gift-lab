import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Button, EmptyState } from '../ui';
import { ErrorState } from '../components/ui/States';
import { CardSkeleton, ProductCard } from '../components/product/ProductCard';
import CategoryRail from '../components/home/CategoryRail';
import PromoTiles from '../components/home/PromoTiles';
import ProductRail from '../components/home/ProductRail';
import ReorderRail from '../components/home/ReorderRail';
import { Motion, staggerContainer } from '../motion';
import { fetchCatalogue, productPath } from '../lib/catalogue';
import { useAuthStore } from '../stores/authStore';
import type { Product } from '../types';

const MAX_NEW = 8;

export default function HomePage() {
  const user = useAuthStore((s) => s.user);
  const [fresh, setFresh] = useState<Product[]>([]);
  const [browse, setBrowse] = useState<Product[]>([]);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = async (isActive: () => boolean = () => true) => {
    setLoading(true);
    setError(null);
    try {
      const [latest, first] = await Promise.all([
        fetchCatalogue({ sort: 'newest' }),
        fetchCatalogue({ page: 1 }),
      ]);
      if (!isActive()) return;
      setFresh(latest.data.slice(0, MAX_NEW));
      setBrowse(first.data);
      setPage(first.meta?.current_page ?? 1);
      setLastPage(first.meta?.last_page ?? 1);
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

  // Page size is server-controlled (24/page) - we only ever advance the page.
  const loadMore = async () => {
    setLoadingMore(true);
    try {
      const next = await fetchCatalogue({ page: page + 1 });
      setBrowse((prev) => [...prev, ...next.data]);
      setPage(next.meta?.current_page ?? page + 1);
      setLastPage(next.meta?.last_page ?? lastPage);
    } catch {
      // Keep what is already on screen: a failed append leaves the button in
      // place to retry rather than replacing a working grid with an error.
    } finally {
      setLoadingMore(false);
    }
  };

  return (
    <div className="flex flex-col gap-8 sm:gap-10">
      <CategoryRail />
      <PromoTiles />

      {user && <ReorderRail />}

      {/* A bare heading over a blank rail reads as broken, so the section is
          hidden outright when empty; the load error surfaces in Browse below. */}
      {(loading || fresh.length > 0) && (
        <section aria-labelledby="home-new">
          <div className="flex items-end justify-between gap-4">
            <h2 id="home-new" className="font-display text-xl text-fg sm:text-2xl">
              New arrivals
            </h2>
            <Link
              to="/products?sort=newest"
              className="inline-flex min-h-[44px] items-center text-sm font-medium text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
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
            ) : (
              <ProductRail items={fresh} label="new arrivals" />
            )}
          </div>
        </section>
      )}

      <section aria-labelledby="home-browse">
        <h2 id="home-browse" className="font-display text-xl text-fg sm:text-2xl">
          Browse the catalogue
        </h2>
        <div className="mt-4">
          {loading ? (
            <>
              <span className="sr-only" role="status" aria-live="polite">
                Loading products…
              </span>
              <div
                className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5"
                aria-hidden="true"
              >
                {Array.from({ length: 5 }).map((_, i) => (
                  <CardSkeleton key={i} />
                ))}
              </div>
            </>
          ) : error ? (
            <ErrorState message={error} onRetry={() => void load()} />
          ) : browse.length === 0 ? (
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
            <>
              <Motion
                variants={staggerContainer}
                initial="hidden"
                animate="visible"
                className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5"
              >
                {browse.map((p) => (
                  <ProductCard key={p.id} product={p} to={productPath(p)} showMeta />
                ))}
              </Motion>
              {page < lastPage && (
                <div className="mt-6 flex justify-center">
                  <Button variant="outline" onClick={() => void loadMore()} disabled={loadingMore}>
                    {loadingMore ? 'Loading…' : 'Load more'}
                  </Button>
                </div>
              )}
            </>
          )}
        </div>
      </section>
    </div>
  );
}
