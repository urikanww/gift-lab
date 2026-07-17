import { useCallback, useEffect, useRef, useState } from 'react';
import { ProductCard } from '../product/ProductCard';
import { productPath } from '../../lib/catalogue';
import type { Product } from '../../types';

/**
 * Product carousel. No manual horizontal scroll - the rail is button-driven
 * (prev/next flank the cards). Buttons disable at each edge. Programmatic
 * scrollLeft still works under overflow-x-hidden, so cards slide on click only.
 */
export default function ProductRail({ items, label }: { items: Product[]; label: string }) {
  const railRef = useRef<HTMLDivElement>(null);
  const [atStart, setAtStart] = useState(true);
  const [atEnd, setAtEnd] = useState(false);

  const updateEdges = useCallback(() => {
    const el = railRef.current;
    if (!el) return;
    setAtStart(el.scrollLeft <= 1);
    setAtEnd(el.scrollLeft + el.clientWidth >= el.scrollWidth - 1);
  }, []);

  useEffect(() => {
    updateEdges();
    window.addEventListener('resize', updateEdges);
    return () => window.removeEventListener('resize', updateEdges);
  }, [items, updateEdges]);

  const move = (dir: 1 | -1) => {
    const el = railRef.current;
    if (!el) return;
    el.scrollBy({ left: dir * Math.max(el.clientWidth * 0.8, 208), behavior: 'smooth' });
  };

  return (
    <div className="flex items-center gap-2 sm:gap-3">
      <RailButton dir="prev" onClick={() => move(-1)} disabled={atStart} label={label} />
      <div ref={railRef} onScroll={updateEdges} className="flex flex-1 gap-4 overflow-x-hidden py-1">
        {items.map((p) => (
          <div key={p.id} className="w-52 shrink-0">
            <ProductCard product={p} to={productPath(p)} showMeta />
          </div>
        ))}
      </div>
      <RailButton dir="next" onClick={() => move(1)} disabled={atEnd} label={label} />
    </div>
  );
}

function RailButton({
  dir,
  onClick,
  disabled,
  label,
}: {
  dir: 'prev' | 'next';
  onClick: () => void;
  disabled: boolean;
  label: string;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      aria-label={`${dir === 'prev' ? 'Previous' : 'Next'} ${label}`}
      className="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-border bg-surface text-fg-muted shadow-card transition-colors hover:bg-surface-2 hover:text-fg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-40"
    >
      <svg viewBox="0 0 20 20" className="h-5 w-5" fill="none" aria-hidden="true">
        <path
          d={dir === 'prev' ? 'M12 5l-5 5 5 5' : 'M8 5l5 5-5 5'}
          stroke="currentColor"
          strokeWidth="1.6"
          strokeLinecap="round"
          strokeLinejoin="round"
        />
      </svg>
    </button>
  );
}
