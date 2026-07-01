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
  if (staffOnly && user.role === 'buyer') return <Navigate to="/" replace />;

  return <>{children}</>;
}
