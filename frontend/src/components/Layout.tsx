import { useState } from 'react';
import { Link, NavLink, useNavigate } from 'react-router-dom';
import { motion, AnimatePresence } from 'framer-motion';
import { AnimatedOutlet } from './AnimatedOutlet';
import { useAuthStore } from '../stores/authStore';
import { useCartStore } from '../stores/cartStore';
import { isStaffRole } from '../lib/roles';
import { Button, Badge, useTheme, cn } from '../ui';
import { useReducedMotionSafe } from '../motion';

interface NavItem {
  to: string;
  label: string;
  end?: boolean;
}

export default function Layout() {
  const { user, logout } = useAuthStore();
  const cartCount = useCartStore((s) => s.lines.length);
  const navigate = useNavigate();
  const [mobileOpen, setMobileOpen] = useState(false);
  const animate = useReducedMotionSafe();

  const onLogout = async () => {
    await logout();
    navigate('/');
  };

  const buyerNav: NavItem[] = [{ to: '/', label: 'Catalogue', end: true }];
  const staffNav: NavItem[] = isStaffRole(user?.role)
    ? [
        { to: '/production-queue', label: 'Queue' },
        { to: '/procurement', label: 'Procurement' },
        { to: '/catalogue-admin', label: 'Manage' },
      ]
    : [];
  const authedNav: NavItem[] = user ? [{ to: '/quotes', label: 'Quotes' }] : [];
  const navItems = [...buyerNav, ...authedNav, ...staffNav];

  return (
    <div className="min-h-screen bg-bg">
      <a
        href="#main-content"
        className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-toast focus:rounded-md focus:bg-primary focus:px-4 focus:py-2 focus:text-primary-fg"
      >
        Skip to content
      </a>

      <header className="sticky top-0 z-header border-b border-border bg-surface/80 backdrop-blur-md">
        <div className="mx-auto flex h-16 max-w-content items-center gap-6 px-4 sm:px-6">
          <Link to="/" className="group flex items-center gap-2" aria-label="Gift Lab home">
            <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-primary text-primary-fg shadow-xs">
              <svg viewBox="0 0 20 20" className="h-4 w-4" fill="none" aria-hidden="true">
                <path
                  d="M10 2 3 6v8l7 4 7-4V6l-7-4Zm0 0v16M3 6l7 4 7-4"
                  stroke="currentColor"
                  strokeWidth="1.5"
                  strokeLinejoin="round"
                />
              </svg>
            </span>
            <span className="font-display text-xl font-medium tracking-tight text-fg">Gift Lab</span>
          </Link>

          {/* Desktop nav */}
          <nav className="hidden flex-1 items-center gap-1 md:flex" aria-label="Primary">
            {navItems.map((item) => (
              <NavItemLink key={item.to} item={item} />
            ))}
          </nav>

          <div className="ml-auto flex items-center gap-2 md:ml-0">
            <ThemeToggle />
            <CartLink count={cartCount} />
            <div className="hidden items-center gap-2 md:flex">
              {user ? (
                <>
                  <span className="max-w-[10rem] truncate text-sm text-fg-muted">{user.name}</span>
                  <Button variant="ghost" size="sm" onClick={onLogout}>
                    Log out
                  </Button>
                </>
              ) : (
                <Button variant="outline" size="sm" onClick={() => navigate('/login')}>
                  Log in
                </Button>
              )}
            </div>

            {/* Mobile menu toggle */}
            <button
              type="button"
              className="inline-flex h-9 w-9 items-center justify-center rounded-md text-fg hover:bg-surface-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring md:hidden"
              aria-label={mobileOpen ? 'Close menu' : 'Open menu'}
              aria-expanded={mobileOpen}
              onClick={() => setMobileOpen((o) => !o)}
            >
              <svg viewBox="0 0 20 20" className="h-5 w-5" fill="none" aria-hidden="true">
                {mobileOpen ? (
                  <path d="M5 5l10 10M15 5L5 15" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" />
                ) : (
                  <path d="M3 6h14M3 10h14M3 14h14" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" />
                )}
              </svg>
            </button>
          </div>
        </div>

        {/* Mobile nav drawer */}
        <AnimatePresence>
          {mobileOpen && (
            <motion.nav
              aria-label="Primary mobile"
              initial={animate ? { height: 0, opacity: 0 } : false}
              animate={{ height: 'auto', opacity: 1 }}
              exit={animate ? { height: 0, opacity: 0 } : undefined}
              transition={{ duration: 0.22, ease: [0.2, 0, 0, 1] }}
              className="overflow-hidden border-t border-border bg-surface md:hidden"
            >
              <div className="flex flex-col gap-1 px-4 py-3">
                {navItems.map((item) => (
                  <NavItemLink key={item.to} item={item} onClick={() => setMobileOpen(false)} mobile />
                ))}
                <div className="mt-2 border-t border-border pt-3">
                  {user ? (
                    <div className="flex items-center justify-between">
                      <span className="truncate text-sm text-fg-muted">{user.name}</span>
                      <Button variant="ghost" size="sm" onClick={onLogout}>
                        Log out
                      </Button>
                    </div>
                  ) : (
                    <Button
                      variant="outline"
                      size="sm"
                      fullWidth
                      onClick={() => {
                        setMobileOpen(false);
                        navigate('/login');
                      }}
                    >
                      Log in
                    </Button>
                  )}
                </div>
              </div>
            </motion.nav>
          )}
        </AnimatePresence>
      </header>

      <main id="main-content" className="mx-auto max-w-content px-4 py-8 sm:px-6 sm:py-10">
        <AnimatedOutlet />
      </main>

      <footer className="mx-auto max-w-content px-4 pb-10 pt-6 sm:px-6">
        <p className="text-xs text-fg-subtle">Gift Lab — custom gifting, crafted to order.</p>
      </footer>
    </div>
  );
}

function NavItemLink({ item, onClick, mobile }: { item: NavItem; onClick?: () => void; mobile?: boolean }) {
  return (
    <NavLink
      to={item.to}
      end={item.end}
      onClick={onClick}
      className={({ isActive }) =>
        cn(
          'rounded-md px-3 py-2 text-sm font-medium transition-colors duration-fast',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
          mobile && 'block',
          isActive ? 'bg-surface-2 text-fg' : 'text-fg-muted hover:bg-surface-2 hover:text-fg',
        )
      }
    >
      {item.label}
    </NavLink>
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

function ThemeToggle() {
  const { theme, toggleTheme } = useTheme();
  return (
    <button
      type="button"
      onClick={toggleTheme}
      aria-label={`Switch to ${theme === 'light' ? 'dark' : 'light'} theme`}
      className="inline-flex h-9 w-9 items-center justify-center rounded-md text-fg-muted transition-colors hover:bg-surface-2 hover:text-fg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
    >
      {theme === 'light' ? (
        <svg viewBox="0 0 20 20" className="h-5 w-5" fill="none" aria-hidden="true">
          <path
            d="M16 11.5A6 6 0 1 1 8.5 4a5 5 0 0 0 7.5 7.5Z"
            stroke="currentColor"
            strokeWidth="1.4"
            strokeLinejoin="round"
          />
        </svg>
      ) : (
        <svg viewBox="0 0 20 20" className="h-5 w-5" fill="none" aria-hidden="true">
          <circle cx="10" cy="10" r="3.5" stroke="currentColor" strokeWidth="1.4" />
          <path
            d="M10 1.5v2M10 16.5v2M1.5 10h2M16.5 10h2M4 4l1.4 1.4M14.6 14.6 16 16M16 4l-1.4 1.4M5.4 14.6 4 16"
            stroke="currentColor"
            strokeWidth="1.4"
            strokeLinecap="round"
          />
        </svg>
      )}
    </button>
  );
}
