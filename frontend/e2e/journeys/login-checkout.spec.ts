import { test, expect } from '@playwright/test';
import { ROLES, loginAs } from '../fixtures/roles';
import { E2E_PRODUCT_NAME } from '../fixtures/data';

/**
 * Layer 1 template: one journey, looped across the whole role matrix.
 *
 * The point of this file is the PATTERN, not the single flow. Every role runs
 * the shared login+landing assertion; only the buyer continues into checkout,
 * because checkout is a buyer-only concept (staff land on the console). The two
 * cross-role assertions worth having are:
 *   1. each role authenticates and lands where its role dictates, and
 *   2. staff are NOT quietly dropped into a buyer checkout.
 * Copy this shape for the next journeys (reorder, production advance, etc.).
 */
for (const role of ROLES) {
  test(`${role.key}: logs in and lands on ${role.landing}`, async ({ page }) => {
    await loginAs(page, role);
    // Redundant with loginAs' own assert, but makes the per-role guarantee
    // explicit and independent if loginAs is later refactored.
    await expect(page).toHaveURL(new RegExp(`${role.landing}$`));
  });
}

test('buyer: login -> catalogue -> add to cart -> checkout -> quote placed', async ({ page }) => {
  const buyer = ROLES.find((r) => r.key === 'buyer')!;

  await loginAs(page, buyer);

  // Catalogue -> product. Select the seeded fixture product by its known name
  // so the run is deterministic regardless of what else is in the catalogue.
  await page.goto('/products');
  await page.getByRole('link', { name: new RegExp(E2E_PRODUCT_NAME, 'i') }).first().click();
  // Product detail uses a slug id (e.g. /products/e2e-fixture-mug), not numeric.
  await expect(page).toHaveURL(/\/products\/[a-z0-9-]+$/i);

  await page.getByRole('button', { name: 'Add to cart' }).click();

  // Into checkout. Cart persists in localStorage, so a direct nav is enough.
  await page.goto('/checkout');

  // Shipping form - wrapped-label inputs, matched by their visible label text.
  await page.getByLabel(/Recipient name/).fill('E2E Buyer');
  await page.getByLabel(/Phone/).fill('+6591234567');
  await page.getByLabel(/Address line 1/).fill('1 Marina Blvd');
  await page.getByLabel(/Postal code/).fill('018989');

  // Both consent checkboxes gate the Place order button (disabled until checked).
  await page.getByRole('checkbox').nth(0).check(); // quote-request acknowledgement
  await page.getByRole('checkbox').nth(1).check(); // recipient PDPA consent

  const placeOrder = page.getByRole('button', { name: 'Place order' });
  await expect(placeOrder).toBeEnabled();
  await placeOrder.click();

  // Success = the DRAFT quote is created and the celebratory confirmation shows
  // (an in-place modal, not a redirect). The order reference is surfaced in it.
  await expect(page.getByText(/has been created/i)).toBeVisible();
});

test('staff_admin: checkout is not reachable as a buyer flow', async ({ page }) => {
  const staff = ROLES.find((r) => r.key === 'staff_admin')!;
  await loginAs(page, staff);

  // A staff account has no company_id, so even if it reaches /checkout it must
  // not be able to place a buyer quote. Assert the buyer-only cart empty-state
  // (no product seeded into a staff cart) rather than a placed order.
  await page.goto('/checkout');
  await expect(page.getByText(/place order/i)).toHaveCount(0);
});
