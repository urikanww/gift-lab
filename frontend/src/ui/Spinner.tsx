import { cn } from './cn';

export type SpinnerSize = 'sm' | 'md' | 'lg';

const sizeMap: Record<SpinnerSize, string> = {
  sm: 'h-4 w-4 border-2',
  md: 'h-5 w-5 border-2',
  lg: 'h-7 w-7 border-[3px]',
};

export interface SpinnerProps {
  size?: SpinnerSize;
  className?: string;
  /** Accessible label; set to a string for standalone spinners. */
  label?: string;
}

/**
 * Pure-CSS spinner (animation defined in index.css, honors reduced motion via
 * the global media query). Add a `label` when used standalone so screen readers
 * announce a busy state.
 */
export function Spinner({ size = 'md', className, label }: SpinnerProps) {
  return (
    <span
      className={cn(
        'inline-block animate-spin rounded-full border-current border-t-transparent align-[-0.125em]',
        sizeMap[size],
        className,
      )}
      role={label ? 'status' : undefined}
      aria-label={label}
      aria-hidden={label ? undefined : true}
    >
      {label && <span className="sr-only">{label}</span>}
    </span>
  );
}
