import type { ReactNode } from 'react';
import { Navigate, useLocation } from 'react-router-dom';
import { useAuthStore } from '../stores/authStore';
import { Spinner } from '../ui';
import { Motion, fadeIn } from '../motion';

/**
 * Gates authenticated routes. `staffOnly` additionally requires an internal
 * staff role, mirroring the backend policy + broadcast channel auth.
 */
export default function ProtectedRoute({
  children,
  staffOnly = false,
}: {
  children: ReactNode;
  staffOnly?: boolean;
}) {
  const { user, status } = useAuthStore();
  const location = useLocation();

  if (status !== 'ready') {
    // Branded, centered check — fades in so a fast session check doesn't flash.
    return (
      <Motion
        variants={fadeIn}
        initial="hidden"
        animate="visible"
        className="flex min-h-[60vh] flex-col items-center justify-center gap-4 text-center"
        role="status"
        aria-live="polite"
      >
        <Spinner size="lg" className="text-brand-500" />
        <p className="text-sm text-fg-muted">Checking your session…</p>
      </Motion>
    );
  }

  if (!user) return <Navigate to="/login" state={{ from: location.pathname }} replace />;
  // Staff allowlist mirrors backend User::isStaff() — deny by default so a future
  // non-staff role can never fall through into a staff-only route.
  if (staffOnly && user.role !== 'staff_admin' && user.role !== 'superadmin') {
    return <Navigate to="/" replace />;
  }

  return <>{children}</>;
}
