import { buttonClasses, cn } from '../ui';
import { googleRedirectUrl, useGoogleEnabled } from '../lib/socialAuth';

/** Google's multicolour "G" mark. Fixed brand colours (not theme tokens). */
function GoogleGlyph() {
  return (
    <svg viewBox="0 0 18 18" className="h-[18px] w-[18px] shrink-0" aria-hidden="true">
      <path fill="#4285F4" d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84a4.14 4.14 0 0 1-1.8 2.72v2.26h2.92c1.7-1.57 2.68-3.88 2.68-6.62z" />
      <path fill="#34A853" d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.92-2.26c-.8.54-1.84.86-3.04.86-2.34 0-4.32-1.58-5.03-3.7H.96v2.34A9 9 0 0 0 9 18z" />
      <path fill="#FBBC05" d="M3.97 10.72a5.4 5.4 0 0 1 0-3.44V4.94H.96a9 9 0 0 0 0 8.12l3.01-2.34z" />
      <path fill="#EA4335" d="M9 3.58c1.32 0 2.5.45 3.44 1.35l2.58-2.58C13.47.89 11.43 0 9 0A9 9 0 0 0 .96 4.94l3.01 2.34C4.68 5.16 6.66 3.58 9 3.58z" />
    </svg>
  );
}

/**
 * Google button + an "or" divider, as one unit that renders NOTHING when Google
 * is unconfigured - so the divider never orphans above the email/password form.
 * Drop this at the top of an auth card.
 */
export function GoogleAuthSection({ label }: { label?: string }) {
  const enabled = useGoogleEnabled();
  if (!enabled) return null;

  return (
    <>
      <a
        href={googleRedirectUrl()}
        className={cn(buttonClasses({ variant: 'outline', size: 'lg' }), 'w-full')}
        data-testid="google-signin"
      >
        <GoogleGlyph />
        {label ?? 'Continue with Google'}
      </a>
      <div className="flex items-center gap-3 text-xs text-fg-subtle">
        <span className="h-px flex-1 bg-border" />
        or
        <span className="h-px flex-1 bg-border" />
      </div>
    </>
  );
}
