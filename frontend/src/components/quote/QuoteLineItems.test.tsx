import { expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { PricingSummary } from './QuoteLineItems';
import type { Quote } from '../../types';

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
