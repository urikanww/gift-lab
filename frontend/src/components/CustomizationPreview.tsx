import { useEffect, useState } from 'react';
import { DesignOnProduct } from './cart/CartSummary';
import ImageLightbox from './ImageLightbox';
import { fetchArtworkPreview } from '../lib/uploadArtwork';
import { cn } from '../ui';
import type { Customization } from '../types';

/**
 * The saved design/reference ref to preview, if any.
 *
 * Deliberately keyed on the refs themselves, NOT on `customization.mode`:
 * lines saved before the mode flag existed carry an `artwork_ref` with no
 * `mode` at all, so a mode check silently hides real designs.
 */
export function customizationImageRef(c?: Customization | null): string | null {
  if (!c) return null;
  if (c.artwork_ref) return c.artwork_ref;
  if (c.reference_refs && c.reference_refs.length > 0) return c.reference_refs[0];
  return null;
}

/**
 * Shows the buyer's saved customization (their captured design or reference
 * image) as a thumbnail that opens a zoom viewer - visibility + assurance that
 * what they laid out is on the order. Renders nothing for plain lines.
 *
 * Shared by the cart and the order detail page: a design that disappears once
 * the quote is raised reads as lost work.
 */
export default function CustomizationPreview({
  customization,
  productName,
  productImageUrl,
  label = 'Your design',
}: {
  customization?: Customization | null;
  productName: string;
  productImageUrl?: string | null;
  label?: string;
}) {
  const ref = customizationImageRef(customization);
  // A captured canvas design (artwork_ref) is a transparent PNG of only the
  // design layers, so we composite it back onto the product photo. A buyer's
  // reference photo already shows the finished look and stands on its own.
  const isCapturedDesign = !!customization?.artwork_ref;
  const baseImageUrl = isCapturedDesign ? (productImageUrl ?? null) : null;
  const [url, setUrl] = useState<string | null>(null);
  // Distinguishes "still fetching" from "fetch came back empty-handed", so a
  // ref we could not resolve is admitted to rather than rendered as nothing.
  const [failed, setFailed] = useState(false);
  const [open, setOpen] = useState(false);

  useEffect(() => {
    setFailed(false);
    if (!ref) {
      setUrl(null);
      return;
    }
    // A ready http(s) URL is used directly; a private storage ref is exchanged
    // for a short-lived signed preview URL.
    if (/^https?:\/\//i.test(ref)) {
      setUrl(ref);
      return;
    }
    let active = true;
    setUrl(null);
    fetchArtworkPreview(ref).then((result) => {
      if (!active) return;
      if (result.ok) setUrl(result.url);
      else setFailed(true);
    });
    return () => {
      active = false;
    };
  }, [ref]);

  // No artwork on this line at all - there is nothing to preview, so show
  // nothing. This is the correct silent case.
  if (!ref) return null;

  // We know this line HAS a design but could not load it. Say so quietly: the
  // preview is supplementary, so this stays a muted placeholder rather than an
  // error banner - but it must never be indistinguishable from "no design".
  if (failed) {
    return (
      <div
        // Not a live region: an order with ten unloadable lines would announce
        // ten times. The text sits in reading order, which is enough.
        className="mt-2 inline-flex items-center gap-2 rounded-md border border-dashed border-border bg-surface-2/50 p-1 pr-2.5"
      >
        <div className="flex h-12 w-12 items-center justify-center rounded bg-surface-2 text-fg-subtle" aria-hidden="true">
          {/* Torn-image glyph - reads as "picture missing", not "error". */}
          <svg viewBox="0 0 24 24" className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth="1.5">
            <rect x="3" y="3" width="18" height="18" rx="2" />
            <path d="m3 16 5-5 4 4 3-3 6 6" />
            <circle cx="8.5" cy="8.5" r="1.5" />
          </svg>
        </div>
        <span className="text-xs text-fg-muted">{label} couldn’t be loaded</span>
      </div>
    );
  }

  // Fetch still in flight - render nothing rather than flashing a placeholder.
  if (!url) return null;

  return (
    <>
      <button
        type="button"
        onClick={() => setOpen(true)}
        className={cn(
          'mt-2 inline-flex items-center gap-2 rounded-md border border-border bg-surface p-1 pr-2.5 text-left',
          'transition-colors hover:border-primary/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
        )}
        aria-label={`Preview your design for ${productName}`}
      >
        {baseImageUrl ? (
          <DesignOnProduct
            productImageUrl={baseImageUrl}
            designSrc={url}
            className="h-12 w-12 rounded"
          />
        ) : (
          <img
            src={url}
            alt=""
            referrerPolicy="no-referrer"
            className="h-12 w-12 rounded bg-[repeating-conic-gradient(var(--color-surface-2)_0%_25%,var(--color-surface)_0%_50%)] bg-[length:12px_12px] object-contain"
          />
        )}
        <span className="text-xs font-medium text-fg">{label}</span>
      </button>
      <ImageLightbox
        src={url}
        baseImageUrl={baseImageUrl}
        alt={`${productName} customization`}
        open={open}
        onClose={() => setOpen(false)}
      />
    </>
  );
}
