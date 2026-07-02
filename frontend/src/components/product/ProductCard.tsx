import { useState } from 'react';
import { Link } from 'react-router-dom';
import { safeHref } from '../../lib/safeHref';
import { designPath } from '../../lib/catalogue';
import { categoryLabel } from '../../lib/categories';
import { Badge, Skeleton } from '../../ui';
import { Motion, staggerItem } from '../../motion';
import type { Product } from '../../types';

/**
 * Scraped image URLs are external and untrusted: route through safeHref (drops
 * javascript:/data: etc.), fall back to a monogram placeholder on load error,
 * and suppress the referrer on the outbound request.
 */
export function CardImage({ product }: { product: Product }) {
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

export function CardSkeleton() {
  return (
    <div className="overflow-hidden rounded-lg border border-border bg-surface shadow-card">
      <Skeleton className="aspect-square w-full rounded-none" />
      <div className="flex flex-col gap-2 p-3">
        <Skeleton height={18} width="70%" />
        <Skeleton height={14} width="40%" />
      </div>
    </div>
  );
}

export interface ProductCardProps {
  product: Product;
  /** Destination route for the card, e.g. `/products/${id}`. */
  to: string;
  /** Show the class badge + creator credit (catalogue-style meta). */
  showMeta?: boolean;
}

export function ProductCard({ product, to, showMeta = false }: ProductCardProps) {
  return (
    <Motion variants={staggerItem} className="h-full">
      {/* Quick-action link is a SIBLING of the card link (never nested <a>). */}
      <div className="group relative h-full">
        <Link
          to={to}
          className="flex h-full flex-col overflow-hidden rounded-lg border border-border bg-surface shadow-card transition-shadow duration-base ease-standard hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-bg"
        >
          <div className="relative aspect-square w-full overflow-hidden bg-surface-2">
            <CardImage product={product} />
            {showMeta && product.category && (
              <div className="absolute left-2 top-2">
                <Badge tone="brand" size="sm">
                  {categoryLabel(product.category)}
                </Badge>
              </div>
            )}
          </div>
          <div className="flex flex-1 flex-col gap-0.5 p-3">
            <h3 className="line-clamp-2 text-sm font-medium leading-snug text-fg transition-colors duration-fast group-hover:text-primary">
              {product.name}
            </h3>
            {showMeta && product.creator_credit && (
              <p className="text-xs text-fg-subtle">by {product.creator_credit}</p>
            )}
            <p className="mt-auto pt-1.5 text-sm">
              <span className="text-2xs uppercase tracking-wide text-fg-subtle">from </span>
              <span className="font-semibold text-fg">
                {product.currency} {product.from_price.toFixed(2)}
              </span>
            </p>
          </div>
        </Link>
        <Link
          to={designPath(product)}
          aria-label={`Personalize ${product.name}`}
          className="absolute inset-x-2 bottom-2 z-raised translate-y-1 rounded-md bg-primary/95 px-3 py-1.5 text-center text-xs font-semibold text-primary-fg opacity-0 shadow-md transition-all duration-base group-hover:translate-y-0 group-hover:opacity-100 focus-visible:translate-y-0 focus-visible:opacity-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring motion-reduce:transition-none motion-reduce:group-hover:translate-y-0"
        >
          🎨 Personalize now
        </Link>
      </div>
    </Motion>
  );
}
