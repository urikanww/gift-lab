import { useCallback, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import api, { apiError, ensureCsrf } from '../lib/api';
import { AsyncBoundary } from '../components/ui/States';
import { Button, Card, Input, Select, useToast } from '../ui';
import { Motion, fadeInUp } from '../motion';
import { useAuthStore } from '../stores/authStore';
import type { AdminCompany, AdminUser, UserRole } from '../types';
import { ActiveBadge, RoleBadge } from './adminUserBadges';

export default function UserAdminDetailPage() {
  const { id } = useParams();
  const [user, setUser] = useState<AdminUser | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const { data } = await api.get<{ data: AdminUser }>(`/admin/users/${id}`);
      setUser(data.data);
    } catch (err) {
      setError(apiError(err));
    } finally {
      setLoading(false);
    }
  }, [id]);

  useEffect(() => {
    void load();
  }, [load]);

  return (
    <Motion variants={fadeInUp} initial="hidden" animate="visible" className="flex flex-col gap-6">
      <Link to="/user-admin" className="text-sm text-fg-muted hover:text-fg">
        &larr; Back to users
      </Link>

      <AsyncBoundary
        loading={loading}
        error={error}
        isEmpty={!user}
        emptyTitle="User not found."
        onRetry={load}
      >
        {user && <DetailBody user={user} onChanged={load} />}
      </AsyncBoundary>
    </Motion>
  );
}

function DetailBody({ user, onChanged }: { user: AdminUser; onChanged: () => void }) {
  const { toast } = useToast();
  const currentUserId = useAuthStore((s) => s.user?.id);
  const isSelf = currentUserId === user.id;
  const deactivated = !user.active;

  const [busy, setBusy] = useState(false);

  const deactivate = async () => {
    setBusy(true);
    try {
      await ensureCsrf();
      await api.delete(`/admin/users/${user.id}`);
      toast({ title: 'User deactivated', description: user.name, tone: 'success' });
      onChanged();
    } catch (err) {
      toast({ title: 'Not deactivated', description: apiError(err), tone: 'danger' });
    } finally {
      setBusy(false);
    }
  };

  const reactivate = async () => {
    setBusy(true);
    try {
      await ensureCsrf();
      await api.post(`/admin/users/${user.id}/reactivate`);
      toast({ title: 'User reactivated', description: user.name, tone: 'success' });
      onChanged();
    } catch (err) {
      toast({ title: 'Not reactivated', description: apiError(err), tone: 'danger' });
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="flex flex-col gap-6">
      {/* Header */}
      <Card padding="lg">
        <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
          <div className="min-w-0">
            <h1 className="font-display text-2xl text-fg">{user.name}</h1>
            <p className="mt-1 truncate text-sm text-fg-muted">{user.email}</p>
            <div className="mt-2 flex flex-wrap items-center gap-2">
              <RoleBadge role={user.role} />
              <ActiveBadge active={user.active} />
              <span className="text-sm text-fg-subtle">{user.company?.name ?? 'No company'}</span>
            </div>
          </div>
          <div className="flex flex-wrap gap-2">
            {!deactivated && (
              <Button
                variant="danger"
                size="sm"
                loading={busy}
                disabled={isSelf}
                onClick={() => void deactivate()}
              >
                Deactivate
              </Button>
            )}
            {deactivated && (
              <Button variant="outline" size="sm" loading={busy} onClick={() => void reactivate()}>
                Reactivate
              </Button>
            )}
          </div>
        </div>

        {isSelf && !deactivated && (
          <p className="mt-4 rounded-md border border-border bg-surface-2 px-3 py-2 text-sm text-fg-muted">
            You can&apos;t deactivate your own account or change your own role.
          </p>
        )}

        {deactivated && (
          <p className="mt-4 rounded-md border border-danger-bg bg-danger-bg/40 px-3 py-2 text-sm text-danger">
            This user is deactivated. Reactivate to edit or reset their password.
          </p>
        )}
      </Card>

      {!deactivated && <EditForm user={user} isSelf={isSelf} onChanged={onChanged} />}

      {!deactivated && <PasswordResetSection user={user} />}
    </div>
  );
}

function EditForm({
  user,
  isSelf,
  onChanged,
}: {
  user: AdminUser;
  isSelf: boolean;
  onChanged: () => void;
}) {
  const { toast } = useToast();
  const [name, setName] = useState(user.name);
  const [email, setEmail] = useState(user.email);
  const [role, setRole] = useState<UserRole>(user.role);
  const [companyId, setCompanyId] = useState(user.company ? String(user.company.id) : '');
  const [companies, setCompanies] = useState<AdminCompany[]>([]);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    let cancelled = false;
    api
      .get<{ data: AdminCompany[] }>('/admin/companies')
      .then(({ data }) => {
        if (!cancelled) setCompanies(data.data);
      })
      .catch(() => {
        // Non-critical - the picker just stays empty; backend still validates.
      });
    return () => {
      cancelled = true;
    };
  }, []);

  const save = async (e: React.FormEvent) => {
    e.preventDefault();
    if (saving) return;
    const payload: Record<string, unknown> = { name, email, role };
    if (role === 'buyer') payload.company_id = companyId ? Number(companyId) : undefined;
    setSaving(true);
    try {
      await ensureCsrf();
      await api.patch(`/admin/users/${user.id}`, payload);
      toast({ title: 'Saved', description: `${name} updated.`, tone: 'success' });
      onChanged();
    } catch (err) {
      toast({ title: 'Not saved', description: apiError(err), tone: 'danger' });
    } finally {
      setSaving(false);
    }
  };

  return (
    <Card padding="lg">
      <h2 className="mb-4 font-display text-xl text-fg">Edit details</h2>
      <form onSubmit={save} className="flex flex-col gap-4">
        <div className="grid gap-4 sm:grid-cols-2">
          <Input label="Name" value={name} onChange={(e) => setName(e.target.value)} required disabled={saving} />
          <Input
            label="Email"
            type="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            required
            disabled={saving}
          />
          <Select
            label="Role"
            value={role}
            onChange={(e) => setRole(e.target.value as UserRole)}
            disabled={saving || isSelf}
            hint={isSelf ? "You can't change your own role." : undefined}
          >
            <option value="buyer">Buyer</option>
            <option value="staff_admin">Staff admin</option>
            <option value="superadmin">Superadmin</option>
          </Select>
          {role === 'buyer' && (
            <Select
              label="Company"
              value={companyId}
              onChange={(e) => setCompanyId(e.target.value)}
              disabled={saving}
            >
              <option value="">Select a company…</option>
              {companies.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </Select>
          )}
        </div>
        <div>
          <Button type="submit" loading={saving}>
            Save changes
          </Button>
        </div>
      </form>
    </Card>
  );
}

function PasswordResetSection({ user }: { user: AdminUser }) {
  const { toast } = useToast();
  const [password, setPassword] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [fieldError, setFieldError] = useState<string | undefined>(undefined);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (submitting) return;
    if (password.length < 8) {
      setFieldError('Password must be at least 8 characters.');
      return;
    }
    setFieldError(undefined);
    setSubmitting(true);
    try {
      await ensureCsrf();
      await api.post(`/admin/users/${user.id}/password`, { password });
      toast({ title: 'Password reset', description: user.name, tone: 'success' });
      setPassword('');
    } catch (err) {
      toast({ title: 'Not reset', description: apiError(err), tone: 'danger' });
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Card padding="lg">
      <h2 className="mb-4 font-display text-xl text-fg">Reset password</h2>
      <form onSubmit={submit} className="flex flex-wrap items-end gap-2">
        <div className="w-64">
          <Input
            label="New password"
            type="password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            required
            disabled={submitting}
            error={fieldError}
            hint={!fieldError ? 'At least 8 characters.' : undefined}
          />
        </div>
        <Button type="submit" size="sm" loading={submitting}>
          Reset password
        </Button>
      </form>
    </Card>
  );
}
