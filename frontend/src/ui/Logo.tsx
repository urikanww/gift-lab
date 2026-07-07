import { cn } from './cn';

/**
 * GiftLab brand mark: a lab flask (the "lab" — made-to-order production) topped
 * with a gift bow (the "gift"). The flask outline inherits the surrounding text
 * colour via `currentColor`; the liquid + bow use the brand primary token so the
 * mark re-tints correctly in light/dark themes.
 */
export function LogoMark({ className }: { className?: string }) {
  return (
    <svg
      viewBox="0 0 48 48"
      className={className}
      fill="none"
      role="img"
      aria-hidden="true"
      focusable="false"
    >
      {/* bow */}
      <path d="M24 5.5l-4 -2.5v5z" fill="rgb(var(--color-primary))" />
      <path d="M24 5.5l4 -2.5v5z" fill="rgb(var(--color-primary))" />
      <circle cx="24" cy="5.8" r="1.7" fill="rgb(var(--color-primary))" />
      {/* neck band */}
      <path d="M19 9h10" stroke="currentColor" strokeWidth="2.6" strokeLinecap="round" />
      {/* flask body outline */}
      <path
        d="M20 9v8l-8.2 17a4 4 0 0 0 3.6 5.8h17.2a4 4 0 0 0 3.6 -5.8l-8.2 -17V9"
        stroke="currentColor"
        strokeWidth="2.6"
        strokeLinejoin="round"
      />
      {/* liquid */}
      <path
        d="M15.4 28h17.2l2.6 5.4a4 4 0 0 1 -3.6 5.8H16.4a4 4 0 0 1 -3.6 -5.8z"
        fill="rgb(var(--color-primary))"
      />
    </svg>
  );
}

/**
 * Full lockup: flask mark + "GiftLab" wordmark (Fraunces display, "Lab" in the
 * brand primary). Callers wrap this in a Link when it should navigate home.
 */
export function Logo({
  className,
  markClassName,
  wordmark = true,
}: {
  className?: string;
  markClassName?: string;
  wordmark?: boolean;
}) {
  return (
    <span className={cn('inline-flex items-center gap-2', className)}>
      <LogoMark className={cn('h-7 w-7 shrink-0 text-fg', markClassName)} />
      {wordmark && (
        <span className="font-display text-xl font-semibold tracking-tight text-fg">
          Gift<span className="text-primary">Lab</span>
        </span>
      )}
    </span>
  );
}
