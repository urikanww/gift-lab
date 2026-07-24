import { useState } from 'react';

/**
 * Renders proof artwork in place so the buyer sees what they're approving
 * without a click-through. The signed URL carries no reliable extension, so we
 * try to render it as an image and fall back to an open-in-new-tab link when it
 * isn't one (e.g. a PDF proof) or fails to load. Clicking the image still opens
 * the full-size artwork in a new tab.
 *
 * Shared: used by the buyer's per-line proof review (BuyerProofItem) and the
 * page's proof surfaces, so the fallback logic lives in one place.
 */
export default function ArtworkPreview({ url }: { url: string | null | undefined }) {
  const [failed, setFailed] = useState(false);

  if (!url) {
    return <p className="text-sm text-fg-muted">Artwork preview isn’t available — please contact us.</p>;
  }

  if (failed) {
    return (
      <a
        href={url}
        target="_blank"
        rel="noreferrer"
        className="inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline focus-visible:outline-none focus-visible:underline"
      >
        Open artwork ↗
      </a>
    );
  }

  return (
    <a
      href={url}
      target="_blank"
      rel="noreferrer"
      className="block overflow-hidden rounded-md border border-border bg-surface-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-bg"
      title="Open full-size artwork"
    >
      <img
        src={url}
        alt="Proof artwork"
        onError={() => setFailed(true)}
        className="mx-auto max-h-[28rem] w-full object-contain"
      />
    </a>
  );
}
