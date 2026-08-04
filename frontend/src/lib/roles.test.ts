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

it('grandfathers a staff_admin whose permissions are missing - but NOT sensitive sections (L28)', () => {
  // No `permissions` key at all grandfathers the OPERATIONAL console so an older
  // payload never hides it...
  const staff = { role: 'staff_admin' as const };
  expect(hasPermission(staff, 'production.manage')).toBe(true);
  expect(hasPermission(staff, 'products.approve')).toBe(true);
  // ...but the sensitive Pricing/Users/Reports sections must be granted
  // explicitly, so a missing array must NOT flash them open (mirrors the
  // backend grandfather).
  expect(hasPermission(staff, 'pricing.view')).toBe(false);
  expect(hasPermission(staff, 'pricing.manage')).toBe(false);
  expect(hasPermission(staff, 'users.manage')).toBe(false);
  expect(hasPermission(staff, 'reports.view')).toBe(false);
  // An explicit grant still works.
  expect(hasPermission({ role: 'staff_admin' as const, permissions: ['pricing.view'] }, 'pricing.view')).toBe(true);
  expect(hasPermission({ role: 'staff_admin' as const, permissions: ['reports.view'] }, 'reports.view')).toBe(true);
});

it('denies buyers and unknown users', () => {
  expect(hasPermission({ role: 'buyer' as const, permissions: [] }, 'quotes.view')).toBe(false);
  expect(hasPermission(null, 'quotes.view')).toBe(false);
  expect(hasPermission(undefined, 'quotes.view')).toBe(false);
});
