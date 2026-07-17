import { Link } from 'react-router-dom';

const TILES = [
  {
    to: '/kits',
    title: 'Build a kit',
    blurb: 'Bundle several gifts into one branded box for your team.',
    icon: '📦',
  },
  {
    to: '/products',
    title: 'Bulk pricing',
    blurb: 'Unit price drops as quantity climbs. Quote any item in the catalogue.',
    icon: '🏢',
  },
];

export default function PromoTiles() {
  return (
    <ul className="grid grid-cols-1 gap-3 sm:grid-cols-2">
      {TILES.map((t) => (
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
