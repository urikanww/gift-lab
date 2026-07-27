import { expect, it, afterEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { ThemeProvider } from '../ui';
import CartPage from './CartPage';
import { useCartStore } from '../stores/cartStore';
import { DELIVERY_NOTE_RELIABLE, DELIVERY_NOTE_UNRELIABLE } from '../lib/deliveryCopy';

afterEach(() => {
  useCartStore.setState({ lines: [], estimate: null, estimateError: null });
});

const LINE = { key: 'k', product: { id: 5, name: 'A5' } as any, variant: null, qty: 1, customization: {} };

function renderCart() {
  render(
    <ThemeProvider>
      <MemoryRouter>
        <CartPage />
      </MemoryRouter>
    </ThemeProvider>,
  );
}

it('renders the shared reliable-delivery disclaimer from lib/deliveryCopy', () => {
  useCartStore.setState({
    lines: [LINE],
    estimate: {
      currency: 'SGD',
      lines: [{ unit_price: 10, line_total: 10 }],
      subtotal: 10,
      delivery: 5,
      gst: 1.35,
      gst_rate: 9,
      total: 16.35,
      delivery_reliable: true,
    } as any,
    estimateError: null,
  });

  renderCart();

  expect(screen.getByText(DELIVERY_NOTE_RELIABLE)).toBeInTheDocument();
});

it('renders the shared unreliable-delivery disclaimer from lib/deliveryCopy', () => {
  useCartStore.setState({
    lines: [LINE],
    estimate: {
      currency: 'SGD',
      lines: [{ unit_price: 10, line_total: 10 }],
      subtotal: 10,
      delivery: 0,
      gst: 0,
      gst_rate: 9,
      total: 10,
      delivery_reliable: false,
    } as any,
    estimateError: null,
  });

  renderCart();

  expect(screen.getByText(DELIVERY_NOTE_UNRELIABLE)).toBeInTheDocument();
});

// Total is already GST-inclusive from the backend (quoteTotals()); the GST
// row here is purely informational and must never be re-summed client-side.
it('shows a GST row with the rate when delivery is shown', () => {
  useCartStore.setState({
    lines: [LINE],
    estimate: {
      currency: 'SGD',
      lines: [{ unit_price: 10, line_total: 10 }],
      subtotal: 10,
      delivery: 5,
      gst: 1.35,
      gst_rate: 9,
      total: 16.35,
      delivery_reliable: true,
    } as any,
    estimateError: null,
  });

  renderCart();

  expect(screen.getByText('GST (9%)')).toBeInTheDocument();
  expect(screen.getByText('SGD 1.35')).toBeInTheDocument();
});

// GST is computed on subtotal+delivery; when delivery is deferred (unreliable
// weight/dims), showing a GST figure that doesn't match the displayed total
// would be misleading, so it defers exactly like the delivery line does.
it('hides the GST row when delivery is deferred (unreliable estimate)', () => {
  useCartStore.setState({
    lines: [LINE],
    estimate: {
      currency: 'SGD',
      lines: [{ unit_price: 10, line_total: 10 }],
      subtotal: 10,
      delivery: 0,
      gst: 0.9,
      gst_rate: 9,
      total: 10,
      delivery_reliable: false,
    } as any,
    estimateError: null,
  });

  renderCart();

  expect(screen.queryByText(/^GST/)).not.toBeInTheDocument();
});
