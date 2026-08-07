import { useCallback, useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api, { apiError } from '../lib/api';
import { AsyncBoundary } from '../components/ui/States';
import { Button, Card, Input, LinkButton, Select, cn } from '../ui';
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

const ROLE_TABS = [
  { value: '', label: 'All' },
  { value: 'buyer', label: 'Buyers' },
  { value: 'staff_admin', label: 'Staff admin' },
  { value: 'superadmin', label: 'Superadmin' },
] as const;

const STATUS_TABS = [
  { value: 'active', label: 'Active' },
  { value: 'deactivated', label: 'Deactivated' },
  { value: 'all', label: 'All' },
] as const;

type SortKey = 'name' | 'created';
type SortDir = 'asc' | 'desc';

function Segmented<T extends string>({
  options,
  value,
  onChange,
  ariaLabel,
}: {
  options: readonly { value: T; label: string }[];
  value: T;
  onChange: (v: T) => void;
  ariaLabel: string;
}) {
  return (
    <div role="group" aria-label={ariaLabel} className="inline-flex flex-wrap gap-1 rounded-lg bg-surface-2 p-1">
      {options.map((o) => (
        <button
          key={o.value}
          type="button"
          aria-pressed={value === o.value}
          onClick={() => onChange(o.value)}
          className={cn(
            'rounded-md px-3 py-1.5 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
            value === o.value ? 'bg-surface text-fg shadow-sm' : 'text-fg-muted hover:text-fg',
          )}
        >
          {o.label}
        </button>
      ))}
    </div>
  );
}

function SortCaret({ active, dir }: { active: boolean; dir: SortDir }) {
  if (!active) return <span aria-hidden="true" className="ml-1 text-fg-subtle">↕</span>;
  return <span aria-hidden="true" className="ml-1 text-fg">{dir === 'asc' ? '↑' : '↓'}</span>;
}

export default function UserAdminPage() {
  const navigate = useNavigate();

  const [users, setUsers] = useState<AdminUser[]>([]);
  const [meta, setMeta] = useState<Meta | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [companies, setCompanies] = useState<AdminCompany[]>([]);

  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(15);

  const [role, setRole] = useState('');
  const [status, setStatus] = useState('active');
  const [sortKey, setSortKey] = useState<SortKey>('name');
  const [sortDir, setSortDir] = useState<SortDir>('asc');

  const [filters, setFilters] = useState<FilterValues>({});
  const [q, setQ] = useState('');
  const [debouncedQ, setDebouncedQ] = useState('');

  const fields = useMemo(() => userFilterFields(companies), [companies]);
  const popupParams = useMemo(() => userFiltersToParams(filters), [filters]);
  const sort = `${sortKey}_${sortDir}`;
  const paramsKey = JSON.stringify({ ...popupParams, role, status, sort });

  useEffect(() => {
    const t = setTimeout(() => setDebouncedQ(q), 300);
    return () => clearTimeout(t);
  }, [q]);

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
        // Non-critical - the company filter just stays empty.
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
          role: role || undefined,
          status,
          sort,
          ...popupParams,
        },
      });
      setUsers(data.data);
      setMeta(data.meta);
    } catch (err) {
      setError(apiError(err));
    } finally {
      setLoading(false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [page, perPage, debouncedQ, paramsKey]);

  useEffect(() => {
    void load();
  }, [load]);

  const toggleSort = (key: SortKey) => {
    if (sortKey === key) {
      setSortDir((d) => (d === 'asc' ? 'desc' : 'asc'));
    } else {
      setSortKey(key);
      setSortDir('asc');
    }
  };

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

      <div className="flex flex-wrap items-center gap-3">
        <Segmented options={ROLE_TABS} value={role} onChange={setRole} ariaLabel="Filter by role" />
        <Segmented options={STATUS_TABS} value={status} onChange={setStatus} ariaLabel="Filter by status" />
      </div>

      <Card padding="lg" className="flex flex-col gap-4">
        <div className="flex flex-wrap items-end gap-3">
          <div className="min-w-[16rem] flex-1">
            <Input
              type="search"
              label="Search"
              placeholder="Search name, email, company, or id…"
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
          <div className="hidden overflow-x-auto md:block">
            <table className="w-full min-w-[44rem] text-left text-sm">
              <thead className="border-b border-border text-xs uppercase tracking-wide text-fg-subtle">
                <tr>
                  <th className="px-4 py-2 font-medium">
                    <button type="button" onClick={() => toggleSort('name')} className="inline-flex items-center hover:text-fg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
                      Name<SortCaret active={sortKey === 'name'} dir={sortDir} />
                    </button>
                  </th>
                  <th className="px-4 py-2 font-medium">Role</th>
                  <th className="px-4 py-2 font-medium">Company</th>
                  <th className="px-4 py-2 font-medium">
                    <button type="button" onClick={() => toggleSort('created')} className="inline-flex items-center hover:text-fg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
                      Joined<SortCaret active={sortKey === 'created'} dir={sortDir} />
                    </button>
                  </th>
                  <th className="px-4 py-2 font-medium">Status</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {users.map((u) => (
                  <tr
                    key={u.id}
                    onClick={() => navigate(`/user-admin/${u.id}`)}
                    className="cursor-pointer transition-colors hover:bg-surface-2"
                  >
                    <td className="px-4 py-3">
                      <p className="font-medium text-fg">{u.name}</p>
                      <p className="text-sm text-fg-muted">{u.email}</p>
                    </td>
                    <td className="px-4 py-3"><RoleBadge role={u.role} /></td>
                    <td className="px-4 py-3 text-fg-muted">{u.company?.name ?? '-'}</td>
                    <td className="px-4 py-3 text-fg-muted">{new Date(u.created_at).toLocaleDateString()}</td>
                    <td className="px-4 py-3"><ActiveBadge active={u.active} /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <ul className="flex flex-col divide-y divide-border md:hidden">
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
        </Card>
      </AsyncBoundary>

      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between gap-4">
          <span className="text-sm text-fg-muted">{rangeLabel}</span>
          <div className="flex gap-2">
            <Button variant="outline" size="sm" disabled={loading || meta.current_page <= 1} onClick={() => setPage((n) => Math.max(1, n - 1))}>
              Prev
            </Button>
            <Button variant="outline" size="sm" disabled={loading || meta.current_page >= meta.last_page} onClick={() => setPage((n) => n + 1)}>
              Next
            </Button>
          </div>
        </div>
      )}
    </Motion>
  );
}
