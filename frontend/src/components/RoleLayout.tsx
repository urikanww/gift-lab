import Layout from './Layout';
import StaffLayout from './StaffLayout';
import { useAuthStore } from '../stores/authStore';
import { isStaffRole } from '../lib/roles';

/**
 * Shared authenticated routes (e.g. quotes) render in the staff console shell
 * for staff and in the standard shopfront layout for buyers. Both render an
 * <Outlet>, so nested routes are unaffected.
 */
export default function RoleLayout() {
  const role = useAuthStore((s) => s.user?.role);
  return isStaffRole(role) ? <StaffLayout /> : <Layout />;
}
