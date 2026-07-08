import { useEffect, useState } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { useAuthStore } from '../stores/authStore';
import { Button, Card, Input } from '../ui';
import { Motion, fadeInUp, staggerContainer, staggerItem } from '../motion';

interface LocationState {
  from?: string;
}

/**
 * Self-serve corporate buyer registration (spec 6.1 Stage 0). Creates the
 * company + first buyer account and signs in, so a first-time buyer arriving
 * from checkout can finish their quote request without an account manager.
 */
export default function RegisterPage() {
  const { register, error } = useAuthStore();
  const user = useAuthStore((s) => s.user);
  const navigate = useNavigate();
  const location = useLocation();
  const from = (location.state as LocationState | null)?.from;

  // Already signed in - registration is for new companies only (audit A13).
  // Bounce to the intended destination instead of letting the form 403.
  useEffect(() => {
    if (user) navigate(from ?? '/quotes', { replace: true });
  }, [user, from, navigate]);

  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [companyName, setCompanyName] = useState('');
  const [companyPhone, setCompanyPhone] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (submitting) return;
    setSubmitting(true);
    const ok = await register({
      name,
      email,
      password,
      password_confirmation: passwordConfirmation,
      company_name: companyName,
      company_phone: companyPhone || undefined,
    });
    setSubmitting(false);
    if (ok) {
      navigate(from ?? '/quotes', { replace: true });
    }
  };

  return (
    <div className="mx-auto flex min-h-[70vh] w-full max-w-md flex-col justify-center px-1 py-10">
      <Motion variants={staggerContainer} initial="hidden" animate="visible">
        <Motion variants={staggerItem} className="mb-8 text-center">
          <span className="inline-flex items-center gap-2 rounded-full bg-brand-100 px-3 py-1 text-2xs font-semibold uppercase tracking-wide text-brand-700">
            <span className="h-1.5 w-1.5 rounded-full bg-brand-500" aria-hidden="true" />
            Gift-Lab
          </span>
          <h1 className="mt-5 font-display text-3xl text-fg sm:text-4xl">Create your account</h1>
          <p className="mt-2 text-sm text-fg-muted">
            Set up your company to request quotes, approve proofs and track orders.
          </p>
        </Motion>

        <Motion variants={staggerItem}>
          <Card padding="lg" className="shadow-md">
            <form onSubmit={submit} className="flex flex-col gap-5" noValidate>
              <Input
                label="Company name"
                value={companyName}
                onChange={(e) => setCompanyName(e.target.value)}
                required
                autoComplete="organization"
                autoFocus
                placeholder="Acme Pte Ltd"
                disabled={submitting}
              />
              <Input
                label="Your name"
                value={name}
                onChange={(e) => setName(e.target.value)}
                required
                autoComplete="name"
                placeholder="Jane Tan"
                disabled={submitting}
              />
              <Input
                type="email"
                label="Work email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                required
                autoComplete="email"
                placeholder="you@company.com"
                disabled={submitting}
              />
              <Input
                type="tel"
                label="Phone (optional)"
                value={companyPhone}
                onChange={(e) => setCompanyPhone(e.target.value)}
                autoComplete="tel"
                placeholder="+65 6123 4567"
                disabled={submitting}
              />
              <Input
                type="password"
                label="Password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                required
                autoComplete="new-password"
                placeholder="••••••••"
                disabled={submitting}
              />
              <Input
                type="password"
                label="Confirm password"
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
                {submitting ? 'Creating account…' : 'Create account'}
              </Button>
            </form>
          </Card>
        </Motion>

        <Motion variants={staggerItem} className="mt-6 text-center text-xs text-fg-subtle">
          Already have an account?{' '}
          <Link to="/login" state={{ from }} className="font-semibold text-brand-700 hover:underline">
            Sign in
          </Link>
        </Motion>
      </Motion>
    </div>
  );
}
