import type { CSSProperties } from 'react';
import { cn } from './cn';

export interface SkeletonProps {
  className?: string;
  width?: number | string;
  height?: number | string;
  /** Shape preset. */
  variant?: 'text' | 'rect' | 'circle';
  style?: CSSProperties;
}

/**
 * Content placeholder with a subtle shimmer (reduced-motion users get a static
 * tint via the global media query). Decorative — hidden from the a11y tree; the
 * surrounding region should expose an aria-busy/status state.
 */
export function Skeleton({ className, width, height, variant = 'rect', style }: SkeletonProps) {
  return (
    <span
      aria-hidden="true"
      className={cn(
        'relative block overflow-hidden bg-surface-2',
        variant === 'text' && 'h-[1em] rounded-sm',
        variant === 'rect' && 'rounded-md',
        variant === 'circle' && 'rounded-full',
        // shimmer sweep
        'before:absolute before:inset-0 before:-translate-x-full before:animate-shimmer',
        'before:bg-gradient-to-r before:from-transparent before:via-black/[0.06] before:to-transparent',
        className,
      )}
      style={{ width, height, ...style }}
    />
  );
}

/** Convenience: N stacked text lines. */
export function SkeletonText({ lines = 3, className }: { lines?: number; className?: string }) {
  return (
    <span className={cn('flex flex-col gap-2', className)} aria-hidden="true">
      {Array.from({ length: lines }).map((_, i) => (
        <Skeleton key={i} variant="text" width={i === lines - 1 ? '70%' : '100%'} />
      ))}
    </span>
  );
}
