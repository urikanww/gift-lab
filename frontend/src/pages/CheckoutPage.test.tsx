import { expect, it, afterEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import { ThemeProvider } from '../ui';
import CheckoutPage from './CheckoutPage';
import { useCartStore } from '../stores/cartStore';
import { useAuthStore } from '../stores/authStore';
import { useSavedAddressStore } from '../stores/savedAddressStore';

afterEach(() => {
  useCartStore.setState({ lines: [], estimate: null, estimateError: null });
});

it('prompts anonymous users to log in before placing the order', () => {
  useSavedAddressStore.setState({ addresses: [], loading: false, error: null });
  useCartStore.setState({
    lines: [{ key: 'k', product: { id: 5, name: 'A5' } as any, variant: null, qty: 1, customization: {} }],
  });
  useAuthStore.setState({ user: null, status: 'ready' } as any);
  render(
    <ThemeProvider>
      <MemoryRouter initialEntries={['/checkout']}>
        <Routes>
          <Route path="/checkout" element={<CheckoutPage />} />
          <Route path="/login" element={<div>Login screen</div>} />
        </Routes>
      </MemoryRouter>
    </ThemeProvider>,
  );
  expect(screen.getByRole('link', { name: /log in|sign in/i })).toBeInTheDocument();
});

it('blocks placing the order until the shipping address is valid', () => {
  useSavedAddressStore.setState({ addresses: [], loading: false, error: null });
  useCartStore.setState({
    lines: [{ key: 'k', product: { id: 5, name: 'A5' } as any, variant: null, qty: 1, customization: {} }],
  });
  useAuthStore.setState({
    user: { id: 1, company_id: 1, role: 'buyer', company: { id: 1, name: 'Acme', address: '' } } as any,
    status: 'ready',
  } as any);

  render(
    <ThemeProvider>
      <MemoryRouter initialEntries={['/checkout']}>
        <Routes>
          <Route path="/checkout" element={<CheckoutPage />} />
        </Routes>
      </MemoryRouter>
    </ThemeProvider>,
  );

  fireEvent.click(screen.getByRole('button', { name: /place order/i }));
  expect(screen.getByText(/complete the shipping address/i)).toBeInTheDocument();
});
