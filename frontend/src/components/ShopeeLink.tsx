import type { AnchorHTMLAttributes, ReactNode } from 'react';

/** Shopee brand orange (official primary). */
export const SHOPEE_ORANGE = '#EE4D2D';

/** Simplified Shopee shopping-bag glyph (not the trademarked wordmark). */
export function ShopeeBagIcon({ className = 'h-4 w-4' }: { className?: string }) {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className={className} aria-hidden="true">
      <path d="M6 8h12l-.8 11.2a1.5 1.5 0 0 1-1.5 1.3H8.3a1.5 1.5 0 0 1-1.5-1.3L6 8Z" strokeLinejoin="round" />
      <path d="M9 8V6.5a3 3 0 0 1 6 0V8" strokeLinecap="round" />
    </svg>
  );
}

interface ShopeeLinkProps extends AnchorHTMLAttributes<HTMLAnchorElement> {
  children?: ReactNode;
}

/**
 * Shopee-branded external link (official orange + bag icon). Opens a new tab.
 * Callers set `rel`: affiliate offer links use "sponsored nofollow noopener
 * noreferrer"; plain reference links (staff procurement) use "noopener
 * noreferrer" — NEVER an affiliate link for our own use (self-referral rule).
 */
export function ShopeeLink({ children = 'Shopee', className = '', style, target = '_blank', ...rest }: ShopeeLinkProps) {
  return (
    <a
      {...rest}
      target={target}
      style={{ backgroundColor: SHOPEE_ORANGE, ...style }}
      className={
        'inline-flex items-center justify-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-semibold ' +
        'text-white shadow-xs transition hover:brightness-95 focus-visible:outline-none focus-visible:ring-2 ' +
        'focus-visible:ring-offset-1 ' +
        className
      }
    >
      <ShopeeBagIcon />
      {children}
    </a>
  );
}
