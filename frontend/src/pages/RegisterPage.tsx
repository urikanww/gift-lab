import { useEffect, useState } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { useAuthStore } from '../stores/authStore';
import { Button, Input } from '../ui';
import { AuthLayout } from '../components/AuthLayout';
import { GoogleAuthSection } from '../components/GoogleButton';
import { Motion, fadeInUp, staggerItem } from '../motion';

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
  const [consent, setConsent] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  // F1: per-field validation messages, keyed as the API sends them, so each
  // input shows its own error inline instead of one lumped banner.
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (submitting) return;
    setSubmitting(true);
    setFieldErrors({});
    const res = await register({
      name,
      email,
      password,
      password_confirmation: passwordConfirmation,
      company_name: companyName,
      company_phone: companyPhone || undefined,
      consent,
    });
    setSubmitting(false);
    if (res.ok) {
      navigate(from ?? '/quotes', { replace: true });
    } else {
      setFieldErrors(res.fieldErrors);
    }
  };

  return (
    <AuthLayout
      title="Create your account"
      subtitle="Set up your company to request quotes, approve proofs and track orders."
    >
      <Motion variants={staggerItem}>
        <div className="flex flex-col gap-5">
          <GoogleAuthSection label="Sign up with Google" />

          <form onSubmit={submit} className="flex flex-col gap-5" noValidate>
              <Input
                label="Company name"
                error={fieldErrors.company_name}
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
                error={fieldErrors.name}
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
                error={fieldErrors.email}
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
                error={fieldErrors.company_phone}
                value={companyPhone}
                onChange={(e) => setCompanyPhone(e.target.value)}
                autoComplete="tel"
                placeholder="+65 6123 4567"
                disabled={submitting}
              />
              <Input
                type="password"
                label="Password"
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
                label="Confirm password"
                error={fieldErrors.password_confirmation}
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

              <label className="flex items-start gap-2 text-sm text-fg-muted">
                <input
                  type="checkbox"
                  checked={consent}
                  onChange={(e) => setConsent(e.target.checked)}
                  disabled={submitting}
                  className="mt-0.5 h-4 w-4 shrink-0 rounded border-border-strong text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                />
                <span>
                  I agree to the{' '}
                  <Link to="/privacy" className="font-semibold text-brand-700 hover:underline">
                    Privacy Policy
                  </Link>
                  .
                </span>
              </label>

            <Button type="submit" fullWidth size="lg" loading={submitting} disabled={submitting || !consent}>
              {submitting ? 'Creating account…' : 'Create account'}
            </Button>
          </form>
        </div>
      </Motion>

      <Motion variants={staggerItem} className="mt-6 text-center text-xs text-fg-subtle lg:text-left">
        Already have an account?{' '}
        <Link to="/login" state={{ from }} className="font-semibold text-brand-700 hover:underline">
          Sign in
        </Link>
      </Motion>
    </AuthLayout>
  );
}
