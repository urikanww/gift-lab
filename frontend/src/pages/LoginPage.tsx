import { useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { useAuthStore } from '../stores/authStore';
import { isStaffRole } from '../lib/roles';
import { Button, Card, Input } from '../ui';
import { Motion, fadeInUp, staggerContainer, staggerItem } from '../motion';

interface LocationState {
  from?: string;
}

export default function LoginPage() {
  const { login, error } = useAuthStore();
  const navigate = useNavigate();
  const location = useLocation();
  const from = (location.state as LocationState | null)?.from;

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (submitting) return;
    setSubmitting(true);
    const ok = await login(email, password);
    setSubmitting(false);
    if (ok) {
      // Role-aware landing: staff manage the catalogue gate; buyers see their
      // quotes. An explicit `from` (bounced off a protected route) still wins.
      const role = useAuthStore.getState().user?.role;
      navigate(from ?? (isStaffRole(role) ? '/dashboard' : '/quotes'), { replace: true });
    }
  };

  return (
    <div className="mx-auto flex min-h-[70vh] w-full max-w-md flex-col justify-center px-1 py-10">
      <Motion variants={staggerContainer} initial="hidden" animate="visible">
        {/* Brand mark + welcome copy */}
        <Motion variants={staggerItem} className="mb-8 text-center">
          <span className="inline-flex items-center gap-2 rounded-full bg-brand-100 px-3 py-1 text-2xs font-semibold uppercase tracking-wide text-brand-700">
            <span className="h-1.5 w-1.5 rounded-full bg-brand-500" aria-hidden="true" />
            Gift-Lab
          </span>
          <h1 className="mt-5 font-display text-3xl text-fg sm:text-4xl">Welcome back</h1>
          <p className="mt-2 text-sm text-fg-muted">
            Sign in to manage quotes, proofs and production.
          </p>
        </Motion>

        <Motion variants={staggerItem}>
          <Card padding="lg" className="shadow-md">
            <form onSubmit={submit} className="flex flex-col gap-5" noValidate>
              <Input
                type="email"
                label="Email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                required
                autoComplete="email"
                autoFocus
                placeholder="you@company.com"
                disabled={submitting}
              />
              <Input
                type="password"
                label="Password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                required
                autoComplete="current-password"
                placeholder="••••••••"
                disabled={submitting}
              />

              {error && (
                <Motion
                  variants={fadeInUp}
                  initial="hidden"
                  animate="visible"
                  className="rounded-md border border-danger/30 bg-danger-bg px-3 py-2 text-sm text-danger"
                  role="alert"
                >
                  {error}
                </Motion>
              )}

              <Button type="submit" fullWidth size="lg" loading={submitting}>
                {submitting ? 'Signing in…' : 'Sign in'}
              </Button>
            </form>
          </Card>
        </Motion>

        <Motion variants={staggerItem} className="mt-6 text-center text-xs text-fg-subtle">
          Staff and buyer access is provisioned by your account manager.
        </Motion>
      </Motion>
    </div>
  );
}
