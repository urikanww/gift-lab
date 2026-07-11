import { useState } from 'react';
import { Badge, Tooltip, cn } from '../ui';
import { safeHref } from '../lib/safeHref';
import ImageLightbox from '../components/ImageLightbox';
import type { AdminProduct, LicenseTier, ProductClass } from '../types';

/** Shared labels/badges for the admin product list + detail pages. */

export const CLASS_LABELS: Record<ProductClass, string> = {
  CORE: 'Core',
  SCRAPED_UV: 'UV Print',
  MODEL_3D: '3D Printed',
};

export function classLabel(cls: string): string {
  return CLASS_LABELS[cls as ProductClass] ?? cls;
}

const PUBLISH_TONE: Record<string, 'neutral' | 'brand' | 'success' | 'danger' | 'warning'> = {
  PENDING: 'neutral',
  READY_TO_APPROVE: 'warning',
  PUBLISHED: 'success',
  CANNOT_PUBLISH: 'danger',
};

const PUBLISH_LABELS: Record<string, string> = {
  PENDING: 'Pending',
  READY_TO_APPROVE: 'Ready to approve',
  PUBLISHED: 'Published',
  CANNOT_PUBLISH: 'Cannot publish',
};

export function PublishBadge({ state }: { state: string }) {
  return (
    <Badge tone={PUBLISH_TONE[state] ?? 'neutral'} size="sm" dot>
      {PUBLISH_LABELS[state] ?? state}
    </Badge>
  );
}

/**
 * Licence-tier badge - superadmin-only surface. 'standard' renders nothing;
 * 'extended' is neutral; 'high_risk' is a red flag for legal attention (with a
 * hover/focus tooltip explaining the licence risk).
 */
export function LicenseTierBadge({ tier }: { tier: LicenseTier }) {
  if (tier === 'standard') return null;
  if (tier === 'high_risk') {
    return (
      <Tooltip content="High legal risk: the source licence restricts commercial resale/derivatives. Superadmin sign-off required before publishing.">
        <Badge tone="danger" size="sm" tabIndex={0} className="cursor-help">
          High legal risk
        </Badge>
      </Tooltip>
    );
  }
  return (
    <Badge tone="neutral" size="sm">
      Extended licence
    </Badge>
  );
}

/**
 * IP-risk badge - distinct from the licence-tier badge above. Renders ONLY when
 * the IP screen flagged the item (`ip_flagged`). It is a NON-blocking, surfaced
 * risk tag (the item can still publish), so it's a danger-tone flag with a
 * hover/focus tooltip naming the matched franchise/keyword when known.
 */
export function IpRiskBadge({ product }: { product: AdminProduct }) {
  if (!product.ip_flagged) return null;
  const reason = product.ip_flag_reason?.trim();
  return (
    <Tooltip
      content={
        reason
          ? `Possible IP match: ${reason}. Publishable, but review the likeness before approving.`
          : 'Flagged by the IP screen - may resemble a protected franchise or character. Publishable, but review before approving.'
      }
    >
      <Badge tone="danger" size="sm" dot tabIndex={0} className="cursor-help">
        IP risk
      </Badge>
    </Tooltip>
  );
}

/**
 * Thumbnail with a letter fallback, mirroring CatalogueAdminPage's ItemThumb.
 * Pass `zoomable` to make it open a full-screen zoom/pan viewer on click - staff
 * inspect the product photo up close without leaving the page.
 */
export function ItemThumb({
  name,
  imageUrl,
  zoomable = false,
}: {
  name: string;
  imageUrl: string | null;
  zoomable?: boolean;
}) {
  const [failed, setFailed] = useState(false);
  const [open, setOpen] = useState(false);
  const href = safeHref(imageUrl);
  if (!href || failed) {
    return (
      <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-md bg-gradient-to-br from-brand-100 to-accent-50 font-display text-lg text-brand-700">
        {name.charAt(0)}
      </div>
    );
  }
  const img = (
    <img
      src={href}
      alt=""
      className={cn('h-11 w-11 shrink-0 rounded-md object-cover', zoomable && 'cursor-zoom-in')}
      loading="lazy"
      referrerPolicy="no-referrer"
      onError={() => setFailed(true)}
    />
  );
  if (!zoomable) return img;
  return (
    <>
      <button
        type="button"
        onClick={() => setOpen(true)}
        aria-label={`View ${name} image`}
        className="shrink-0 rounded-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
      >
        {img}
      </button>
      <ImageLightbox src={href} alt={name} open={open} onClose={() => setOpen(false)} />
    </>
  );
}
