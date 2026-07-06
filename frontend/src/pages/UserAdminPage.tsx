import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api, { apiError } from '../lib/api';
import { AsyncBoundary } from '../components/ui/States';
import { Button, Card, Input, LinkButton, Select } from '../ui';
import { Motion, fadeInUp } from '../motion';
import type { AdminCompany, AdminUser } from '../types';
import { ActiveBadge, RoleBadge } from './adminUserBadges';

interface Meta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

const PER_PAGE_OPTIONS = [15, 30, 50, 100] as const;

/**
 * Server-driven user browser (route /user-admin, superadmin-only). All
 * filtering and pagination happen on the API; this page just reflects the
 * query state. Create and edit live on their own pages (/user-admin/new, /:id).
 */
export default function UserAdminPage() {
  const navigate = useNavigate();

  const [users, setUsers] = useState<AdminUser[]>([]);
  const [meta, setMeta] = useState<Meta | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [companies, setCompanies] = useState<AdminCompany[]>([]);

  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(15);
  const [status, setStatus] = useState<'active' | 'deactivated' | 'all'>('active');
  const [role, setRole] = useState('');
  const [company, setCompany] = useState('');
  const [q, setQ] = useState('');
  const [debouncedQ, setDebouncedQ] = useState('');

  // Debounce the free-text search so typing doesn't fire a request per keystroke.
  useEffect(() => {
    const t = setTimeout(() => setDebouncedQ(q), 300);
    return () => clearTimeout(t);
  }, [q]);

  // Any filter/page-size change resets to page 1 (a filtered/resized set has
  // fewer pages).
  useEffect(() => {
    setPage(1);
  }, [status, role, company, debouncedQ, perPage]);

  useEffect(() => {
    let cancelled = false;
    api
      .get<{ data: AdminCompany[] }>('/admin/companies')
      .then(({ data }) => {
        if (!cancelled) setCompanies(data.data);
      })
      .catch(() => {
        // Non-critical — the filter just stays empty.
      });
    return () => {
      cancelled = true;
    };
  }, []);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const { data } = await api.get<{ data: AdminUser[]; meta: Meta }>('/admin/users', {
        params: {
          page,
          per_page: perPage,
          status,
          role: role || undefined,
          company: company || undefined,
          q: debouncedQ || undefined,
        },
      });
      setUsers(data.data);
      setMeta(data.meta);
    } catch (err) {
      setError(apiError(err));
    } finally {
      setLoading(false);
    }
  }, [page, perPage, status, role, company, debouncedQ]);

  useEffect(() => {
    void load();
  }, [load]);

  const rangeLabel = meta ? `Page ${meta.current_page} of ${meta.last_page} · ${meta.total} total` : '';

  return (
    <Motion variants={fadeInUp} initial="hidden" animate="visible" className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <h1 className="font-display text-3xl text-fg">Users</h1>
          <p className="mt-1 max-w-xl text-sm text-fg-muted">
            Manage buyer, staff, and superadmin accounts. Deactivate to revoke access without
            deleting history.
          </p>
        </div>
        <LinkButton to="/user-admin/new">New user</LinkButton>
      </header>

      {/* Controls */}
      <Card padding="lg" className="flex flex-col gap-4">
        <Input
          label="Search"
          placeholder="Search by name or email…"
          value={q}
          onChange={(e) => setQ(e.target.value)}
        />
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <Select label="Role" value={role} onChange={(e) => setRole(e.target.value)}>
            <option value="">All roles</option>
            <option value="buyer">Buyer</option>
            <option value="staff_admin">Staff admin</option>
            <option value="superadmin">Superadmin</option>
          </Select>
          <Select label="Company" value={company} onChange={(e) => setCompany(e.target.value)}>
            <option value="">All companies</option>
            {companies.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </Select>
          <Select
            label="Status"
            value={status}
            onChange={(e) => setStatus(e.target.value as 'active' | 'deactivated' | 'all')}
          >
            <option value="active">Active</option>
            <option value="deactivated">Deactivated</option>
            <option value="all">All</option>
          </Select>
          <Select label="Per page" value={String(perPage)} onChange={(e) => setPerPage(Number(e.target.value))}>
            {PER_PAGE_OPTIONS.map((n) => (
              <option key={n} value={n}>
                {n}
              </option>
            ))}
          </Select>
        </div>
      </Card>

      <AsyncBoundary
        loading={loading}
        error={error}
        isEmpty={users.length === 0}
        emptyTitle="No users match these filters."
        onRetry={load}
      >
        <Card padding="none" className="overflow-hidden">
          <div className="overflow-x-auto">
            <ul className="flex min-w-[40rem] flex-col divide-y divide-border">
              {users.map((u) => (
                <li key={u.id}>
                  <button
                    type="button"
                    onClick={() => navigate(`/user-admin/${u.id}`)}
                    className="flex w-full items-center gap-4 px-4 py-3 text-left transition-colors hover:bg-surface-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                  >
                    <div className="min-w-0 flex-1">
                      <p className="block w-full truncate font-medium text-fg">{u.name}</p>
                      <p className="block w-full truncate text-sm text-fg-muted">{u.email}</p>
                      <div className="mt-1 flex flex-wrap items-center gap-2">
                        <RoleBadge role={u.role} />
                        <ActiveBadge active={u.active} />
                      </div>
                    </div>
                    <div className="shrink-0 text-right">
                      <p className="text-sm text-fg-muted">{u.company?.name ?? '—'}</p>
                    </div>
                  </button>
                </li>
              ))}
            </ul>
          </div>
        </Card>
      </AsyncBoundary>

      {/* Pagination */}
      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between gap-4">
          <span className="text-sm text-fg-muted">{rangeLabel}</span>
          <div className="flex gap-2">
            <Button
              variant="outline"
              size="sm"
              disabled={loading || meta.current_page <= 1}
              onClick={() => setPage((n) => Math.max(1, n - 1))}
            >
              Prev
            </Button>
            <Button
              variant="outline"
              size="sm"
              disabled={loading || meta.current_page >= meta.last_page}
              onClick={() => setPage((n) => n + 1)}
            >
              Next
            </Button>
          </div>
        </div>
      )}
    </Motion>
  );
}
