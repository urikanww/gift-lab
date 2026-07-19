import { cn } from '../../ui';
import { safeHref } from '../../lib/safeHref';
import type { CartLine } from '../../types';

/** Human label for a cart line's customization (filament colour + logo size). */
export function customizationLabel(line: CartLine): string {
  const { logo_size, filament_color } = line.customization;
  const parts: string[] = [];
  if (filament_color) parts.push(`${filament_color} filament`);
  if (logo_size) parts.push(`Logo ${logo_size}`);
  return parts.length ? parts.join(' · ') : 'Blank';
}

/** Human label for a cart line's selected variant attributes. */
export function optionsLabel(line: CartLine): string {
  return line.variant ? Object.values(line.variant.attributes).join(' / ') : '-';
}

/** A single label/value row in an estimate summary. */
export function SummaryRow({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-baseline justify-between text-sm">
      <dt className="text-fg-muted">{label}</dt>
      <dd className="tabular-nums text-fg">{value}</dd>
    </div>
  );
}

/**
 * The buyer's captured design composited onto the product photo. The captured
 * artwork PNG is transparent (only the design layers - the product backdrop is
 * a DOM layer in the designer, never baked into the export), so on its own it
 * reads as "a logo floating on a checkerboard". Here we put the product photo
 * back underneath so the preview shows the product WITH their design on it -
 * the same stacked-layer trick the designer canvas uses (product `object-cover`
 * backdrop, design on top), so no cross-origin canvas export / taint is needed.
 */
export function DesignOnProduct({
  productImageUrl,
  designSrc,
  alt = '',
  className,
}: {
  productImageUrl?: string | null;
  designSrc: string;
  alt?: string;
  className?: string;
}) {
  const productHref = safeHref(productImageUrl);
  return (
    <div
      className={cn(
        'relative overflow-hidden bg-[repeating-conic-gradient(var(--color-surface-2)_0%_25%,var(--color-surface)_0%_50%)] bg-[length:12px_12px]',
        className,
      )}
    >
      {productHref && (
        <img
          src={productHref}
          alt=""
          aria-hidden="true"
          referrerPolicy="no-referrer"
          className="pointer-events-none absolute inset-0 h-full w-full object-cover"
        />
      )}
      <img
        src={designSrc}
        alt={alt}
        referrerPolicy="no-referrer"
        className="pointer-events-none absolute inset-0 h-full w-full object-contain"
      />
    </div>
  );
}

/** Decorative cart glyph for empty states. */
export function CartGlyph() {
  return (
    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
      <path
        d="M3 4h2l2.4 12.3a1 1 0 0 0 1 .7h8.7a1 1 0 0 0 1-.8L21 8H6"
        stroke="currentColor"
        strokeWidth="1.5"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
      <circle cx="9" cy="20" r="1.2" fill="currentColor" />
      <circle cx="18" cy="20" r="1.2" fill="currentColor" />
    </svg>
  );
}
