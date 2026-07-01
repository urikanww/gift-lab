import type { ReactNode } from 'react';
import { Navigate, useLocation } from 'react-router-dom';
import { useAuthStore } from '../stores/authStore';
import { LoadingState } from './ui/States';

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

  if (status !== 'ready') return <LoadingState label="Checking session…" />;
  if (!user) return <Navigate to="/login" state={{ from: location.pathname }} replace />;
  // Staff allowlist mirrors backend User::isStaff() — deny by default so a future
  // non-staff role can never fall through into a staff-only route.
  if (staffOnly && user.role !== 'staff_admin' && user.role !== 'superadmin') {
    return <Navigate to="/" replace />;
  }

  return <>{children}</>;
}
