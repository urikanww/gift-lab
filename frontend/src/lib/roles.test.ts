import { expect, it } from 'vitest';
import { hasPermission, isStaffRole } from './roles';

it('treats staff_admin and superadmin as staff, everyone else not', () => {
  expect(isStaffRole('staff_admin')).toBe(true);
  expect(isStaffRole('superadmin')).toBe(true);
  expect(isStaffRole('buyer')).toBe(false);
  expect(isStaffRole(null)).toBe(false);
});

it('gives a superadmin every permission', () => {
  const su = { role: 'superadmin' as const, permissions: [] };
  // Even with an empty array, role wins.
  expect(hasPermission(su, 'quotes.edit')).toBe(true);
  expect(hasPermission(su, 'anything.at.all')).toBe(true);
});

it('checks a restricted staff_admin against their allowlist', () => {
  const staff = { role: 'staff_admin' as const, permissions: ['quotes.view', 'quotes.edit'] };
  expect(hasPermission(staff, 'quotes.edit')).toBe(true);
  expect(hasPermission(staff, 'production.manage')).toBe(false);
});

it('grandfathers a staff_admin whose permissions are missing', () => {
  // No `permissions` key at all = unrestricted, so the console is never hidden
  // from an older payload.
  const staff = { role: 'staff_admin' as const };
  expect(hasPermission(staff, 'production.manage')).toBe(true);
});

it('denies buyers and unknown users', () => {
  expect(hasPermission({ role: 'buyer' as const, permissions: [] }, 'quotes.view')).toBe(false);
  expect(hasPermission(null, 'quotes.view')).toBe(false);
  expect(hasPermission(undefined, 'quotes.view')).toBe(false);
});
