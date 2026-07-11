import { useEffect, useId, useRef, useState } from 'react';
import api from '../lib/api';
import { cn } from '../ui/cn';

export interface ProductOption {
  id: number;
  name: string;
  class?: string;
}

/**
 * Async product search combobox for the pricing test-quote (and anywhere a
 * staffer must pick one product out of thousands). Replaces a plain <Select>
 * capped at ~200 rows: types → debounced `GET /admin/products?q=…` (published
 * only) → keyboard-navigable listbox. Keyboard: ↓/↑ move, Enter selects, Esc
 * closes; clicking outside closes.
 */
export default function ProductCombobox({
  value,
  onChange,
  label = 'Product',
  publishState = 'PUBLISHED',
}: {
  value: ProductOption | null;
  onChange: (product: ProductOption) => void;
  label?: string;
  /** Restrict the search to a publish state (the quote panel prices published items). */
  publishState?: string;
}) {
  const [query, setQuery] = useState('');
  const [results, setResults] = useState<ProductOption[]>([]);
  const [open, setOpen] = useState(false);
  const [highlight, setHighlight] = useState(0);
  const [loading, setLoading] = useState(false);
  const boxRef = useRef<HTMLDivElement>(null);
  const inputId = useId();
  const listId = useId();

  // Debounced search while the menu is open. An empty query returns the first
  // page (most-recent), so opening the menu shows options before typing.
  useEffect(() => {
    if (!open) return;
    let alive = true;
    const t = setTimeout(async () => {
      setLoading(true);
      try {
        const { data } = await api.get<{ data: ProductOption[] }>('/admin/products', {
          params: { q: query || undefined, publish_state: publishState, per_page: 20 },
        });
        if (!alive) return;
        setResults(data.data);
        setHighlight(0);
      } catch {
        if (alive) setResults([]);
      } finally {
        if (alive) setLoading(false);
      }
    }, 250);
    return () => {
      alive = false;
      clearTimeout(t);
    };
  }, [query, open, publishState]);

  // Close when clicking outside the widget.
  useEffect(() => {
    const onDoc = (e: MouseEvent) => {
      if (boxRef.current && !boxRef.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', onDoc);
    return () => document.removeEventListener('mousedown', onDoc);
  }, []);

  const pick = (p: ProductOption) => {
    onChange(p);
    setOpen(false);
    setQuery('');
  };

  const onKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (!open && (e.key === 'ArrowDown' || e.key === 'Enter')) {
      setOpen(true);
      return;
    }
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      setHighlight((h) => Math.min(h + 1, results.length - 1));
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      setHighlight((h) => Math.max(h - 1, 0));
    } else if (e.key === 'Enter') {
      e.preventDefault();
      const p = results[highlight];
      if (p) pick(p);
    } else if (e.key === 'Escape') {
      setOpen(false);
    }
  };

  return (
    <div ref={boxRef} className="relative flex flex-col gap-1.5">
      <label htmlFor={inputId} className="text-sm font-medium text-fg">
        {label}
      </label>
      <input
        id={inputId}
        role="combobox"
        aria-expanded={open}
        aria-controls={listId}
        aria-autocomplete="list"
        aria-activedescendant={open && results[highlight] ? `${listId}-${results[highlight].id}` : undefined}
        autoComplete="off"
        className={cn(
          'w-full h-10 rounded-md border border-border-strong bg-surface text-fg px-3 text-base',
          'placeholder:text-fg-subtle transition-colors duration-fast ease-standard',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-bg',
        )}
        value={open ? query : (value?.name ?? '')}
        placeholder={value ? value.name : 'Search products…'}
        onFocus={() => setOpen(true)}
        onChange={(e) => {
          setQuery(e.target.value);
          setOpen(true);
        }}
        onKeyDown={onKeyDown}
      />
      {open && (
        <ul
          id={listId}
          role="listbox"
          className="absolute top-full z-20 mt-1 max-h-64 w-full overflow-auto rounded-md border border-border bg-surface py-1 shadow-lg"
        >
          {loading ? (
            <li className="px-3 py-2 text-sm text-fg-subtle">Searching…</li>
          ) : results.length === 0 ? (
            <li className="px-3 py-2 text-sm text-fg-subtle">No products match.</li>
          ) : (
            results.map((p, i) => (
              <li
                key={p.id}
                id={`${listId}-${p.id}`}
                role="option"
                aria-selected={i === highlight}
                onMouseEnter={() => setHighlight(i)}
                onMouseDown={(e) => {
                  // mousedown (not click) so it fires before the input blur closes the list.
                  e.preventDefault();
                  pick(p);
                }}
                className={cn(
                  'cursor-pointer px-3 py-2 text-sm',
                  i === highlight ? 'bg-surface-2 text-fg' : 'text-fg-muted',
                )}
              >
                {p.name}
              </li>
            ))
          )}
        </ul>
      )}
    </div>
  );
}
