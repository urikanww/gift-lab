import { useEffect, useState } from 'react';
import { Link, Navigate, useNavigate, useSearchParams } from 'react-router-dom';
import { useAuthStore } from '../stores/authStore';
import api from '../lib/api';
import { Button, Card, Input, Logo } from '../ui';
import { Motion, fadeInUp, staggerContainer, staggerItem } from '../motion';

interface PendingProfile {
  name: string;
  email: string;
}

/**
 * Second step of Google sign-UP. The backend holds the verified Google profile
 * (name + email) under the opaque `pending` token; this page reads it back for
 * display, collects the B2B company details + PDPA consent a Google profile
 * can't supply, and posts everything to finish account creation.
 */
export default function GoogleCompletePage() {
  const user = useAuthStore((s) => s.user);
  const completeGoogle = useAuthStore((s) => s.completeGoogle);
  const error = useAuthStore((s) => s.error);
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const token = searchParams.get('pending') ?? '';

  const [profile, setProfile] = useState<PendingProfile | null>(null);
  // 'loading' -> probing the token; 'ready' -> show form; 'expired' -> token gone.
  const [phase, setPhase] = useState<'loading' | 'ready' | 'expired'>('loading');

  const [companyName, setCompanyName] = useState('');
  const [companyRegistrationNo, setCompanyRegistrationNo] = useState('');
  const [companyPhone, setCompanyPhone] = useState('');
  const [companyAddress, setCompanyAddress] = useState('');
  const [consent, setConsent] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  // Read back the pending profile. A missing/blank token or a 410 (expired,
  // consumed, or never existed) both land on the "start again" state.
  useEffect(() => {
    if (!token) {
      setPhase('expired');
      return;
    }
    let mounted = true;
    api
      .get<PendingProfile>(`/auth/google/pending/${encodeURIComponent(token)}`)
      .then((r) => {
        if (!mounted) return;
        setProfile(r.data);
        setPhase('ready');
      })
      .catch(() => {
        if (mounted) setPhase('expired');
      });
    return () => {
      mounted = false;
    };
  }, [token]);

  // Signed in mid-flow (e.g. completed in another tab) - nothing to finish here.
  if (user) return <Navigate to="/account" replace />;

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (submitting) return;
    setSubmitting(true);
    setFieldErrors({});
    const res = await completeGoogle({
      token,
      company_name: companyName,
      company_registration_no: companyRegistrationNo || undefined,
      company_phone: companyPhone || undefined,
      company_address: companyAddress || undefined,
      consent,
    });
    setSubmitting(false);
    if (res.ok) {
      navigate('/account', { replace: true });
    } else {
      setFieldErrors(res.fieldErrors);
      // A stale/expired token surfaces as a `token` field error - flip to the
      // "start again" state instead of leaving a dead form on screen.
      if (res.fieldErrors.token) setPhase('expired');
    }
  };

  return (
    <div className="mx-auto flex min-h-[70vh] w-full max-w-md flex-col justify-center px-1 py-10">
      <Motion variants={staggerContainer} initial="hidden" animate="visible">
        <Motion variants={staggerItem} className="mb-8 text-center">
          <Logo className="justify-center" markClassName="h-9 w-9" />
          <h1 className="mt-5 font-display text-3xl text-fg sm:text-4xl">Finish your account</h1>
          <p className="mt-2 text-sm text-fg-muted">
            Just a few company details to complete your corporate buyer account.
          </p>
        </Motion>

        {phase === 'loading' && (
          <Motion variants={staggerItem} className="text-center text-sm text-fg-muted" role="status">
            Loading your sign-up…
          </Motion>
        )}

        {phase === 'expired' && (
          <Motion variants={staggerItem}>
            <Card padding="lg" className="text-center shadow-md">
              <p className="text-sm text-fg-muted">
                This Google sign-up link has expired or was already used. Please start again.
              </p>
              <Link
                to="/register"
                className="mt-4 inline-block font-semibold text-brand-700 hover:underline"
              >
                Back to sign up
              </Link>
            </Card>
          </Motion>
        )}

        {phase === 'ready' && profile && (
          <Motion variants={staggerItem}>
            <Card padding="lg" className="shadow-md">
              {/* Read-only identity from the verified Google profile. */}
              <div className="mb-5 rounded-md border border-border bg-surface-2 px-3 py-2 text-sm">
                <span className="text-fg-muted">Signing up as </span>
                <span className="font-medium text-fg">{profile.name}</span>
                <span className="text-fg-muted"> ({profile.email})</span>
              </div>

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
                  label="Company registration no. (optional)"
                  error={fieldErrors.company_registration_no}
                  value={companyRegistrationNo}
                  onChange={(e) => setCompanyRegistrationNo(e.target.value)}
                  placeholder="201812345A"
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
                  label="Address (optional)"
                  error={fieldErrors.company_address}
                  value={companyAddress}
                  onChange={(e) => setCompanyAddress(e.target.value)}
                  autoComplete="street-address"
                  placeholder="1 Marina Blvd, Singapore"
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

                <Button
                  type="submit"
                  fullWidth
                  size="lg"
                  loading={submitting}
                  disabled={submitting || !consent}
                >
                  {submitting ? 'Creating account…' : 'Create account'}
                </Button>
              </form>
            </Card>
          </Motion>
        )}
      </Motion>
    </div>
  );
}
