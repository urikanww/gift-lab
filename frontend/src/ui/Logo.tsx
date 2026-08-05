import { cn } from './cn';

/**
 * GiftLab brand mark: the gift-box logo (public/logo.png). Fixed-colour raster
 * (deep cobalt box + azure heart), so it reads the same in light and dark - the
 * transparent background lets it sit on either canvas. Decorative: the wordmark
 * / link aria-label carries the accessible name, so this stays aria-hidden.
 */
export function LogoMark({ className }: { className?: string }) {
  return (
    <img
      src="/logo.png"
      alt=""
      className={className}
      aria-hidden="true"
      draggable={false}
    />
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
