import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Badge, Button, EmptyState, Skeleton } from '../ui';
import { ErrorState } from '../components/ui/States';
import { Motion, fadeInUp, staggerContainer, staggerItem } from '../motion';
import { fetchCatalogue } from '../lib/catalogue';
import { CATEGORIES } from '../lib/categories';
import { safeHref } from '../lib/safeHref';
import type { Product } from '../types';

const MAX_POPULAR = 8;

/** Shared button-equivalent styling so react-router <Link>s read as CTAs. */
const ctaBase =
  'inline-flex items-center justify-center gap-2 h-12 px-6 text-lg font-medium select-none whitespace-nowrap ' +
  'rounded-md transition-colors duration-fast ease-standard focus-visible:outline-none focus-visible:ring-2 ' +
  'focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-bg';

function HeroCtaPrimary() {
  return (
    <Link to="/products" className={`${ctaBase} bg-primary text-primary-fg hover:bg-primary-hover shadow-xs`}>
      Open the studio
    </Link>
  );
}

function HeroCtaSecondary() {
  return (
    <Link
      to="/products"
      className={`${ctaBase} bg-surface text-fg border border-border-strong hover:border-fg-subtle`}
    >
      Browse products
    </Link>
  );
}

/** External/untrusted image → route through safeHref, monogram fallback. */
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

function ProductCard({ product }: { product: Product }) {
  return (
    <Motion variants={staggerItem} className="h-full">
      <Link
        to={`/products/${product.id}`}
        className="group flex h-full flex-col overflow-hidden rounded-lg border border-border bg-surface shadow-card transition-shadow duration-base ease-standard hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-bg"
      >
        <div className="relative aspect-[4/3] w-full overflow-hidden bg-surface-2">
          <CardImage product={product} />
        </div>
        <div className="flex flex-1 flex-col gap-1 p-4">
          <h3 className="font-display text-lg leading-snug text-fg transition-colors duration-fast group-hover:text-primary">
            {product.name}
          </h3>
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

  const load = async () => {
    setLoading(true);
    setError(null);
    try {
      const result = await fetchCatalogue(1);
      setProducts(result.data.slice(0, MAX_POPULAR));
    } catch {
      setError('We could not load products right now.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    void load();
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
              <HeroCtaPrimary />
              <HeroCtaSecondary />
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
                <ProductCard key={p.id} product={p} />
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
