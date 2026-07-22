import { expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { PricingSummary } from './QuoteLineItems';
import type { Quote } from '../../types';

const base = {
  id: 1,
  currency: 'SGD',
  subtotal: '100.00',
  delivery: '10.00',
  total: '95.00',
} as unknown as Quote;

it('renders each adjustment between delivery and total', () => {
  render(
    <PricingSummary
      quote={{ ...base, adjustments: [
        { label: 'Loyalty discount', amount: -20 },
        { label: 'GST', amount: 5 },
      ] } as unknown as Quote}
    />,
  );

  expect(screen.getByText('Loyalty discount')).toBeInTheDocument();
  expect(screen.getByText('SGD -20.00')).toBeInTheDocument();
  expect(screen.getByText('GST')).toBeInTheDocument();
  expect(screen.getByText('SGD 5.00')).toBeInTheDocument();
  // The total already accounts for them (server-computed).
  expect(screen.getByText('SGD 95.00')).toBeInTheDocument();
});

it('shows no adjustment rows when there are none', () => {
  render(<PricingSummary quote={{ ...base, adjustments: [] } as unknown as Quote} />);

  expect(screen.getByText('Subtotal')).toBeInTheDocument();
  expect(screen.queryByText(/discount/i)).not.toBeInTheDocument();
});
