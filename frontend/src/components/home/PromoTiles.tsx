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
    <ul className="grid grid-cols-1 gap-3">
      {tiles.map((t) => (
        <li key={t.to}>
          <Link
            to={t.to}
            className="flex h-full items-start gap-3 rounded-2xl border border-border bg-gradient-to-br from-brand-50 via-surface to-accent-50 p-5 transition-colors hover:border-primary/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          >
            <span className="text-3xl" aria-hidden="true">
              {t.icon}
            </span>
            <span>
              <span className="block font-display text-base text-fg">{t.title}</span>
              <span className="block text-sm text-fg-muted">{t.blurb}</span>
            </span>
          </Link>
        </li>
      ))}
    </ul>
  );
}
