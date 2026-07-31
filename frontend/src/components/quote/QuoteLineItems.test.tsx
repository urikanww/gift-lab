import { afterEach, beforeEach, expect, it } from 'vitest';
import { act, render, screen } from '@testing-library/react';
import QuoteLineItems, { PricingSummary } from './QuoteLineItems';
import { useAuthStore } from '../../stores/authStore';
import type { LineItem, Quote, UserRole } from '../../types';

// The line source action is staff-gated (shared order route). act() wraps the
// store write because subscribed components re-render on it.
const signInAs = (role: UserRole | null) =>
  act(() => {
    useAuthStore.setState({ user: role ? ({ role } as never) : null, status: 'ready', error: null });
  });

// Sign a staff user in for these tests; reset afterwards so other suites see a
// clean store.
beforeEach(() => signInAs('superadmin'));
afterEach(() => signInAs(null));

const lineItem = (product: Partial<LineItem['product']> | null): LineItem =>
  ({
    id: 1,
    quote_id: 1,
    job_id: null,
    product_id: 4,
    variant_id: null,
    qty: 30,
    unit_price: '6.00',
    currency: 'SGD',
    line_total: '180.00',
    line_state: 'PENDING',
    customization: null,
    product: product
      ? ({ id: 4, name: 'Demo Enamel Pin', image_url: null, ...product } as LineItem['product'])
      : undefined,
  }) as unknown as LineItem;

it('shows the Shopee stock-check button when the product has an affiliate_url', () => {
  render(<QuoteLineItems items={[lineItem({ affiliate_url: 'https://s.shopee.sg/DEMO' })]} />);

  // Rendered in both the desktop table and the mobile list.
  const links = screen.getAllByRole('link', { name: /check stock on shopee/i });
  expect(links.length).toBeGreaterThan(0);
  expect(links[0]).toHaveAttribute('href', 'https://s.shopee.sg/DEMO');
  expect(links[0]).toHaveAttribute('rel', 'sponsored nofollow noopener noreferrer');
  expect(links[0]).toHaveAttribute('target', '_blank');
});

it('shows a "No source detected" caption when the product has no affiliate_url and no source_url', () => {
  render(<QuoteLineItems items={[lineItem({ class: 'CORE' })]} />);

  expect(screen.queryByRole('link')).not.toBeInTheDocument();
  expect(screen.getAllByText(/no source detected/i).length).toBeGreaterThan(0);
});

it('links to the Thingiverse listing, labelled by source_kind, when there is no affiliate_url', () => {
  render(
    <QuoteLineItems
      items={[lineItem({ source_kind: 'thingiverse', source_url: 'https://www.thingiverse.com/thing:42' })]}
    />,
  );

  const links = screen.getAllByRole('link', { name: /view on thingiverse/i });
  expect(links[0]).toHaveAttribute('href', 'https://www.thingiverse.com/thing:42');
  expect(links[0]).toHaveAttribute('target', '_blank');
  expect(links[0]).toHaveAttribute('rel', 'noopener noreferrer');
});

it('falls back to the host in the label when source_kind is unknown/marketplace', () => {
  render(
    <QuoteLineItems items={[lineItem({ source_kind: 'marketplace', source_url: 'https://shopee.sg/item/1' })]} />,
  );
  expect(screen.getAllByRole('link', { name: /view on shopee\.sg/i })[0]).toBeInTheDocument();
});

it('affiliate link wins over a plain source_url when a product has both', () => {
  render(
    <QuoteLineItems
      items={[lineItem({ source_kind: 'makerworld', source_url: 'https://makerworld.com/x', affiliate_url: 'https://s.shopee.sg/Y' })]}
    />,
  );
  expect(screen.getAllByRole('link', { name: /check stock on shopee/i })[0]).toHaveAttribute('href', 'https://s.shopee.sg/Y');
  expect(screen.queryByRole('link', { name: /view on/i })).not.toBeInTheDocument();
});

it('shows "No source detected" when source_url is present but a non-http(s) scheme', () => {
  render(<QuoteLineItems items={[lineItem({ source_kind: 'thingiverse', source_url: 'javascript:alert(1)' })]} />);
  expect(screen.queryByRole('link')).not.toBeInTheDocument();
  expect(screen.getAllByText(/no source detected/i).length).toBeGreaterThan(0);
});

it('renders no source action at all for a buyer (non-staff) viewer', () => {
  signInAs('buyer');
  render(<QuoteLineItems items={[lineItem({ affiliate_url: 'https://s.shopee.sg/DEMO' })]} />);
  expect(screen.queryByText(/check stock on shopee/i)).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: /check stock on shopee/i })).not.toBeInTheDocument();
  // The staff-only fallback caption must not leak to buyers either.
  expect(screen.queryByText(/no source detected/i)).not.toBeInTheDocument();
});

const base = {
  id: 1,
  currency: 'SGD',
  subtotal: '100.00',
  delivery: '10.00',
  gst: '9.90',
  gst_rate: '9.00',
  total: '95.00',
} as unknown as Quote;

it('renders each adjustment between delivery and total', () => {
  render(
    <PricingSummary
      quote={{ ...base, adjustments: [
        { label: 'Loyalty discount', amount: -20 },
        { label: 'Rush surcharge', amount: 5 },
      ] } as unknown as Quote}
    />,
  );

  expect(screen.getByText('Loyalty discount')).toBeInTheDocument();
  expect(screen.getByText('SGD -20.00')).toBeInTheDocument();
  expect(screen.getByText('Rush surcharge')).toBeInTheDocument();
  expect(screen.getByText('SGD 5.00')).toBeInTheDocument();
  // The total already accounts for them (server-computed).
  expect(screen.getByText('SGD 95.00')).toBeInTheDocument();
});

it('shows no adjustment rows when there are none', () => {
  render(<PricingSummary quote={{ ...base, adjustments: [] } as unknown as Quote} />);

  expect(screen.getByText('Subtotal')).toBeInTheDocument();
  expect(screen.queryByText(/discount/i)).not.toBeInTheDocument();
});

// The total returned by the backend is already GST-inclusive (Task 3/4 in
// PricingService/QuoteService); this row is purely informational and must
// never be summed into the total client-side.
it('renders a GST row with the parsed rate, positioned between delivery and total', () => {
  const { container } = render(<PricingSummary quote={{ ...base, adjustments: [] } as unknown as Quote} />);

  expect(screen.getByText('GST (9%)')).toBeInTheDocument();
  expect(screen.getByText('SGD 9.90')).toBeInTheDocument();

  const labels = Array.from(container.querySelectorAll('dt')).map((el) => el.textContent);
  const deliveryIdx = labels.indexOf('Delivery');
  const gstIdx = labels.indexOf('GST (9%)');
  const totalIdx = labels.indexOf('Total');
  expect(deliveryIdx).toBeGreaterThanOrEqual(0);
  expect(gstIdx).toBeGreaterThan(deliveryIdx);
  expect(totalIdx).toBeGreaterThan(gstIdx);
});
