import type { UserRole } from '../types';

/**
 * Positive staff allowlist - mirrors the backend `User::isStaff()` and
 * `ProtectedRoute`. Deny by default: an unknown/future role or null is treated
 * as NOT staff, so staff-only UI never leaks to a non-staff account (the old
 * `role !== 'buyer'` check was fail-open).
 */
export function isStaffRole(role: UserRole | null | undefined): boolean {
  return role === 'staff_admin' || role === 'superadmin';
}
