import { useState, useEffect } from 'react';
import { Button, cn } from '../ui';

interface Props {
  value: number;
  min?: number;
  max?: number;
  step?: number;
  onChange: (qty: number) => void;
  className?: string;
}

/**
 * Adjustable quantity control clamped to a minimum order quantity. Typing is
 * free while focused; the value is clamped to [min, max] on blur/step so an
 * in-progress edit isn't fought, but the committed value is always valid.
 */
export default function QuantityStepper({ value, min = 1, max = 100000, step = 1, onChange, className }: Props) {
  const [draft, setDraft] = useState(String(value));
  useEffect(() => setDraft(String(value)), [value]);

  const clamp = (n: number) => Math.min(max, Math.max(min, Math.floor(n)));
  const commit = (raw: string) => {
    const n = Number(raw);
    const next = Number.isFinite(n) ? clamp(n) : min;
    setDraft(String(next));
    onChange(next);
  };

  return (
    <div className={cn('flex items-center gap-1', className)}>
      <Button variant="outline" size="sm" aria-label="Decrease" onClick={() => onChange(clamp(value - step))}>
        −
      </Button>
      <input
        type="number"
        inputMode="numeric"
        aria-label="Quantity"
        min={min}
        max={max}
        value={draft}
        onChange={(e) => setDraft(e.target.value)}
        onBlur={(e) => commit(e.target.value)}
        className="w-16 rounded-md border border-border bg-surface px-2 py-1.5 text-center text-sm text-fg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
      />
      <Button variant="outline" size="sm" aria-label="Increase" onClick={() => onChange(clamp(value + step))}>
        +
      </Button>
    </div>
  );
}
