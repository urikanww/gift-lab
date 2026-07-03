import { useEffect, useState } from 'react';
import { Link, NavLink, Outlet, useNavigate } from 'react-router-dom';
import { useAuthStore } from '../stores/authStore';
import { useDashboardStore } from '../stores/dashboardStore';
import { Badge, Button, cn } from '../ui';

interface NavItem {
  to: string;
  label: string;
  badge?: number;
}

function useStaffNav(): NavItem[] {
  const q = useDashboardStore((s) => s.data?.queues);
  const overdue = useDashboardStore((s) => s.data?.production.overdue ?? 0);
  return [
    { to: '/dashboard', label: 'Dashboard' },
    { to: '/quotes', label: 'Quotes', badge: q?.proofsPending },
    { to: '/production-queue', label: 'Production', badge: overdue || undefined },
    { to: '/procurement', label: 'Procurement', badge: q?.procurementToReconfirm },
    { to: '/catalogue-admin', label: 'Catalogue Gate', badge: q?.cataloguePending },
  ];
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
      <aside className="hidden w-60 shrink-0 border-r border-border bg-surface md:flex md:flex-col md:justify-between md:p-4">
        <div className="flex flex-col gap-6">
          <Link to="/dashboard" className="font-display text-xl font-semibold text-fg">
            GIFT<span className="text-primary">LAB</span>
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
          <span className="hidden text-sm text-fg-muted md:inline">{user?.name}</span>
        </header>

        <main id="main-content" className="mx-auto w-full max-w-content flex-1 px-4 py-6 sm:px-6">
          <Outlet />
        </main>
      </div>

      {drawer && (
        <div className="fixed inset-0 z-modal md:hidden">
          <div className="absolute inset-0 bg-black/50" onClick={() => setDrawer(false)} aria-hidden="true" />
          <div className="absolute inset-y-0 left-0 flex w-72 max-w-[85vw] flex-col gap-4 border-r border-border bg-surface p-4">
            <div className="flex items-center justify-between">
              <span className="font-display text-lg font-semibold text-fg">Menu</span>
              <button
                type="button"
                onClick={() => setDrawer(false)}
                aria-label="Close menu"
                className="inline-flex h-11 w-11 items-center justify-center rounded-md text-fg hover:bg-surface-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              >
                <svg viewBox="0 0 20 20" className="h-5 w-5" fill="none" aria-hidden="true">
                  <path d="M5 5l10 10M15 5L5 15" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" />
                </svg>
              </button>
            </div>
            <NavList onNavigate={() => setDrawer(false)} />
            <Button variant="ghost" size="sm" className="min-h-[44px] justify-start" onClick={onLogout}>
              Log out
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}
