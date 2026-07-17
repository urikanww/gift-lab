import { Link } from 'react-router-dom';
import { Logo } from '../ui';

const TRUST_BADGES = [
  { icon: '⚡', label: '3-day turnaround' },
  { icon: '🎨', label: 'Live 2D + 3D preview' },
  { icon: '🔒', label: 'Secure checkout' },
  { icon: '🏢', label: 'Bulk & corporate' },
];

const LINK_COLUMNS: { heading: string; links: { label: string; to: string }[] }[] = [
  {
    heading: 'Shop',
    links: [
      { label: 'Products', to: '/products' },
      { label: 'Kits', to: '/kits' },
      { label: 'Gift ideas', to: '/gift-ideas' },
      { label: 'Track order', to: '/track' },
      { label: 'Cart', to: '/cart' },
    ],
  },
  {
    heading: 'Company',
    links: [
      { label: 'About', to: '#' },
      { label: 'Help', to: '#' },
    ],
  },
  {
    heading: 'Account',
    links: [
      { label: 'My Orders', to: '/quotes' },
      { label: 'Log in', to: '/login' },
    ],
  },
];

export default function SiteFooter() {
  return (
    <footer className="mt-16 border-t border-border bg-surface text-fg-muted">
      <div className="mx-auto max-w-content px-4 py-10 sm:px-6">
        <ul className="mb-10 grid grid-cols-2 gap-4 sm:grid-cols-4">
          {TRUST_BADGES.map((b) => (
            <li
              key={b.label}
              className="flex items-center gap-2 rounded-md border border-border bg-surface-2 px-3 py-2 text-sm text-fg"
            >
              <span aria-hidden="true" className="text-base">
                {b.icon}
              </span>
              <span>{b.label}</span>
            </li>
          ))}
        </ul>

        <div className="grid grid-cols-1 gap-8 md:grid-cols-4">
          <div>
            <Link
              to="/"
              aria-label="GiftLab home"
              className="inline-flex rounded-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
              <Logo />
            </Link>
            <p className="mt-3 max-w-xs text-sm">
              Custom gifts and merchandise, designed live and delivered fast.
            </p>
          </div>

          {LINK_COLUMNS.map((col) => (
            <nav key={col.heading} aria-label={col.heading}>
              <h2 className="mb-3 text-sm font-semibold text-fg">{col.heading}</h2>
              <ul className="flex flex-col gap-2">
                {col.links.map((link) => (
                  <li key={link.label}>
                    <Link
                      to={link.to}
                      className="text-sm transition-colors hover:text-fg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                      {link.label}
                    </Link>
                  </li>
                ))}
              </ul>
            </nav>
          ))}
        </div>

        <p className="mt-10 border-t border-border pt-6 text-xs">© 2026 GiftLab</p>
      </div>
    </footer>
  );
}
