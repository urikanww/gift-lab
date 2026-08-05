import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuthStore } from '../stores/authStore';
import { Button, Input } from '../ui';
import { AuthLayout } from '../components/AuthLayout';
import { Motion, fadeInUp, staggerItem } from '../motion';

/**
 * Forgot password: collect an email and ask the backend to send a reset link.
 * The response is deliberately generic (the API never reveals whether the email
 * is registered), so on success we just show that message and stop.
 */
export default function ForgotPasswordPage() {
  const forgotPassword = useAuthStore((s) => s.forgotPassword);
  const [email, setEmail] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [sent, setSent] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (submitting) return;
    setSubmitting(true);
    setError(null);
    const res = await forgotPassword(email);
    setSubmitting(false);
    if (res.ok) setSent(res.message);
    else setError(res.message);
  };

  return (
    <AuthLayout
      title="Reset your password"
      subtitle="Enter your account email and we'll send a link to set a new password."
    >
      {sent ? (
        <Motion variants={staggerItem}>
          <div
            className="rounded-md border border-success/30 bg-success-bg px-3 py-3 text-sm text-success"
            role="status"
          >
            {sent}
          </div>
          <p className="mt-6 text-center text-xs text-fg-subtle lg:text-left">
            <Link to="/login" className="font-semibold text-brand-700 hover:underline">
              Back to sign in
            </Link>
          </p>
        </Motion>
      ) : (
        <Motion variants={staggerItem}>
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
              {submitting ? 'Sending…' : 'Send reset link'}
            </Button>
          </form>

          <p className="mt-6 text-center text-xs text-fg-subtle lg:text-left">
            Remembered it?{' '}
            <Link to="/login" className="font-semibold text-brand-700 hover:underline">
              Back to sign in
            </Link>
          </p>
        </Motion>
      )}
    </AuthLayout>
  );
}
