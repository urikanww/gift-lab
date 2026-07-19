import { useCallback, useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Button, cn } from '../ui';

interface Props {
  src: string | null;
  alt?: string;
  open: boolean;
  onClose: () => void;
  /**
   * Optional product photo drawn as an `object-cover` backdrop directly behind
   * `src`, zoomed/panned as one unit. Used to show a buyer's transparent design
   * export sitting ON the product, mirroring the designer's stacked layers.
   */
  baseImageUrl?: string | null;
}

const MIN_SCALE = 1;
const MAX_SCALE = 6;
const STEP = 0.5;

/**
 * Full-screen product-image viewer for staff: zoom (buttons / wheel / +- keys)
 * and pan (drag when zoomed). Portalled overlay matching the app's Modal
 * conventions - z-modal, ink backdrop, body scroll lock, Escape to close.
 */
export default function ImageLightbox({ src, alt = '', open, onClose, baseImageUrl = null }: Props) {
  const [scale, setScale] = useState(1);
  const [tx, setTx] = useState(0);
  const [ty, setTy] = useState(0);
  const dragOrigin = useRef<{ x: number; y: number } | null>(null);
  const dragging = useRef(false);

  const reset = useCallback(() => {
    setScale(1);
    setTx(0);
    setTy(0);
  }, []);

  const zoomTo = useCallback((next: number) => {
    const clamped = Math.min(MAX_SCALE, Math.max(MIN_SCALE, next));
    setScale(clamped);
    // Recentre when returning to 1x so a re-zoom starts from the middle.
    if (clamped === 1) {
      setTx(0);
      setTy(0);
    }
  }, []);

  // Fresh transform each time the viewer opens or the image changes.
  useEffect(() => {
    if (open) reset();
  }, [open, src, reset]);

  // Body scroll lock + keyboard shortcuts while open.
  useEffect(() => {
    if (!open) return;
    const { overflow } = document.body.style;
    document.body.style.overflow = 'hidden';
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
      else if (e.key === '+' || e.key === '=') zoomTo(scale + STEP);
      else if (e.key === '-' || e.key === '_') zoomTo(scale - STEP);
      else if (e.key === '0') reset();
    };
    window.addEventListener('keydown', onKey);
    return () => {
      document.body.style.overflow = overflow;
      window.removeEventListener('keydown', onKey);
    };
  }, [open, onClose, reset, zoomTo, scale]);

  if (!open || !src) return null;

  const onWheel = (e: React.WheelEvent) => {
    zoomTo(scale - Math.sign(e.deltaY) * STEP);
  };

  const onPointerDown = (e: React.PointerEvent) => {
    if (scale <= 1) return;
    dragging.current = true;
    dragOrigin.current = { x: e.clientX - tx, y: e.clientY - ty };
    e.currentTarget.setPointerCapture(e.pointerId);
  };
  const onPointerMove = (e: React.PointerEvent) => {
    if (!dragging.current || !dragOrigin.current) return;
    setTx(e.clientX - dragOrigin.current.x);
    setTy(e.clientY - dragOrigin.current.y);
  };
  const onPointerUp = (e: React.PointerEvent) => {
    dragging.current = false;
    dragOrigin.current = null;
    if (e.currentTarget.hasPointerCapture(e.pointerId)) {
      e.currentTarget.releasePointerCapture(e.pointerId);
    }
  };

  const ctrlBtn = 'text-white hover:bg-white/15';

  return createPortal(
    <div
      className="fixed inset-0 z-modal flex flex-col bg-ink-900/90 backdrop-blur-sm"
      role="dialog"
      aria-modal="true"
      aria-label="Product image viewer"
    >
      {/* Toolbar */}
      <div className="flex items-center justify-between gap-2 p-3">
        <span className="rounded bg-black/40 px-2 py-1 text-xs tabular-nums text-white/90">
          {Math.round(scale * 100)}%
        </span>
        <div className="flex items-center gap-1">
          <Button variant="ghost" size="sm" className={ctrlBtn} aria-label="Zoom out" onClick={() => zoomTo(scale - STEP)}>
            −
          </Button>
          <Button variant="ghost" size="sm" className={ctrlBtn} aria-label="Reset zoom" onClick={reset}>
            Reset
          </Button>
          <Button variant="ghost" size="sm" className={ctrlBtn} aria-label="Zoom in" onClick={() => zoomTo(scale + STEP)}>
            +
          </Button>
          <Button variant="ghost" size="sm" className={ctrlBtn} aria-label="Close viewer" onClick={onClose}>
            ✕
          </Button>
        </div>
      </div>

      {/* Image stage - clicking the empty area closes. */}
      <div
        className="relative flex flex-1 items-center justify-center overflow-hidden p-4"
        onWheel={onWheel}
        onClick={(e) => {
          if (e.target === e.currentTarget) onClose();
        }}
      >
        <div
          onPointerDown={onPointerDown}
          onPointerMove={onPointerMove}
          onPointerUp={onPointerUp}
          onClick={() => {
            if (scale === 1) zoomTo(scale + STEP);
          }}
          className={cn(
            'relative select-none',
            scale > 1 ? 'cursor-grab active:cursor-grabbing' : 'cursor-zoom-in',
          )}
          style={{
            transform: `translate(${tx}px, ${ty}px) scale(${scale})`,
            transition: dragging.current ? 'none' : 'transform 120ms ease-out',
          }}
        >
          {/* Product photo backdrop, sized to and clipped by the design's box so
              the two zoom/pan as one - matches the designer's object-cover
              backdrop under the transparent design layer. */}
          {baseImageUrl && (
            <img
              src={baseImageUrl}
              alt=""
              aria-hidden="true"
              draggable={false}
              referrerPolicy="no-referrer"
              className="pointer-events-none absolute inset-0 h-full w-full object-cover"
            />
          )}
          <img
            src={src}
            alt={alt}
            draggable={false}
            referrerPolicy="no-referrer"
            className="relative block max-h-[80vh] max-w-full object-contain"
          />
        </div>
      </div>
    </div>,
    document.body,
  );
}
