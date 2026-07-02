import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Badge, Button, EmptyState, LinkButton } from '../ui';
import { ErrorState } from '../components/ui/States';
import { CardSkeleton, ProductCard } from '../components/product/ProductCard';
import { Motion, fadeInUp, staggerContainer } from '../motion';
import { fetchCatalogue, productPath } from '../lib/catalogue';
import { CATEGORIES } from '../lib/categories';
import type { Product } from '../types';

const MAX_POPULAR = 8;

const STEPS = [
  {
    n: '1',
    title: 'Pick a product',
    body: 'Browse the boutique — core essentials, UV-printed pieces and 3D-crafted gifts.',
  },
  {
    n: '2',
    title: 'Customize in the studio',
    body: 'Add your logo and text with a live 2D + 3D preview before you commit.',
  },
  {
    n: '3',
    title: 'Checkout & get it made',
    body: 'Request a quote or pay now — we handle proofing and production end-to-end.',
  },
];

const TRUST = [
  { icon: '⚡', label: '3-day turnaround' },
  { icon: '🎨', label: 'Live 2D + 3D preview' },
  { icon: '🔒', label: 'Secure checkout' },
  { icon: '🏢', label: 'Bulk & corporate' },
];

export default function HomePage() {
  const [products, setProducts] = useState<Product[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = async (isActive: () => boolean = () => true) => {
    setLoading(true);
    setError(null);
    try {
      const result = await fetchCatalogue({});
      if (!isActive()) return;
      setProducts(result.data.slice(0, MAX_POPULAR));
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
  }, []);

  return (
    <div className="flex flex-col gap-16 sm:gap-20">
      {/* ── Hero ─────────────────────────────────────────────── */}
      <Motion
        variants={fadeInUp}
        initial="hidden"
        animate="visible"
        className="relative overflow-hidden rounded-2xl border border-border bg-gradient-to-br from-brand-50 via-surface to-accent-50 px-6 py-14 sm:px-10 sm:py-20"
      >
        <div
          className="pointer-events-none absolute -right-16 -top-16 h-64 w-64 rounded-full bg-brand-100/50 blur-3xl"
          aria-hidden="true"
        />
        <div
          className="pointer-events-none absolute -bottom-20 -left-10 h-56 w-56 rounded-full bg-accent-100/40 blur-3xl"
          aria-hidden="true"
        />

        <div className="relative flex flex-col items-start gap-6 lg:flex-row lg:items-center lg:justify-between">
          <div className="max-w-2xl">
            <Badge tone="brand" size="sm" dot>
              Custom gifting studio
            </Badge>
            <h1 className="mt-4 font-display text-4xl font-bold leading-tight text-fg sm:text-5xl lg:text-6xl">
              Design gifts worth
              <br className="hidden sm:block" /> remembering.
            </h1>
            <p className="mt-4 max-w-xl text-base text-fg-muted sm:text-lg">
              Personalise premium products with a live 2D + 3D preview, then order in minutes —
              no account needed until checkout.
            </p>
            <div className="mt-8 flex flex-col gap-3 sm:flex-row">
              <LinkButton to="/products" variant="primary" size="lg">
                Open the studio
              </LinkButton>
              <LinkButton to="/products" variant="outline" size="lg">
                Browse products
              </LinkButton>
            </div>
          </div>

          {/* Decorative floating preview chips. */}
          <div className="hidden shrink-0 flex-col gap-3 lg:flex" aria-hidden="true">
            {TRUST.map((t) => (
              <div
                key={t.label}
                className="flex items-center gap-2 rounded-full border border-border bg-surface/80 px-4 py-2 text-sm text-fg-muted shadow-card backdrop-blur"
              >
                <span className="text-base">{t.icon}</span>
                {t.label}
              </div>
            ))}
          </div>
        </div>
      </Motion>

      {/* ── Shop by category ─────────────────────────────────── */}
      <section aria-labelledby="home-categories">
        <h2 id="home-categories" className="font-display text-2xl text-fg sm:text-3xl">
          Shop by category
        </h2>
        <div className="mt-6 grid grid-cols-2 gap-4 md:grid-cols-3">
          {CATEGORIES.map((c) => (
            <Link
              key={c.key}
              to={`/products?class=${c.key}`}
              className="group flex flex-col items-start gap-3 rounded-xl border border-border bg-surface p-6 shadow-card transition-all duration-base ease-standard hover:-translate-y-0.5 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-bg motion-reduce:hover:translate-y-0"
            >
              <span className="text-4xl" aria-hidden="true">
                {c.icon}
              </span>
              <span className="font-display text-lg text-fg transition-colors duration-fast group-hover:text-primary">
                {c.label}
              </span>
            </Link>
          ))}
        </div>
      </section>

      {/* ── Popular products ─────────────────────────────────── */}
      <section aria-labelledby="home-popular">
        <div className="flex items-end justify-between gap-4">
          <h2 id="home-popular" className="font-display text-2xl text-fg sm:text-3xl">
            Popular products
          </h2>
          <Link
            to="/products"
            className="text-sm font-medium text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-bg"
          >
            View all
          </Link>
        </div>

        <div className="mt-6">
          {loading ? (
            <>
              <span className="sr-only" role="status" aria-live="polite">
                Loading products…
              </span>
              <div
                className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4"
                aria-hidden="true"
              >
                {Array.from({ length: 4 }).map((_, i) => (
                  <CardSkeleton key={i} />
                ))}
              </div>
            </>
          ) : error ? (
            <ErrorState message={error} onRetry={() => void load()} />
          ) : products.length === 0 ? (
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
              className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4"
            >
              {products.map((p) => (
                <ProductCard key={p.id} product={p} to={productPath(p)} />
              ))}
            </Motion>
          )}
        </div>
      </section>

      {/* ── How it works ─────────────────────────────────────── */}
      <section aria-labelledby="home-how">
        <h2 id="home-how" className="font-display text-2xl text-fg sm:text-3xl">
          How it works
        </h2>
        <div className="mt-6 grid grid-cols-1 gap-5 sm:grid-cols-3">
          {STEPS.map((s) => (
            <div
              key={s.n}
              className="flex flex-col gap-3 rounded-xl border border-border bg-surface p-6 shadow-card"
            >
              <span className="flex h-10 w-10 items-center justify-center rounded-full bg-primary font-display text-lg text-primary-fg">
                {s.n}
              </span>
              <h3 className="font-display text-lg text-fg">{s.title}</h3>
              <p className="text-sm text-fg-muted">{s.body}</p>
            </div>
          ))}
        </div>
      </section>

      {/* ── Trust bar ────────────────────────────────────────── */}
      <section
        aria-label="Why choose us"
        className="grid grid-cols-2 gap-4 rounded-2xl border border-border bg-surface-2 p-6 md:grid-cols-4"
      >
        {TRUST.map((t) => (
          <div key={t.label} className="flex items-center gap-3">
            <span className="text-2xl" aria-hidden="true">
              {t.icon}
            </span>
            <span className="text-sm font-medium text-fg">{t.label}</span>
          </div>
        ))}
      </section>
    </div>
  );
}
