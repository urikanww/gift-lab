import { Badge } from '../ui';
import type { UserRole } from '../types';

/** Shared labels/badges for the admin user list + detail pages. */

export const ROLE_LABELS: Record<UserRole, string> = {
  buyer: 'Buyer',
  staff_admin: 'Staff admin',
  superadmin: 'Superadmin',
};

export function roleLabel(role: string): string {
  return ROLE_LABELS[role as UserRole] ?? role;
}

const ROLE_TONE: Record<UserRole, 'neutral' | 'brand' | 'warning'> = {
  buyer: 'neutral',
  staff_admin: 'brand',
  superadmin: 'warning',
};

export function RoleBadge({ role }: { role: string }) {
  return (
    <Badge tone={ROLE_TONE[role as UserRole] ?? 'neutral'} size="sm">
      {roleLabel(role)}
    </Badge>
  );
}

export function ActiveBadge({ active }: { active: boolean }) {
  return (
    <Badge tone={active ? 'success' : 'danger'} size="sm" dot>
      {active ? 'Active' : 'Deactivated'}
    </Badge>
  );
}
