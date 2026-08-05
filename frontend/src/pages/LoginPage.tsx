import { useState } from 'react';
import { Link, Navigate, useLocation, useNavigate, useSearchParams } from 'react-router-dom';
import { useAuthStore } from '../stores/authStore';
import { isStaffRole } from '../lib/roles';
import { Button, Card, Input, Logo } from '../ui';
import { GoogleAuthSection } from '../components/GoogleButton';
import { Motion, fadeInUp, staggerContainer, staggerItem } from '../motion';

interface LocationState {
  from?: string;
}

// The Google callback bounces failures back to /login?error=<code>. Map each to
// copy the buyer can act on. Unknown/absent code -> no banner.
const GOOGLE_ERRORS: Record<string, string> = {
  google_email_exists:
    'This email already has a password account. Sign in with your password below.',
  google_unverified:
    'Your Google email isn’t verified. Verify it with Google, or sign in with your password.',
  google_not_allowed:
    'Google sign-in isn’t available for this account. Please sign in with your password.',
  google_failed: 'Google sign-in didn’t complete. Please try again.',
};

export default function LoginPage() {
  const { login, error, user } = useAuthStore();
  const navigate = useNavigate();
  const location = useLocation();
  const [searchParams] = useSearchParams();
  // Return path can arrive as router state (a ProtectedRoute bounce) or as a
  // ?from= query (the api.ts full-page 401 redirect, L3). Only honour a
  // same-origin path - never "//evil.com" or an absolute URL - so the redirect
  // can't be turned into an open redirect.
  const fromQuery = searchParams.get('from');
  const safeFromQuery =
    fromQuery && fromQuery.startsWith('/') && !fromQuery.startsWith('//') ? fromQuery : undefined;
  const from = (location.state as LocationState | null)?.from ?? safeFromQuery;
  const googleError = GOOGLE_ERRORS[searchParams.get('error') ?? ''];

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [submitting, setSubmitting] = useState(false);

  // Already signed in (e.g. bookmarked /login, or back-navigated to it) - bounce
  // to the same role-aware landing a fresh sign-in would use, instead of
  // showing the form again over an active session.
  if (user) {
    return <Navigate to={from ?? (isStaffRole(user.role) ? '/dashboard' : '/account')} replace />;
  }

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
      navigate(from ?? (isStaffRole(role) ? '/dashboard' : '/account'), { replace: true });
    }
  };

  return (
    <div className="mx-auto flex min-h-[70vh] w-full max-w-md flex-col justify-center px-1 py-10">
      <Motion variants={staggerContainer} initial="hidden" animate="visible">
        {/* Brand mark + welcome copy */}
        <Motion variants={staggerItem} className="mb-8 text-center">
          <Logo className="justify-center" markClassName="h-9 w-9" />
          <h1 className="mt-5 font-display text-3xl text-fg sm:text-4xl">Welcome back</h1>
          <p className="mt-2 text-sm text-fg-muted">
            Sign in to manage quotes, proofs and production.
          </p>
        </Motion>

        <Motion variants={staggerItem}>
          <Card padding="lg" className="flex flex-col gap-5 shadow-md">
            {googleError && (
              <Motion
                variants={fadeInUp}
                initial="hidden"
                animate="visible"
                className="rounded-md border border-danger/30 bg-danger-bg px-3 py-2 text-sm text-danger"
                role="alert"
              >
                {googleError}
              </Motion>
            )}

            <GoogleAuthSection label="Sign in with Google" />

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
          New corporate buyer?{' '}
          <Link to="/register" state={{ from }} className="font-semibold text-brand-700 hover:underline">
            Create your company account
          </Link>
        </Motion>
      </Motion>
    </div>
  );
}
