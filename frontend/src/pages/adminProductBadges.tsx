import { useState } from 'react';
import { Badge } from '../ui';
import { safeHref } from '../lib/safeHref';
import type { LicenseTier, ProductClass } from '../types';

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
 * Licence-tier badge — superadmin-only surface. 'standard' renders nothing;
 * 'extended' is neutral; 'high_risk' is a red flag for legal attention.
 */
export function LicenseTierBadge({ tier }: { tier: LicenseTier }) {
  if (tier === 'standard') return null;
  if (tier === 'high_risk') {
    return (
      <Badge tone="danger" size="sm">
        High legal risk
      </Badge>
    );
  }
  return (
    <Badge tone="neutral" size="sm">
      Extended licence
    </Badge>
  );
}

/** Thumbnail with a letter fallback, mirroring CatalogueAdminPage's ItemThumb. */
export function ItemThumb({ name, imageUrl }: { name: string; imageUrl: string | null }) {
  const [failed, setFailed] = useState(false);
  const href = safeHref(imageUrl);
  if (!href || failed) {
    return (
      <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-md bg-gradient-to-br from-brand-100 to-accent-50 font-display text-lg text-brand-700">
        {name.charAt(0)}
      </div>
    );
  }
  return (
    <img
      src={href}
      alt=""
      className="h-11 w-11 shrink-0 rounded-md object-cover"
      loading="lazy"
      referrerPolicy="no-referrer"
      onError={() => setFailed(true)}
    />
  );
}
