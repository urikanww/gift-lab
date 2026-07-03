import {
  useCallback,
  useEffect,
  useRef,
  useState,
  type FormEvent,
  type KeyboardEvent,
} from 'react';
import { Link, NavLink, useNavigate } from 'react-router-dom';
import { useAuthStore } from '../stores/authStore';
import { useCartStore } from '../stores/cartStore';
import { CATEGORIES } from '../lib/categories';
import { isStaffRole } from '../lib/roles';
import { Badge, Button, Input, useTheme, cn } from '../ui';
import type { User } from '../types';

const FOCUSABLE =
  'a[href],button:not([disabled]),textarea:not([disabled]),input:not([disabled]),select:not([disabled]),[tabindex]:not([tabindex="-1"])';

const navLinkClass = ({ isActive }: { isActive: boolean }) =>
  cn(
    'flex min-h-[44px] items-center rounded-md px-3 py-2 text-sm font-medium transition-colors duration-fast',
    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
    isActive ? 'bg-surface-2 text-fg' : 'text-fg-muted hover:bg-surface-2 hover:text-fg',
  );

export default function SiteHeader() {
  const user = useAuthStore((s) => s.user);
  const logout = useAuthStore((s) => s.logout);
  const cartCount = useCartStore((s) => s.lines.length);
  const navigate = useNavigate();
  const [drawerOpen, setDrawerOpen] = useState(false);

  const onSearch = (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const value = new FormData(e.currentTarget).get('q')?.toString().trim() ?? '';
    if (value) navigate(`/products?q=${encodeURIComponent(value)}`);
  };

  const onLogout = async () => {
    await logout();
    navigate('/');
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
          <CategoriesMenu />
          <NavLink to="/kits" className={navLinkClass}>
            Build a kit
          </NavLink>
          <NavLink to="/track" className={navLinkClass}>
            Track order
          </NavLink>
          {user && !isStaffRole(user.role) && (
            <NavLink to="/brand-kit" className={navLinkClass}>
              Brand kit
            </NavLink>
          )}
          {isStaffRole(user?.role) && (
            <>
              <span className="mx-1 h-5 w-px bg-border" aria-hidden="true" />
              <NavLink to="/catalogue-admin" className={navLinkClass}>
                Catalogue gate
              </NavLink>
              <NavLink to="/production-queue" className={navLinkClass}>
                Production
              </NavLink>
              <NavLink to="/procurement" className={navLinkClass}>
                Procurement
              </NavLink>
            </>
          )}
        </nav>

        <form onSubmit={onSearch} role="search" className="hidden lg:block lg:w-56">
          <Input name="q" type="search" aria-label="Search products" placeholder="Search products…" />
        </form>

        <div className="ml-auto flex items-center gap-2">
          <ThemeToggle />
          <CartLink count={cartCount} />
          <div className="hidden md:flex md:items-center md:gap-1">
            <AccountLink user={user} />
            {user && (
              <Button variant="ghost" size="sm" onClick={onLogout}>
                Log out
              </Button>
            )}
          </div>

          <button
            type="button"
            className="inline-flex h-11 w-11 items-center justify-center rounded-md text-fg hover:bg-surface-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring md:hidden"
            aria-label="Open menu"
            aria-expanded={drawerOpen}
            onClick={() => setDrawerOpen(true)}
          >
            <svg viewBox="0 0 20 20" className="h-5 w-5" fill="none" aria-hidden="true">
              <path d="M3 6h14M3 10h14M3 14h14" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" />
            </svg>
          </button>
        </div>
      </div>

      <MobileDrawer
        open={drawerOpen}
        onClose={() => setDrawerOpen(false)}
        user={user}
        onSearch={onSearch}
        onLogout={onLogout}
      />
    </header>
  );
}

function CategoriesMenu() {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);
  const buttonRef = useRef<HTMLButtonElement>(null);

  // Close on any click outside the menu (standard disclosure pattern). Focus
  // is NOT restored here — the user clicked elsewhere on purpose.
  useEffect(() => {
    if (!open) return;
    const onDocClick = (e: MouseEvent) => {
      if (!ref.current?.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', onDocClick);
    return () => document.removeEventListener('mousedown', onDocClick);
  }, [open]);

  return (
    <div
      ref={ref}
      className="relative"
      onKeyDown={(e) => {
        if (e.key === 'Escape') {
          setOpen(false);
          // Keyboard dismissal returns focus to the trigger (mirrors Modal).
          buttonRef.current?.focus();
        }
      }}
    >
      <button
        ref={buttonRef}
        type="button"
        aria-expanded={open}
        aria-haspopup="true"
        onClick={() => setOpen((o) => !o)}
        className={cn(
          'flex min-h-[44px] items-center rounded-md px-3 py-2 text-sm font-medium transition-colors duration-fast',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
          open ? 'bg-surface-2 text-fg' : 'text-fg-muted hover:bg-surface-2 hover:text-fg',
        )}
      >
        Categories <span aria-hidden="true">▾</span>
      </button>
      {open && (
        <div className="absolute left-0 top-full z-dropdown mt-1 grid w-[28rem] grid-cols-2 gap-1 rounded-lg border border-border bg-surface p-2 shadow-lg">
          {CATEGORIES.map((c) => (
            <Link
              key={c.key}
              to={`/products?category=${c.key}`}
              onClick={() => setOpen(false)}
              className="flex items-start gap-2.5 rounded-md px-3 py-2 hover:bg-surface-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
              <span aria-hidden="true" className="text-lg leading-6">
                {c.icon}
              </span>
              <span>
                <span className="block text-sm font-medium text-fg">{c.label}</span>
                <span className="block text-xs text-fg-muted">{c.blurb}</span>
              </span>
            </Link>
          ))}
        </div>
      )}
    </div>
  );
}

function ThemeToggle() {
  const { theme, toggleTheme } = useTheme();
  return (
    <button
      type="button"
      onClick={toggleTheme}
      aria-label="Toggle theme"
      className="inline-flex h-11 w-11 items-center justify-center rounded-md text-lg text-fg-muted transition-colors hover:bg-surface-2 hover:text-fg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
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
          'relative inline-flex h-11 w-11 items-center justify-center rounded-md transition-colors',
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

function AccountLink({ user, onClick }: { user: User | null; onClick?: () => void }) {
  return user ? (
    <NavLink to="/quotes" onClick={onClick} className={navLinkClass}>
      {isStaffRole(user.role) ? 'Quotes' : 'My Orders'}
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
  onLogout,
}: {
  open: boolean;
  onClose: () => void;
  user: User | null;
  onSearch: (e: FormEvent<HTMLFormElement>) => void;
  onLogout: () => void;
}) {
  const panelRef = useRef<HTMLElement>(null);
  const previouslyFocused = useRef<HTMLElement | null>(null);

  // Escape to close + Tab focus trap (mirrors ui/Modal.tsx).
  const handleKeyDown = useCallback(
    (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        e.stopPropagation();
        onClose();
        return;
      }
      if (e.key === 'Tab') {
        const nodes = panelRef.current?.querySelectorAll<HTMLElement>(FOCUSABLE);
        if (!nodes || nodes.length === 0) {
          e.preventDefault();
          return;
        }
        const first = nodes[0];
        const last = nodes[nodes.length - 1];
        const active = document.activeElement;
        if (e.shiftKey && active === first) {
          e.preventDefault();
          last.focus();
        } else if (!e.shiftKey && active === last) {
          e.preventDefault();
          first.focus();
        }
      }
    },
    [onClose],
  );

  // On open: remember trigger, lock body scroll, move focus in. On close: restore.
  useEffect(() => {
    if (!open) return;
    previouslyFocused.current = document.activeElement as HTMLElement | null;
    const { overflow } = document.body.style;
    document.body.style.overflow = 'hidden';

    const raf = requestAnimationFrame(() => {
      const nodes = panelRef.current?.querySelectorAll<HTMLElement>(FOCUSABLE);
      (nodes && nodes.length ? nodes[0] : panelRef.current)?.focus();
    });

    return () => {
      cancelAnimationFrame(raf);
      document.body.style.overflow = overflow;
      previouslyFocused.current?.focus?.();
    };
  }, [open]);

  if (!open) return null;

  return (
    <div className="md:hidden" onKeyDown={handleKeyDown}>
      <div
        className="fixed inset-0 z-modal bg-black/50 motion-safe:animate-fadeIn"
        onClick={onClose}
        aria-hidden="true"
      />
      <nav
        ref={panelRef}
        aria-label="Mobile"
        tabIndex={-1}
        className="fixed inset-y-0 right-0 z-modal flex w-72 max-w-[85vw] translate-x-0 flex-col gap-1 border-l border-border bg-surface p-4 focus:outline-none motion-safe:animate-drawerIn"
      >
            <div className="mb-2 flex items-center justify-between">
              <span className="font-display text-lg font-semibold text-fg">Menu</span>
              <button
                type="button"
                onClick={onClose}
                aria-label="Close menu"
                className="inline-flex h-11 w-11 items-center justify-center rounded-md text-fg hover:bg-surface-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
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
              <NavLink key={c.key} to={`/products?category=${c.key}`} onClick={onClose} className={navLinkClass}>
                <span aria-hidden="true">{c.icon}</span> {c.label}
              </NavLink>
            ))}
            <NavLink to="/kits" onClick={onClose} className={navLinkClass}>
              Build a kit
            </NavLink>
            <NavLink to="/track" onClick={onClose} className={navLinkClass}>
              Track order
            </NavLink>
            <div className="mt-2 flex flex-col gap-1 border-t border-border pt-3">
              {user && !isStaffRole(user.role) && (
                <NavLink to="/brand-kit" onClick={onClose} className={navLinkClass}>
                  Brand kit
                </NavLink>
              )}
              {isStaffRole(user?.role) && (
                <>
                  <NavLink to="/catalogue-admin" onClick={onClose} className={navLinkClass}>
                    Catalogue gate
                  </NavLink>
                  <NavLink to="/production-queue" onClick={onClose} className={navLinkClass}>
                    Production
                  </NavLink>
                  <NavLink to="/procurement" onClick={onClose} className={navLinkClass}>
                    Procurement
                  </NavLink>
                </>
              )}
              <AccountLink user={user} onClick={onClose} />
              {user && (
                <Button
                  variant="ghost"
                  size="sm"
                  fullWidth
                  onClick={() => {
                    onClose();
                    onLogout();
                  }}
                >
                  Log out
                </Button>
              )}
            </div>
      </nav>
    </div>
  );
}
