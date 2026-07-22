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

/**
 * Whether a user holds a granular "section.action" permission. Mirrors the
 * backend User::hasPermission:
 *  - superadmin: always true.
 *  - staff_admin: their allowlist; a MISSING array is grandfathered to true, so
 *    an older payload without permissions never hides the console.
 *  - anyone else: false.
 *
 * Deny-by-default on shape: an unexpected/null user is not staff, so false.
 */
export function hasPermission(
  user: { role?: UserRole | null; permissions?: string[] } | null | undefined,
  key: string,
): boolean {
  if (!user) return false;
  if (user.role === 'superadmin') return true;
  if (user.role !== 'staff_admin') return false;
  return user.permissions === undefined ? true : user.permissions.includes(key);
}
