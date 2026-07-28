import { useEffect, useState } from 'react';
import { Badge } from '../../ui';
import ImageLightbox from '../ImageLightbox';
import { fetchArtworkPreview } from '../../lib/uploadArtwork';
import type { Customization } from '../../types';

/**
 * The buyer's design brief for a "Upload finished look" line, shown to staff on
 * the proofing panel so they can produce the artwork. Renders the reference
 * image(s) the buyer attached plus their placement notes.
 *
 * Only for buyer_uploaded lines: a self-designed line already carries its own
 * captured artwork (previewed elsewhere), and a plain line has no brief at all.
 * Renders nothing in those cases so the row stays clean.
 */

/** One reference thumbnail: resolves the private ref to a signed URL, opens a zoom viewer. */
function ReferenceThumb({ refKey, index }: { refKey: string; index: number }) {
  const [url, setUrl] = useState<string | null>(null);
  const [failed, setFailed] = useState(false);
  const [open, setOpen] = useState(false);

  useEffect(() => {
    setFailed(false);
    // A ready http(s) URL is used directly; a private storage ref is exchanged
    // for a short-lived signed preview URL (same path as the buyer's own preview).
    if (/^https?:\/\//i.test(refKey)) {
      setUrl(refKey);
      return;
    }
    let active = true;
    setUrl(null);
    fetchArtworkPreview(refKey).then((result) => {
      if (!active) return;
      if (result.ok) setUrl(result.url);
      else setFailed(true);
    });
    return () => {
      active = false;
    };
  }, [refKey]);

  if (failed) {
    return (
      <div
        className="flex h-16 w-16 items-center justify-center rounded-md border border-dashed border-border bg-surface-2/50 text-2xs text-fg-subtle"
        title="Reference couldn’t be loaded"
      >
        no preview
      </div>
    );
  }
  if (!url) return null;

  return (
    <>
      <button
        type="button"
        onClick={() => setOpen(true)}
        className="block h-16 w-16 overflow-hidden rounded-md border border-border bg-surface transition-colors hover:border-primary/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        aria-label={`Open buyer reference ${index + 1}`}
      >
        <img src={url} alt="" referrerPolicy="no-referrer" className="h-full w-full object-cover" />
      </button>
      <ImageLightbox src={url} alt={`Buyer reference ${index + 1}`} open={open} onClose={() => setOpen(false)} />
    </>
  );
}

export default function BuyerBrief({ customization }: { customization?: Customization | null }) {
  if (!customization || customization.mode !== 'buyer_uploaded') return null;

  // The buyer's logo (if any) leads, then their reference photos of the look.
  const refs = [
    ...(customization.artwork_ref ? [customization.artwork_ref] : []),
    ...(customization.reference_refs ?? []),
  ];
  const notes = customization.placement_notes?.trim();

  // A buyer_uploaded line with neither refs nor notes shouldn't happen (the
  // uploader requires a reference), but degrade to the badge alone if it does.
  return (
    <div className="rounded-md border border-dashed border-border bg-surface-2/40 p-3">
      <div className="mb-2 flex items-center gap-2">
        <Badge tone="brand" size="sm">
          Buyer’s brief — please design
        </Badge>
      </div>
      {refs.length > 0 && (
        <div className="flex flex-wrap gap-2">
          {refs.map((r, i) => (
            <ReferenceThumb key={`${r}-${i}`} refKey={r} index={i} />
          ))}
        </div>
      )}
      {notes && (
        <p className="mt-2 whitespace-pre-line text-sm text-fg">
          <span className="font-medium text-fg-muted">Placement notes: </span>
          {notes}
        </p>
      )}
    </div>
  );
}
