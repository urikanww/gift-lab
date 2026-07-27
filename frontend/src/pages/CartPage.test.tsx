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
      total: 15,
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
      total: 10,
      delivery_reliable: false,
    } as any,
    estimateError: null,
  });

  renderCart();

  expect(screen.getByText(DELIVERY_NOTE_UNRELIABLE)).toBeInTheDocument();
});
