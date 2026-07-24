import { useEffect, useState } from 'react';
import { Modal } from '../../ui';
import { DesignOnProduct } from '../cart/CartSummary';
import { fetchArtworkPreview } from '../../lib/uploadArtwork';

/**
 * Picker for which existing artwork to attach as the proof, opened from the
 * "Use existing artwork" button in the staff proof controls. Lists everything
 * already on the order - the buyer's line designs, images the buyer attached
 * to change requests, and previously issued proof versions - so staff never
 * re-upload a file the order already carries. Always opens, even for a single
 * option: the choice (and what it is) stays visible instead of silently
 * filling the field.
 */

export interface ArtworkOption {
  /** Storage ref (or pasted URL) that fills the proof field when picked. */
  ref: string;
  /** Row label; also shown on the filled field, so it names the artwork. */
  name: string;
  /** Source line, e.g. "Attached by the buyer". */
  detail?: string;
  /** Product photo to composite a transparent line-design PNG onto. */
  productImageUrl?: string | null;
  /** Pre-resolved viewing URL when the API already supplied one. */
  url?: string | null;
}

/**
 * Thumbnail: pre-resolved URL when the API gave one, otherwise the signed
 * preview exchange; quiet placeholder while loading or for files an <img>
 * cannot render (a PDF proof). The row's text carries the identification.
 */
function ArtworkThumb({ option }: { option: ArtworkOption }) {
  const [url, setUrl] = useState<string | null>(null);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    setFailed(false);
    const direct = option.url ?? (/^https?:\/\//i.test(option.ref) ? option.ref : null);
    if (direct) {
      setUrl(direct);
      return;
    }
    let active = true;
    setUrl(null);
    fetchArtworkPreview(option.ref).then((result) => {
      if (active && result.ok) setUrl(result.url);
    });
    return () => {
      active = false;
    };
  }, [option.ref, option.url]);

  if (!url || failed) {
    return <div className="h-14 w-14 shrink-0 rounded bg-surface-2" aria-hidden="true" />;
  }

  return option.productImageUrl ? (
    <DesignOnProduct
      productImageUrl={option.productImageUrl}
      designSrc={url}
      className="h-14 w-14 shrink-0 rounded"
    />
  ) : (
    <img
      src={url}
      alt=""
      referrerPolicy="no-referrer"
      onError={() => setFailed(true)}
      className="h-14 w-14 shrink-0 rounded bg-[repeating-conic-gradient(var(--color-surface-2)_0%_25%,var(--color-surface)_0%_50%)] bg-[length:12px_12px] object-contain"
    />
  );
}

export default function ArtworkPicker({
  options,
  open,
  onClose,
  onSelect,
}: {
  options: ArtworkOption[];
  open: boolean;
  onClose: () => void;
  onSelect: (ref: string) => void;
}) {
  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Use existing artwork"
      description="Pick artwork already on this order to attach as the proof."
      size="sm"
    >
      <ul className="flex flex-col divide-y divide-border">
        {options.map((option) => (
          <li key={option.ref}>
            <button
              type="button"
              onClick={() => onSelect(option.ref)}
              className="flex w-full items-center gap-3 rounded-md px-2 py-3 text-left transition-colors hover:bg-surface-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
              <ArtworkThumb option={option} />
              <span className="flex min-w-0 flex-col">
                <span className="truncate text-sm font-medium text-fg">{option.name}</span>
                {option.detail && (
                  <span className="text-xs text-fg-muted">{option.detail}</span>
                )}
              </span>
            </button>
          </li>
        ))}
      </ul>
    </Modal>
  );
}
