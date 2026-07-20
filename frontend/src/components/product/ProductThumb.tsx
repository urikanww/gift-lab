import { useState } from 'react';
import { safeHref } from '../../lib/safeHref';
import { cn } from '../../ui';

/**
 * Square product photo with a letter fallback.
 *
 * Shared by the cart and the order detail page so a buyer sees the same image
 * for a line before and after the quote is raised. `product` is optional
 * because a line item's product relation is only present when the endpoint
 * eager-loaded it - the caller should not have to guard.
 */
export default function ProductThumb({
  product,
  className,
}: {
  product?: { name: string; image_url?: string | null } | null;
  className?: string;
}) {
  const [failed, setFailed] = useState(false);
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

  return (
    <img
      src={href}
      alt=""
      className={cn(box, 'border border-border object-cover')}
      loading="lazy"
      referrerPolicy="no-referrer"
      onError={() => setFailed(true)}
    />
  );
}
