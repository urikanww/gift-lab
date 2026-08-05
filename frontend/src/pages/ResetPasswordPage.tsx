import { useState } from 'react';
import { Link, Navigate, useNavigate, useSearchParams } from 'react-router-dom';
import { useAuthStore } from '../stores/authStore';
import { Button, Input } from '../ui';
import { AuthLayout } from '../components/AuthLayout';
import { Motion, fadeInUp, staggerItem } from '../motion';

/**
 * Reset password: the emailed link carries only an opaque token (?token=...),
 * so the form re-collects the email (no PII in the URL) plus the new password.
 * On success it bounces to /login with a success flag the login page surfaces.
 */
export default function ResetPasswordPage() {
  const resetPassword = useAuthStore((s) => s.resetPassword);
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const token = searchParams.get('token') ?? '';

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);

  // A link with no token can't reset anything - send them to request a fresh one.
  if (!token) return <Navigate to="/forgot-password" replace />;

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (submitting) return;
    setSubmitting(true);
    setFieldErrors({});
    setError(null);
    const res = await resetPassword({
      token,
      email,
      password,
      password_confirmation: passwordConfirmation,
    });
    setSubmitting(false);
    if (res.ok) {
      navigate('/login?reset=success', { replace: true });
    } else if (Object.keys(res.fieldErrors).length > 0) {
      setFieldErrors(res.fieldErrors);
    } else {
      setError(res.message);
    }
  };

  return (
    <AuthLayout title="Choose a new password" subtitle="Set a new password for your account.">
      <Motion variants={staggerItem}>
        <form onSubmit={submit} className="flex flex-col gap-5" noValidate>
          <Input
            type="email"
            label="Email"
            error={fieldErrors.email}
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
            label="New password"
            error={fieldErrors.password}
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            required
            autoComplete="new-password"
            placeholder="••••••••"
            disabled={submitting}
          />
          <Input
            type="password"
            label="Confirm new password"
            value={passwordConfirmation}
            onChange={(e) => setPasswordConfirmation(e.target.value)}
            required
            autoComplete="new-password"
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
            {submitting ? 'Saving…' : 'Reset password'}
          </Button>
        </form>

        <p className="mt-6 text-center text-xs text-fg-subtle lg:text-left">
          <Link to="/login" className="font-semibold text-brand-700 hover:underline">
            Back to sign in
          </Link>
        </p>
      </Motion>
    </AuthLayout>
  );
}
