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
 * Sections that are never grandfathered (L28) - mirrors the backend
 * Permissions::SENSITIVE_SECTIONS. Pricing (financial config), Users
 * (account management), and Reports (business/financial reporting + CSV
 * export) must be granted EXPLICITLY, so a payload missing the permissions
 * array must not flash a sensitive control open.
 */
const SENSITIVE_SECTIONS = ['pricing', 'users', 'reports'];

function isSensitiveKey(key: string): boolean {
  return SENSITIVE_SECTIONS.includes(key.split('.')[0]);
}

/**
 * Whether a user holds a granular "section.action" permission. Mirrors the
 * backend User::hasPermission:
 *  - superadmin: always true.
 *  - staff_admin: their allowlist; a MISSING array grandfathers the OPERATIONAL
 *    sections to true (so an older payload never hides the ordinary console) but
 *    NOT the sensitive Pricing/Users/Reports sections, which the backend also
 *    excludes from its grandfather default (L28).
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
  if (user.permissions === undefined) return !isSensitiveKey(key);
  return user.permissions.includes(key);
}
