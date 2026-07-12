import type { ReactNode } from 'react';
import { Button } from '../../ui';

export interface FilterChip {
  key: string;
  label: string;
}

/**
 * Numeric pill that reads as PART of its parent button (the "new chat badge"
 * look). Shared by both admin filter toolbars.
 */
export function CountPill({ children }: { children: ReactNode }) {
  return (
    <span className="ml-1.5 inline-flex min-w-[1.25rem] items-center justify-center rounded-full bg-primary px-1.5 py-0.5 text-2xs font-semibold leading-none text-primary-fg">
      {children}
    </span>
  );
}

/**
 * Removable active-filter chips + a Clear all. Renders nothing when empty.
 * Pure presentational — the parent owns filter state (URL params).
 */
export function FilterChips({
  chips,
  onRemove,
  onClear,
}: {
  chips: FilterChip[];
  onRemove: (key: string) => void;
  onClear: () => void;
}) {
  if (chips.length === 0) return null;
  return (
    <div className="flex flex-wrap items-center gap-2">
      {chips.map((chip) => (
        <button
          key={chip.key}
          type="button"
          onClick={() => onRemove(chip.key)}
          className="inline-flex items-center gap-1.5 rounded-full border border-border bg-surface px-3 py-1 text-xs font-medium text-fg transition-colors hover:border-danger hover:text-danger"
          aria-label={`Remove filter: ${chip.label}`}
        >
          {chip.label}
          <span aria-hidden="true">✕</span>
        </button>
      ))}
      <Button variant="ghost" size="sm" onClick={onClear}>
        Clear all
      </Button>
    </div>
  );
}
