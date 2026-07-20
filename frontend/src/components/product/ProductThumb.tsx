import { useState } from 'react';
import ImageLightbox from '../ImageLightbox';
import { safeHref } from '../../lib/safeHref';
import { cn } from '../../ui';

/**
 * Square product photo with a letter fallback.
 *
 * Shared by the cart and the order detail page so a buyer sees the same image
 * for a line before and after the quote is raised. `product` is optional
 * because a line item's product relation is only present when the endpoint
 * eager-loaded it - the caller should not have to guard.
 *
 * `zoomable` is opt-in on purpose. The cart renders this as a plain decorative
 * thumbnail and must stay that way; only the order detail page, where a buyer
 * is checking what they actually ordered, asks for the click-to-zoom viewer.
 */
export default function ProductThumb({
  product,
  className,
  zoomable = false,
}: {
  product?: { name: string; image_url?: string | null } | null;
  className?: string;
  zoomable?: boolean;
}) {
  const [failed, setFailed] = useState(false);
  const [open, setOpen] = useState(false);
  const href = safeHref(product?.image_url);
  const box = cn('h-16 w-16 shrink-0 rounded-md', className);

  if (!product || !href || failed) {
    return (
      <div
        className={cn(
          box,
          'flex items-center justify-center bg-gradient-to-br from-brand-100 to-accent-50 font-display text-xl text-brand-700',
        )}
      >
        {product?.name.charAt(0) ?? '?'}
      </div>
    );
  }

  const img = (
    <img
      src={href}
      alt=""
      className={cn(box, 'border border-border object-cover')}
      loading="lazy"
      referrerPolicy="no-referrer"
      onError={() => setFailed(true)}
    />
  );

  // Non-interactive by default - the cart's markup is unchanged.
  if (!zoomable) return img;

  // A real button (not a click handler on the image or a wrapper div) so the
  // photo is reachable and operable by keyboard and announced with a label that
  // names the product, mirroring CustomizationPreview's design thumbnail.
  return (
    <>
      <button
        type="button"
        onClick={() => setOpen(true)}
        className={cn(
          'shrink-0 rounded-md transition-colors hover:opacity-90',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-bg',
        )}
        aria-label={`View a larger photo of ${product.name}`}
      >
        {img}
      </button>
      <ImageLightbox
        src={href}
        alt={product.name}
        open={open}
        onClose={() => setOpen(false)}
      />
    </>
  );
}
