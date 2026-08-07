import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import api, { apiError, ensureCsrf } from '../lib/api';
import { Button, Card, Input, Select, useToast } from '../ui';
import { Motion, fadeInUp } from '../motion';
import type { UserRole } from '../types';

/**
 * Standalone "add a user" page (route /user-admin/new, superadmin-only). On
 * success it hands off to the detail page.
 */
export default function UserAdminCreatePage() {
  const { toast } = useToast();
  const navigate = useNavigate();

  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [role, setRole] = useState<UserRole>('staff_admin');
  const [submitting, setSubmitting] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  const validate = (): boolean => {
    const errors: Record<string, string> = {};
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      errors.email = 'Enter a valid email address.';
    }
    if (password.length < 8) {
      errors.password = 'Password must be at least 8 characters.';
    }
    setFieldErrors(errors);
    return Object.keys(errors).length === 0;
  };

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (submitting) return;
    if (!validate()) return;
    setSubmitting(true);
    try {
      await ensureCsrf();
      const payload: Record<string, unknown> = { name, email, password, role };
      const { data } = await api.post<{ data: { id: number } }>('/admin/users', payload);
      toast({ title: 'User created', description: name, tone: 'success' });
      navigate(`/user-admin/${data.data.id}`);
    } catch (err) {
      toast({ title: 'Not created', description: apiError(err), tone: 'danger' });
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Motion variants={fadeInUp} initial="hidden" animate="visible" className="flex flex-col gap-6">
      <header className="flex flex-col gap-1">
        <Link to="/user-admin" className="text-sm text-fg-muted hover:text-fg">
          &larr; Back to users
        </Link>
        <h1 className="font-display text-3xl text-fg">Add a user</h1>
        <p className="text-sm text-fg-muted">Create a staff admin or superadmin account.</p>
      </header>

      <Card padding="lg" aria-labelledby="create-user-heading">
        <h2 id="create-user-heading" className="mb-4 font-display text-xl text-fg">
          Details
        </h2>
        <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
          <Input label="Name" value={name} onChange={(e) => setName(e.target.value)} required disabled={submitting} />
          <Input
            label="Email"
            type="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            required
            disabled={submitting}
            error={fieldErrors.email}
          />
          <Input
            label="Password"
            type="password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            required
            disabled={submitting}
            error={fieldErrors.password}
            hint={!fieldErrors.password ? 'At least 8 characters.' : undefined}
          />
          <Select
            label="Role"
            value={role}
            onChange={(e) => setRole(e.target.value as UserRole)}
            disabled={submitting}
          >
            <option value="staff_admin">Staff admin</option>
            <option value="superadmin">Superadmin</option>
          </Select>
          <div className="flex items-end sm:col-span-2">
            <Button type="submit" loading={submitting}>
              Create user
            </Button>
          </div>
        </form>
      </Card>
    </Motion>
  );
}
