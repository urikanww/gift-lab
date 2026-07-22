import { useCallback, useEffect, useRef, useState, type KeyboardEvent } from 'react';
import { Link, NavLink, Outlet, useNavigate } from 'react-router-dom';
import { useAuthStore } from '../stores/authStore';
import { useDashboardStore } from '../stores/dashboardStore';
import { hasPermission } from '../lib/roles';
import { Badge, Button, Logo, cn, useTheme } from '../ui';

const FOCUSABLE =
  'a[href],button:not([disabled]),textarea:not([disabled]),input:not([disabled]),select:not([disabled]),[tabindex]:not([tabindex="-1"])';

interface NavItem {
  to: string;
  label: string;
  badge?: number;
  /** Granular permission required to see this section (its `.view`). */
  permission?: string;
}

function useStaffNav(): NavItem[] {
  const q = useDashboardStore((s) => s.data?.queues);
  const pipeline = useDashboardStore((s) => s.data?.pipeline);
  const overdue = useDashboardStore((s) => s.data?.production.overdue ?? 0);
  const user = useAuthStore((s) => s.user);
  // Each order-related menu carries a count of items sitting in ITS court -
  // work staff still has to action, not things merely waiting on a buyer. Zero
  // renders no badge (see NavList), so a clear queue reads as clear.
  const items: NavItem[] = [
    // Dashboard is the console home - every staff member keeps it.
    { to: '/dashboard', label: 'Dashboard' },
    // Drafts are quotes not yet sent to the buyer - the staff action here.
    { to: '/quotes', label: 'Quotes', badge: pipeline?.DRAFT, permission: 'quotes.view' },
    { to: '/production-queue', label: 'Production', badge: overdue || undefined, permission: 'production.view' },
    { to: '/procurement', label: 'Procurement', badge: q?.procurementToReconfirm, permission: 'procurement.view' },
    { to: '/reorders', label: 'Buy-list', badge: q?.reordersOpen, permission: 'reorders.view' },
    // Products awaiting catalogue approval before they can go live.
    { to: '/product-admin', label: 'Products', badge: q?.cataloguePending, permission: 'products.view' },
    { to: '/notification-settings', label: 'Notifications', permission: 'notifications.view' },
    // Pricing and Users are sensitive: superadmin always, or a staff_admin a
    // superadmin has explicitly granted. Not part of the grandfather default.
    { to: '/pricing-admin', label: 'Pricing', permission: 'pricing.view' },
    { to: '/user-admin', label: 'Users', permission: 'users.view' },
  ];
  // Hide a section a restricted staff_admin has not been granted. Superadmin and
  // grandfathered staff pass every check (see hasPermission).
  return items.filter((it) => !it.permission || hasPermission(user, it.permission));
}

const linkClass = ({ isActive }: { isActive: boolean }) =>
  cn(
    'flex min-h-[44px] items-center justify-between gap-2 rounded-md px-3 py-2 text-sm font-medium transition-colors',
    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
    isActive ? 'bg-surface-2 text-fg' : 'text-fg-muted hover:bg-surface-2 hover:text-fg',
  );

function NavList({ onNavigate }: { onNavigate?: () => void }) {
  const items = useStaffNav();
  return (
    <nav className="flex flex-col gap-1" aria-label="Staff">
      {items.map((it) => (
        <NavLink key={it.to} to={it.to} onClick={onNavigate} className={linkClass}>
          <span>{it.label}</span>
          {it.badge ? <Badge tone="brand" size="sm">{it.badge}</Badge> : null}
        </NavLink>
      ))}
    </nav>
  );
}

export default function StaffLayout() {
  const user = useAuthStore((s) => s.user);
  const logout = useAuthStore((s) => s.logout);
  const load = useDashboardStore((s) => s.load);
  const subscribe = useDashboardStore((s) => s.subscribe);
  const unsubscribe = useDashboardStore((s) => s.unsubscribe);
  const navigate = useNavigate();
  const [drawer, setDrawer] = useState(false);

  useEffect(() => {
    void load();
    subscribe();
    return () => unsubscribe();
  }, [load, subscribe, unsubscribe]);

  const onLogout = async () => {
    await logout();
    navigate('/');
  };

  return (
    <div className="min-h-screen bg-bg md:flex">
      <aside className="hidden w-60 shrink-0 border-r border-border bg-surface md:sticky md:top-0 md:flex md:h-screen md:flex-col md:justify-between md:overflow-y-auto md:p-4">
        <div className="flex flex-col gap-6">
          <Link
            to="/dashboard"
            aria-label="GiftLab dashboard"
            className="inline-flex rounded-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          >
            <Logo />
          </Link>
          <NavList />
        </div>
        <div className="flex flex-col gap-2 border-t border-border pt-3 text-sm">
          <span className="truncate text-fg-muted">{user?.name}</span>
          <Button variant="ghost" size="sm" className="min-h-[44px] justify-start" onClick={onLogout}>
            Log out
          </Button>
        </div>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="sticky top-0 z-header flex h-14 items-center gap-3 border-b border-border bg-surface/80 px-4 backdrop-blur-md md:justify-end">
          <button
            type="button"
            className="inline-flex h-11 w-11 items-center justify-center rounded-md text-fg hover:bg-surface-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring md:hidden"
            aria-label="Open menu"
            aria-expanded={drawer}
            onClick={() => setDrawer(true)}
          >
            <svg viewBox="0 0 20 20" className="h-5 w-5" fill="none" aria-hidden="true">
              <path d="M3 6h14M3 10h14M3 14h14" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" />
            </svg>
          </button>
          <span className="font-display text-sm font-semibold text-fg md:hidden">Staff Console</span>
          <div className="ml-auto flex items-center gap-2">
            <span className="hidden text-sm text-fg-muted md:inline">{user?.name}</span>
            <ThemeToggle />
          </div>
        </header>

        <main id="main-content" className="mx-auto w-full max-w-content flex-1 px-4 py-6 sm:px-6">
          <Outlet />
        </main>
      </div>

      <StaffDrawer open={drawer} onClose={() => setDrawer(false)} onLogout={onLogout} />
    </div>
  );
}

/** Light/dark theme switch for the staff console (mirrors the storefront one). */
function ThemeToggle() {
  const { theme, toggleTheme } = useTheme();
  return (
    <button
      type="button"
      onClick={toggleTheme}
      aria-label={`Switch to ${theme === 'dark' ? 'light' : 'dark'} theme`}
      title={`Switch to ${theme === 'dark' ? 'light' : 'dark'} theme`}
      className="inline-flex h-11 w-11 items-center justify-center rounded-md text-lg text-fg-muted transition-colors hover:bg-surface-2 hover:text-fg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
    >
      <span aria-hidden="true">{theme === 'dark' ? '☀' : '☾'}</span>
    </button>
  );
}

/**
 * Mobile staff navigation drawer. Mirrors the accessible pattern in
 * SiteHeader's MobileDrawer / ui.Modal: role="dialog" aria-modal, Escape to
 * close, Tab focus trap, body scroll lock, and focus restored to the trigger.
 */
function StaffDrawer({
  open,
  onClose,
  onLogout,
}: {
  open: boolean;
  onClose: () => void;
  onLogout: () => void;
}) {
  const panelRef = useRef<HTMLDivElement>(null);
  const previouslyFocused = useRef<HTMLElement | null>(null);

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
    <div className="fixed inset-0 z-modal md:hidden" onKeyDown={handleKeyDown}>
      <div className="absolute inset-0 bg-black/50" onClick={onClose} aria-hidden="true" />
      <div
        ref={panelRef}
        role="dialog"
        aria-modal="true"
        aria-label="Staff menu"
        tabIndex={-1}
        className="absolute inset-y-0 left-0 flex w-72 max-w-[85vw] flex-col gap-4 border-r border-border bg-surface p-4 focus:outline-none"
      >
        <div className="flex items-center justify-between">
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
        <NavList onNavigate={onClose} />
        <Button variant="ghost" size="sm" className="min-h-[44px] justify-start" onClick={onLogout}>
          Log out
        </Button>
      </div>
    </div>
  );
}
