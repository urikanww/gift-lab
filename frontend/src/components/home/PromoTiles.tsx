import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { fetchBulkPricing, formatPct } from '../../lib/catalogue';

// True at any config and states no numbers - the engine's discount is a single
// step, so "drops as quantity climbs" would oversell it.
const BULK_FALLBACK_BLURB = 'Unit price drops on larger orders. Quote any item in the catalogue.';

export default function PromoTiles() {
  // The real offer, when we can get it. This tile is on the public home page,
  // so a failed fetch must degrade to generic-but-true copy, never a blank.
  const [bulkBlurb, setBulkBlurb] = useState(BULK_FALLBACK_BLURB);

  useEffect(() => {
    let active = true;
    fetchBulkPricing().then((b) => {
      if (!active || !b || b.bulkQty === null) return;
      setBulkBlurb(`${formatPct(b.discountPct)}% off at ${b.bulkQty}+ units. Quote any item in the catalogue.`);
    });
    return () => {
      active = false;
    };
  }, []);

  const tiles = [{ to: '/products', title: 'Bulk pricing', blurb: bulkBlurb, icon: '🏢' }];

  return (
    <div className="flex flex-col gap-3">
      {tiles.map((t) => (
        <Link
          key={t.to}
          to={t.to}
          className="flex min-h-[44px] items-center gap-3 rounded-xl border border-border bg-gradient-to-r from-brand-50 via-surface to-accent-50 px-4 py-3 transition-colors hover:border-primary/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        >
          <span className="text-xl" aria-hidden="true">
            {t.icon}
          </span>
          {/* Title and blurb sit on one line so a single promo reads as a band
              rather than a mostly-empty card. */}
          <span className="flex-1 text-sm">
            <span className="font-display text-fg">{t.title}</span>{' '}
            <span className="text-fg-muted">{t.blurb}</span>
          </span>
          <svg
            viewBox="0 0 20 20"
            className="h-4 w-4 shrink-0 text-fg-subtle"
            fill="none"
            aria-hidden="true"
          >
            <path
              d="M8 5l5 5-5 5"
              stroke="currentColor"
              strokeWidth="1.6"
              strokeLinecap="round"
              strokeLinejoin="round"
            />
          </svg>
        </Link>
      ))}
    </div>
  );
}
