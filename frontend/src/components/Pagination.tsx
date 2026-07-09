import { Button, cn } from '../ui';

/**
 * Compact numbered pager: Prev · 1 … n-1 [n] n+1 … last · Next. Lets a user jump
 * straight to a page instead of clicking Next through a deep list. Renders
 * nothing for a single page.
 */
interface Props {
  page: number;
  lastPage: number;
  onGoto: (page: number) => void;
  disabled?: boolean;
  className?: string;
}

/** Page numbers to show, with `null` marking a gap (ellipsis). */
function pageWindow(page: number, last: number): (number | null)[] {
  if (last <= 7) return Array.from({ length: last }, (_, i) => i + 1);

  const out: (number | null)[] = [1];
  const start = Math.max(2, page - 1);
  const end = Math.min(last - 1, page + 1);
  if (start > 2) out.push(null);
  for (let p = start; p <= end; p++) out.push(p);
  if (end < last - 1) out.push(null);
  out.push(last);
  return out;
}

export default function Pagination({ page, lastPage, onGoto, disabled, className }: Props) {
  if (lastPage <= 1) return null;

  return (
    <nav className={cn('flex flex-wrap items-center justify-center gap-1', className)} aria-label="Pagination">
      <Button
        variant="outline"
        size="sm"
        className="min-h-[44px]"
        disabled={disabled || page <= 1}
        onClick={() => onGoto(page - 1)}
      >
        Prev
      </Button>

      {pageWindow(page, lastPage).map((p, i) =>
        p === null ? (
          <span key={`gap-${i}`} className="px-1.5 text-fg-subtle" aria-hidden="true">
            &hellip;
          </span>
        ) : (
          <Button
            key={p}
            variant={p === page ? 'primary' : 'outline'}
            size="sm"
            className="min-h-[44px] min-w-[44px]"
            aria-current={p === page ? 'page' : undefined}
            disabled={disabled || p === page}
            onClick={() => onGoto(p)}
          >
            {p}
          </Button>
        ),
      )}

      <Button
        variant="outline"
        size="sm"
        className="min-h-[44px]"
        disabled={disabled || page >= lastPage}
        onClick={() => onGoto(page + 1)}
      >
        Next
      </Button>
    </nav>
  );
}
