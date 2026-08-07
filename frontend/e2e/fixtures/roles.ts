import { expect, type Page } from '@playwright/test';

/**
 * The full role matrix the E2E suite parametrizes over. Credentials mirror
 * database/seeders/E2eSeeder.php (buyer) and AdminUserSeeder.php (staff) - keep
 * them in sync or the login journey fails.
 *
 * `landing` is the path LoginPage routes each role to on success (see
 * LoginPage.tsx / isStaffRole): buyers -> /account, staff -> /dashboard.
 * `canCheckout` marks who the checkout flow actually belongs to - only buyers
 * build a cart and place a quote; staff logging in land on the console instead.
 */
export interface Role {
  key: 'buyer' | 'staff_admin' | 'superadmin';
  email: string;
  password: string;
  landing: string;
  canCheckout: boolean;
}

export const ROLES: Role[] = [
  {
    key: 'buyer',
    email: 'buyer.e2e@giftlab.local',
    password: 'E2ePass!123',
    landing: '/account',
    canCheckout: true,
  },
  {
    key: 'staff_admin',
    email: 'ops@giftlab.local',
    password: 'ChangeMe!123',
    landing: '/dashboard',
    canCheckout: false,
  },
  {
    key: 'superadmin',
    email: 'superadmin@giftlab.local',
    password: 'ChangeMe!123',
    landing: '/dashboard',
    canCheckout: false,
  },
];

/**
 * Sign in through the real UI (not an API shortcut) - login is part of what
 * this journey verifies. Asserts the role landed where LoginPage routes it.
 */
export async function loginAs(page: Page, role: Role): Promise<void> {
  await page.goto('/login');
  // Let the app's mount-time `/user` probe finish before we log in. The API is a
  // single-threaded dev server; if login's csrf+POST race that probe, the
  // session cookie and XSRF token can desync into a 419 "CSRF token mismatch".
  await page.waitForLoadState('networkidle');
  await page.getByLabel('Email').fill(role.email);
  await page.getByLabel('Password').fill(role.password);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page).toHaveURL(new RegExp(`${role.landing}$`));
}
