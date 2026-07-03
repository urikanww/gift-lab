import { Suspense, lazy, useEffect, useMemo, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import {
  Badge,
  Button,
  Card,
  EmptyState,
  LinkButton,
  Skeleton,
  Spinner,
  cn,
  useOptionalToast,
} from '../ui';
import { ErrorState } from '../components/ui/States';
import { CardImage, ProductCard } from '../components/product/ProductCard';

// three.js is heavy — load the viewer only on MODEL_3D pages that have a file.
const ModelViewer = lazy(() => import('../components/ModelViewer'));
import { Motion, fadeInUp, staggerContainer, staggerItem } from '../motion';
import {
  designPath,
  fetchCatalogue,
  fetchProduct,
  fetchTierPrices,
  productPath,
  type TierPrice,
} from '../lib/catalogue';
import { categoryLabel } from '../lib/categories';
import { useCartStore } from '../stores/cartStore';
import type { PrintMethod, Product, Variant } from '../types';

const TIER_QUANTITIES = [25, 100, 250, 500];

const PRINT_METHOD_LABELS: Record<PrintMethod, string> = {
  UV: 'UV print',
  FDM: 'FDM 3D print',
  RESIN: 'Resin 3D print',
};

const TRUST = [
  { icon: '⚡', label: '3-day turnaround' },
  { icon: '🔒', label: 'Secure checkout' },
  { icon: '🏢', label: 'Bulk & corporate' },
];

// Presentational reviews — no backend. Kept static so the PDP reads as a real
// storefront without inventing data-fetching for content that doesn't exist.
const REVIEWS = [
  { name: 'Priya S.', rating: 5, body: 'Beautiful finish and the proof turnaround was fast. Ordered 200 for our launch.' },
  { name: 'Marcus L.', rating: 5, body: 'The live preview made customising painless. Exactly what we saw got delivered.' },
  { name: 'Wei Tan', rating: 4, body: 'Great quality for the price. Would have loved more colour options.' },
];
const RATING_AVG = 4.8;
const RATING_COUNT = 128;

/** Static star row. Renders `value` (out of 5) rounded to the nearest whole star. */
function Stars({ value, className }: { value: number; className?: string }) {
  return (
    <span className={cn('inline-flex text-warning', className)} aria-hidden="true">
      {Array.from({ length: 5 }).map((_, i) => (
        <span key={i} className={i < Math.round(value) ? 'text-warning' : 'text-fg-subtle/40'}>
          ★
        </span>
      ))}
    </span>
  );
}

export default function ProductDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { toast } = useOptionalToast();
  const addLine = useCartStore((s) => s.addLine);

  const [product, setProduct] = useState<Product | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [reloadKey, setReloadKey] = useState(0);

  const [selectedVariantId, setSelectedVariantId] = useState<number | null>(null);
  const [selectedTierQty, setSelectedTierQty] = useState<number | null>(null);

  const [related, setRelated] = useState<Product[]>([]);

  const [tiers, setTiers] = useState<TierPrice[]>([]);
  const [tiersLoading, setTiersLoading] = useState(false);

  // ── Load the product (with unmount/stale guard). ──────────────────────────
  useEffect(() => {
    let active = true;
    setLoading(true);
    setError(null);
    setProduct(null);
    // Per-product UI state must not leak across same-route navigation
    // (related-product clicks reuse this component instance).
    setSelectedTierQty(null);
    fetchProduct(id ?? '')
      .then((p) => {
        if (!active) return;
        setProduct(p);
        setSelectedVariantId(p.variants?.[0]?.id ?? null);
      })
      .catch(() => {
        if (!active) return;
        setError('We could not load this product right now.');
      })
      .finally(() => {
        if (active) setLoading(false);
      });
    return () => {
      active = false;
    };
  }, [id, reloadKey]);

  // ── Related products (best-effort; failure is non-fatal). ─────────────────
  useEffect(() => {
    if (!product) return;
    let active = true;
    fetchCatalogue({})
      .then((res) => {
        if (!active) return;
        setRelated(res.data.filter((p) => p.id !== product.id).slice(0, 3));
      })
      .catch(() => {
        if (active) setRelated([]);
      });
    return () => {
      active = false;
    };
  }, [product?.id]);

  // ── Tier pricing — re-fetch when product or selected variant changes. ─────
  useEffect(() => {
    if (!product) return;
    let active = true;
    setTiersLoading(true);
    fetchTierPrices(product.id, selectedVariantId, TIER_QUANTITIES)
      .then((res) => {
        if (!active) return;
        setTiers(res);
      })
      .catch(() => {
        if (active) setTiers([]);
      })
      .finally(() => {
        if (active) setTiersLoading(false);
      });
    return () => {
      active = false;
    };
  }, [product, selectedVariantId]);

  const selectedVariant: Variant | null = useMemo(
    () => product?.variants?.find((v) => v.id === selectedVariantId) ?? null,
    [product, selectedVariantId],
  );

  // Distinct colour values across variants (attributes.color), if present.
  const colorOptions = useMemo(() => {
    if (!product?.variants?.length) return [] as { value: string; variantId: number }[];
    const seen = new Map<string, number>();
    for (const v of product.variants) {
      const color = v.attributes.color ?? v.attributes.colour;
      if (color && !seen.has(color)) seen.set(color, v.id);
    }
    return Array.from(seen, ([value, variantId]) => ({ value, variantId }));
  }, [product]);

  if (loading) {
    return (
      <div className="grid gap-8 md:grid-cols-2 md:gap-12">
        <span className="sr-only" role="status" aria-live="polite">
          Loading product…
        </span>
        <Skeleton className="aspect-[4/3] w-full rounded-xl" />
        <div className="flex flex-col gap-4">
          <Skeleton height={16} width="40%" />
          <Skeleton height={32} width="80%" />
          <Skeleton height={20} width="30%" />
          <Skeleton height={80} width="100%" />
          <Skeleton height={44} width="60%" />
        </div>
      </div>
    );
  }

  if (error) {
    return <ErrorState message={error} onRetry={() => setReloadKey((k) => k + 1)} />;
  }

  if (!product) {
    return (
      <EmptyState
        title="Product not found"
        description="This product may have been unpublished or the link is incorrect."
        action={
          <LinkButton to="/products" variant="outline">
            Back to products
          </LinkButton>
        }
      />
    );
  }

  const currency = product.currency;

  const handleAddSample = () => {
    addLine(product, selectedVariant, {});
    toast({
      title: 'Sample added to cart',
      description: product.name,
      tone: 'success',
    });
  };

  return (
    <div className="flex flex-col gap-10 pb-24 md:pb-0">
      {/* ── Two-column: gallery + info ────────────────────────────────────── */}
      <div className="grid items-start gap-8 md:grid-cols-2 md:gap-12">
        {/* LEFT — gallery (sticky only at md+). */}
        <div className="self-start md:sticky md:top-20">
          <Motion variants={fadeInUp} initial="hidden" animate="visible" className="flex flex-col gap-4">
            {/* Interactive 3D model when we hold the file; static image otherwise */}
            {product.has_model && (
              <Suspense fallback={<div className="h-[360px] w-full animate-pulse rounded-lg border border-border bg-surface-2" />}>
                <ModelViewer productKey={product.slug ?? String(product.id)} />
              </Suspense>
            )}
            <div className="group relative aspect-[4/3] w-full overflow-hidden rounded-xl border border-border bg-surface-2">
              <CardImage product={product} />
              {product.class === 'MODEL_3D' && !product.has_model && (
                <div className="absolute left-3 top-3">
                  <Badge tone="success" size="sm" dot>
                    3D preview available
                  </Badge>
                </div>
              )}
            </div>
            {/* Thumbnail strip — decorative placeholders reusing the same image. */}
            <div className="grid grid-cols-4 gap-3" aria-hidden="true">
              {Array.from({ length: 4 }).map((_, i) => (
                <div
                  key={i}
                  className={cn(
                    'aspect-square overflow-hidden rounded-lg border bg-surface-2',
                    i === 0 ? 'border-primary' : 'border-border',
                  )}
                >
                  <CardImage product={product} />
                </div>
              ))}
            </div>
          </Motion>
        </div>

        {/* RIGHT — info column. */}
        <Motion
          variants={staggerContainer}
          initial="hidden"
          animate="visible"
          className="flex flex-col gap-6"
        >
          {/* Breadcrumb */}
          <Motion variants={staggerItem}>
            <nav aria-label="Breadcrumb" className="text-sm text-fg-muted">
              <ol className="flex flex-wrap items-center gap-1.5">
                <li>
                  <Link
                    to="/products"
                    className="hover:text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-bg"
                  >
                    Products
                  </Link>
                </li>
                <li aria-hidden="true">/</li>
                <li>
                  <Link
                    to={`/products?category=${product.category ?? ''}`}
                    className="hover:text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-bg"
                  >
                    {categoryLabel(product.category)}
                  </Link>
                </li>
                <li aria-hidden="true">/</li>
                <li className="text-fg" aria-current="page">
                  {product.name}
                </li>
              </ol>
            </nav>
          </Motion>

          <Motion variants={staggerItem} className="flex flex-col gap-3">
            <h1 className="font-display text-3xl leading-tight text-fg sm:text-4xl">{product.name}</h1>

            {/* Presentational rating summary */}
            <div className="flex items-center gap-2 text-sm">
              <Stars value={RATING_AVG} />
              <span className="font-medium text-fg">{RATING_AVG.toFixed(1)}</span>
              <span className="text-fg-muted">({RATING_COUNT} reviews)</span>
            </div>

            {/* Price */}
            <p className="flex items-baseline gap-2">
              <span className="text-2xs uppercase tracking-wide text-fg-subtle">from</span>
              <span className="font-display text-2xl font-bold text-fg">
                {currency} {product.from_price.toFixed(2)}
              </span>
            </p>

            {product.description && <p className="text-fg-muted">{product.description}</p>}
            {product.creator_credit && (
              <p className="text-sm text-fg-subtle">Design by {product.creator_credit}</p>
            )}
          </Motion>

          {/* Colour swatches */}
          {colorOptions.length > 0 && (
            <Motion variants={staggerItem} className="flex flex-col gap-2">
              <span className="text-sm font-medium text-fg">Colour</span>
              <div className="flex flex-wrap gap-2">
                {colorOptions.map((c) => {
                  const active = selectedVariant
                    ? (selectedVariant.attributes.color ?? selectedVariant.attributes.colour) === c.value
                    : false;
                  return (
                    <button
                      key={c.value}
                      type="button"
                      onClick={() => setSelectedVariantId(c.variantId)}
                      aria-pressed={active}
                      className={cn(
                        'rounded-full border px-3 py-1.5 text-sm capitalize transition-colors duration-fast focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-bg',
                        active
                          ? 'border-primary bg-primary/10 text-primary'
                          : 'border-border bg-surface text-fg-muted hover:border-primary/50 hover:text-fg',
                      )}
                    >
                      {c.value}
                    </button>
                  );
                })}
              </div>
            </Motion>
          )}

          {/* Quantity tier pricing */}
          <Motion variants={staggerItem} className="flex flex-col gap-2">
            <span className="text-sm font-medium text-fg">Volume pricing</span>
            {tiersLoading ? (
              <div className="flex items-center gap-2 text-sm text-fg-muted">
                <Spinner size="sm" />
                <span>Calculating price breaks…</span>
              </div>
            ) : tiers.length === 0 ? (
              <p className="text-sm text-fg-muted">Volume pricing is unavailable right now.</p>
            ) : (
              <div className="flex flex-wrap gap-2">
                {tiers.map((t) => {
                  const active = selectedTierQty === t.qty;
                  return (
                    <button
                      key={t.qty}
                      type="button"
                      onClick={() => setSelectedTierQty(t.qty)}
                      aria-pressed={active}
                      className={cn(
                        'flex min-w-[6rem] flex-1 flex-col items-start rounded-lg border px-3 py-2 text-left transition-colors duration-fast focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-bg',
                        active
                          ? 'border-primary bg-primary/10'
                          : 'border-border bg-surface hover:border-primary/50',
                      )}
                    >
                      <span className="text-sm font-semibold text-fg">{t.qty} pcs</span>
                      <span className="text-xs text-fg-muted">
                        {t.currency} {t.unitPrice.toFixed(2)} <span className="text-fg-subtle">/ unit</span>
                      </span>
                    </button>
                  );
                })}
              </div>
            )}
          </Motion>

          {/* CTAs */}
          <Motion variants={staggerItem} className="flex flex-col gap-3 sm:flex-row">
            <LinkButton
              to={designPath(product)}
              variant="primary"
              size="lg"
              className="w-full sm:w-auto"
            >
              Customize in studio
            </LinkButton>
            <Button
              variant="outline"
              size="lg"
              onClick={handleAddSample}
              className="w-full sm:w-auto"
            >
              Add sample to cart
            </Button>
          </Motion>

          {/* Sample = one physical unit, priced at the single-unit rate. Spell it
              out so the higher-than-"from" price isn't a surprise at checkout. */}
          <Motion variants={staggerItem}>
            <p className="text-xs text-fg-subtle">
              A sample is one unit at single-unit pricing. Volume discounts apply
              to full orders — see the price breaks above.
            </p>
          </Motion>

          {/* Trust mini-row */}
          <Motion variants={staggerItem}>
            <ul className="flex flex-wrap gap-x-5 gap-y-2 text-sm text-fg-muted">
              {TRUST.map((t) => (
                <li key={t.label} className="flex items-center gap-2">
                  <span aria-hidden="true">{t.icon}</span>
                  {t.label}
                </li>
              ))}
            </ul>
          </Motion>
        </Motion>
      </div>

      {/* ── Specifications ────────────────────────────────────────────────── */}
      <section aria-labelledby="pdp-specs" className="flex flex-col gap-4">
        <h2 id="pdp-specs" className="font-display text-2xl text-fg">
          Specifications
        </h2>
        <Card padding="md">
          <dl className="grid grid-cols-1 gap-x-8 gap-y-3 sm:grid-cols-2">
            <SpecRow label="Category" value={categoryLabel(product.category)} />
            {product.print_method && (
              <SpecRow label="Print method" value={PRINT_METHOD_LABELS[product.print_method]} />
            )}
            {product.dimensions &&
              Object.entries(product.dimensions).map(([k, v]) => (
                <SpecRow key={k} label={`Dimension (${k})`} value={String(v)} />
              ))}
            {product.weight && <SpecRow label="Weight" value={`${product.weight} g`} />}
            <SpecRow label="Stock" value={product.stock_mode} />
          </dl>
        </Card>
      </section>

      {/* ── Reviews (presentational) ──────────────────────────────────────── */}
      <section aria-labelledby="pdp-reviews" className="flex flex-col gap-4">
        <div className="flex flex-wrap items-center gap-3">
          <h2 id="pdp-reviews" className="font-display text-2xl text-fg">
            Reviews
          </h2>
          <div className="flex items-center gap-2 text-sm text-fg-muted">
            <Stars value={RATING_AVG} />
            <span>
              {RATING_AVG.toFixed(1)} · {RATING_COUNT} reviews
            </span>
          </div>
        </div>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          {REVIEWS.map((r) => (
            <Card key={r.name} padding="md" className="flex flex-col gap-2">
              <Stars value={r.rating} />
              <p className="text-sm text-fg-muted">{r.body}</p>
              <p className="mt-auto pt-2 text-xs font-medium text-fg">{r.name}</p>
            </Card>
          ))}
        </div>
      </section>

      {/* ── Related products ──────────────────────────────────────────────── */}
      {related.length > 0 && (
        <section aria-labelledby="pdp-related" className="flex flex-col gap-4">
          <h2 id="pdp-related" className="font-display text-2xl text-fg">
            You might also like
          </h2>
          <Motion
            variants={staggerContainer}
            initial="hidden"
            animate="visible"
            className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3"
          >
            {related.map((p) => (
              <ProductCard key={p.id} product={p} to={productPath(p)} showMeta />
            ))}
          </Motion>
        </section>
      )}

      {/* Mobile sticky action bar — the in-flow CTAs sit far below the fold on a
          phone, so mirror them in a fixed bar. Hidden at md+ where CTAs are visible. */}
      <div className="fixed inset-x-0 bottom-0 z-raised flex gap-2 border-t border-border bg-surface/95 p-3 backdrop-blur-md md:hidden">
        <LinkButton to={designPath(product)} variant="primary" size="md" className="flex-1">
          Customize
        </LinkButton>
        <Button variant="outline" size="md" onClick={handleAddSample} className="flex-1">
          Add sample
        </Button>
      </div>
    </div>
  );
}

function SpecRow({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex justify-between gap-4 border-b border-border/60 py-1.5 last:border-0">
      <dt className="text-sm text-fg-muted">{label}</dt>
      <dd className="text-sm font-medium text-fg">{value}</dd>
    </div>
  );
}
