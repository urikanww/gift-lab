import type { ReactNode } from 'react';
import { cn } from './cn';

export interface EmptyStateProps {
  /** Optional decorative icon/illustration. */
  icon?: ReactNode;
  title: string;
  description?: ReactNode;
  /** Primary call-to-action, e.g. a <Button>. */
  action?: ReactNode;
  className?: string;
}

/**
 * Centered empty/zero state. Use whenever a collection resolves to nothing so
 * screens never render a bare blank area.
 */
export function EmptyState({ icon, title, description, action, className }: EmptyStateProps) {
  return (
    <div
      className={cn(
        'flex flex-col items-center justify-center gap-3 rounded-lg border border-dashed border-border ' +
          'bg-surface/50 px-6 py-16 text-center',
        className,
      )}
    >
      {icon && <div className="text-fg-subtle [&_svg]:h-10 [&_svg]:w-10">{icon}</div>}
      <h3 className="font-display text-xl text-fg">{title}</h3>
      {description && <p className="max-w-sm text-sm text-fg-muted">{description}</p>}
      {action && <div className="mt-2">{action}</div>}
    </div>
  );
}
