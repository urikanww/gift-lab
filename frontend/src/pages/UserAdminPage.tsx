import { useCallback, useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api, { apiError } from '../lib/api';
import { AsyncBoundary } from '../components/ui/States';
import { Button, Card, Input, LinkButton, Select } from '../ui';
import ListFilters, { FilterBadges } from '../components/filters/ListFilters';
import type { FilterValues } from '../components/filters/types';
import { userFilterFields, userFiltersToParams } from '../lib/userListFilters';
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
  const [filters, setFilters] = useState<FilterValues>({});
  const [q, setQ] = useState('');
  const [debouncedQ, setDebouncedQ] = useState('');

  const fields = useMemo(() => userFilterFields(companies), [companies]);
  const params = useMemo(() => userFiltersToParams(filters), [filters]);
  const paramsKey = JSON.stringify(params);

  // Debounce the free-text search so typing doesn't fire a request per keystroke.
  useEffect(() => {
    const t = setTimeout(() => setDebouncedQ(q), 300);
    return () => clearTimeout(t);
  }, [q]);

  // Any filter/page-size change resets to page 1 (a filtered/resized set has
  // fewer pages).
  useEffect(() => {
    setPage(1);
  }, [paramsKey, debouncedQ, perPage]);

  useEffect(() => {
    let cancelled = false;
    api
      .get<{ data: AdminCompany[] }>('/admin/companies')
      .then(({ data }) => {
        if (!cancelled) setCompanies(data.data);
      })
      .catch(() => {
        // Non-critical - the filter just stays empty.
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
          q: debouncedQ || undefined,
          ...params,
        },
      });
      setUsers(data.data);
      setMeta(data.meta);
    } catch (err) {
      setError(apiError(err));
    } finally {
      setLoading(false);
    }
    // paramsKey stands in for the (stable-keyed) params object.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [page, perPage, debouncedQ, paramsKey]);

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
        <div className="flex flex-wrap items-end gap-3">
          <div className="min-w-[16rem] flex-1">
            <Input
              type="search"
              label="Search"
              placeholder="Search by name or email…"
              value={q}
              onChange={(e) => setQ(e.target.value)}
            />
          </div>
          <ListFilters fields={fields} value={filters} onChange={setFilters} />
          <Select
            label="Per page"
            className="w-28"
            value={String(perPage)}
            onChange={(e) => setPerPage(Number(e.target.value))}
          >
            {PER_PAGE_OPTIONS.map((n) => (
              <option key={n} value={n}>
                {n}
              </option>
            ))}
          </Select>
        </div>
        <FilterBadges fields={fields} value={filters} onChange={setFilters} />
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
                      <p className="text-sm text-fg-muted">{u.company?.name ?? '-'}</p>
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
