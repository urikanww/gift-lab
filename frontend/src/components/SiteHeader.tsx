import { useState, type FormEvent } from 'react';
import { Link, NavLink, useNavigate } from 'react-router-dom';
import { AnimatePresence, motion } from 'framer-motion';
import { useAuthStore } from '../stores/authStore';
import { useCartStore } from '../stores/cartStore';
import { CATEGORIES } from '../lib/categories';
import { Badge, Input, useTheme, cn } from '../ui';
import { useReducedMotionSafe } from '../motion';

const navLinkClass = ({ isActive }: { isActive: boolean }) =>
  cn(
    'rounded-md px-3 py-2 text-sm font-medium transition-colors duration-fast',
    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
    isActive ? 'bg-surface-2 text-fg' : 'text-fg-muted hover:bg-surface-2 hover:text-fg',
  );

export default function SiteHeader() {
  const user = useAuthStore((s) => s.user);
  const cartCount = useCartStore((s) => s.lines.length);
  const navigate = useNavigate();
  const [drawerOpen, setDrawerOpen] = useState(false);

  const onSearch = (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const value = new FormData(e.currentTarget).get('q')?.toString().trim() ?? '';
    if (value) navigate(`/products?q=${encodeURIComponent(value)}`);
  };

  return (
    <header className="sticky top-0 z-header border-b border-border bg-surface/80 backdrop-blur-md">
      <div className="mx-auto flex h-16 max-w-content items-center gap-4 px-4 sm:px-6">
        <Link
          to="/"
          aria-label="GiftLab home"
          className="font-display text-xl font-semibold tracking-tight text-fg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        >
          GIFT<span className="text-primary">LAB</span>
        </Link>

        {/* Desktop nav */}
        <nav className="hidden flex-1 items-center gap-1 md:flex" aria-label="Primary">
          <NavLink to="/products" end className={navLinkClass}>
            Products
          </NavLink>
          {CATEGORIES.map((c) => (
            <NavLink key={c.key} to={`/products?class=${c.key}`} className={navLinkClass}>
              <span aria-hidden="true">{c.icon}</span> {c.label}
            </NavLink>
          ))}
        </nav>

        <form onSubmit={onSearch} role="search" className="hidden lg:block lg:w-56">
          <Input name="q" type="search" aria-label="Search products" placeholder="Search products…" />
        </form>

        <div className="ml-auto flex items-center gap-2">
          <ThemeToggle />
          <CartLink count={cartCount} />
          <div className="hidden md:block">
            <AccountLink user={user} />
          </div>

          <button
            type="button"
            className="inline-flex h-9 w-9 items-center justify-center rounded-md text-fg hover:bg-surface-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring md:hidden"
            aria-label={drawerOpen ? 'Close menu' : 'Open menu'}
            aria-expanded={drawerOpen}
            onClick={() => setDrawerOpen(true)}
          >
            <svg viewBox="0 0 20 20" className="h-5 w-5" fill="none" aria-hidden="true">
              <path d="M3 6h14M3 10h14M3 14h14" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" />
            </svg>
          </button>
        </div>
      </div>

      <MobileDrawer open={drawerOpen} onClose={() => setDrawerOpen(false)} user={user} onSearch={onSearch} />
    </header>
  );
}

function ThemeToggle() {
  const { theme, toggleTheme } = useTheme();
  return (
    <button
      type="button"
      onClick={toggleTheme}
      aria-label="Toggle theme"
      className="inline-flex h-9 w-9 items-center justify-center rounded-md text-lg text-fg-muted transition-colors hover:bg-surface-2 hover:text-fg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
    >
      <span aria-hidden="true">{theme === 'dark' ? '☀' : '☾'}</span>
    </button>
  );
}

function CartLink({ count }: { count: number }) {
  return (
    <NavLink
      to="/cart"
      className={({ isActive }) =>
        cn(
          'relative inline-flex h-9 w-9 items-center justify-center rounded-md transition-colors',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
          isActive ? 'bg-surface-2 text-fg' : 'text-fg-muted hover:bg-surface-2 hover:text-fg',
        )
      }
      aria-label={`Cart, ${count} item${count === 1 ? '' : 's'}`}
    >
      <svg viewBox="0 0 20 20" className="h-5 w-5" fill="none" aria-hidden="true">
        <path
          d="M3 3h2l1.5 9.5A2 2 0 0 0 8.5 14h6a2 2 0 0 0 2-1.6L18 6H5"
          stroke="currentColor"
          strokeWidth="1.5"
          strokeLinecap="round"
          strokeLinejoin="round"
        />
        <circle cx="8.5" cy="17" r="1" fill="currentColor" />
        <circle cx="15" cy="17" r="1" fill="currentColor" />
      </svg>
      {count > 0 && (
        <span className="absolute -right-1 -top-1">
          <Badge tone="brand" size="sm" className="min-w-[1.15rem] justify-center px-1">
            {count}
          </Badge>
        </span>
      )}
    </NavLink>
  );
}

function AccountLink({ user, onClick }: { user: unknown; onClick?: () => void }) {
  return user ? (
    <NavLink to="/quotes" onClick={onClick} className={navLinkClass}>
      My Orders
    </NavLink>
  ) : (
    <NavLink to="/login" onClick={onClick} className={navLinkClass}>
      Log in
    </NavLink>
  );
}

function MobileDrawer({
  open,
  onClose,
  user,
  onSearch,
}: {
  open: boolean;
  onClose: () => void;
  user: unknown;
  onSearch: (e: FormEvent<HTMLFormElement>) => void;
}) {
  const animate = useReducedMotionSafe();
  return (
    <AnimatePresence>
      {open && (
        <>
          <motion.div
            className="fixed inset-0 z-modal bg-black/50 md:hidden"
            initial={animate ? { opacity: 0 } : false}
            animate={{ opacity: 1 }}
            exit={animate ? { opacity: 0 } : undefined}
            onClick={onClose}
          />
          <motion.nav
            aria-label="Mobile"
            className="fixed inset-y-0 right-0 z-modal flex w-72 max-w-[85vw] flex-col gap-1 border-l border-border bg-surface p-4 md:hidden"
            initial={animate ? { x: '100%' } : false}
            animate={{ x: 0 }}
            exit={animate ? { x: '100%' } : undefined}
            transition={{ duration: 0.24, ease: [0.2, 0, 0, 1] }}
          >
            <div className="mb-2 flex items-center justify-between">
              <span className="font-display text-lg font-semibold text-fg">Menu</span>
              <button
                type="button"
                onClick={onClose}
                aria-label="Close menu"
                className="inline-flex h-9 w-9 items-center justify-center rounded-md text-fg hover:bg-surface-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              >
                <svg viewBox="0 0 20 20" className="h-5 w-5" fill="none" aria-hidden="true">
                  <path d="M5 5l10 10M15 5L5 15" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" />
                </svg>
              </button>
            </div>

            <form onSubmit={(e) => { onSearch(e); onClose(); }} role="search" className="mb-2">
              <Input name="q" type="search" aria-label="Search products" placeholder="Search products…" />
            </form>

            <NavLink to="/products" end onClick={onClose} className={navLinkClass}>
              Products
            </NavLink>
            {CATEGORIES.map((c) => (
              <NavLink key={c.key} to={`/products?class=${c.key}`} onClick={onClose} className={navLinkClass}>
                <span aria-hidden="true">{c.icon}</span> {c.label}
              </NavLink>
            ))}
            <div className="mt-2 border-t border-border pt-3">
              <AccountLink user={user} onClick={onClose} />
            </div>
          </motion.nav>
        </>
      )}
    </AnimatePresence>
  );
}
