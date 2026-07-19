import { expect, it } from 'vitest';
import { EMPTY_SHIPPING, isShippingValid } from './ShippingFields';

it('requires recipient, phone, line1, and postal code', () => {
  expect(isShippingValid(EMPTY_SHIPPING)).toBe(false);
  expect(
    isShippingValid({
      ...EMPTY_SHIPPING,
      recipient_name: 'A',
      phone: '+6591234567',
      line1: '1 Marina Blvd',
      postal_code: '018989',
    }),
  ).toBe(true);
});
