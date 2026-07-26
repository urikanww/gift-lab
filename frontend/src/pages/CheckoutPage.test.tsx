import { expect, it, afterEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import { ThemeProvider } from '../ui';
import CheckoutPage from './CheckoutPage';
import { useCartStore } from '../stores/cartStore';
import { useAuthStore } from '../stores/authStore';
import { useSavedAddressStore } from '../stores/savedAddressStore';
import { useQuoteStore } from '../stores/quoteStore';

// Captured once so createQuote can be restored after tests that stub it out.
const originalCreateQuote = useQuoteStore.getState().createQuote;

afterEach(() => {
  useCartStore.setState({ lines: [], estimate: null, estimateError: null });
  useQuoteStore.setState({ actionError: null, createQuote: originalCreateQuote });
});

/** A saved address with every field ShippingFields requires, so the address
 * picker auto-selects it and `isShippingValid` passes without manual typing. */
const VALID_SAVED_ADDRESS = {
  id: 1,
  label: 'HQ',
  recipient_name: 'Jo Tan',
  phone: '91234567',
  email: '',
  line1: '1 Example Street',
  line2: '',
  city: '',
  state: '',
  postal_code: '123456',
  country: 'SG',
  notes: '',
};

function renderCheckoutAsBuyer() {
  useSavedAddressStore.setState({ addresses: [VALID_SAVED_ADDRESS], loading: false, error: null });
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
}

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

  // Place order is gated on the quote-request acknowledgement; tick it so the
  // click reaches the shipping-address validation we're asserting here.
  fireEvent.click(screen.getByRole('checkbox'));
  fireEvent.click(screen.getByRole('button', { name: /place order/i }));
  expect(screen.getByText(/complete the shipping address/i)).toBeInTheDocument();
});

it('keeps Place order disabled until the quote-request box is ticked', () => {
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

  expect(screen.getByRole('button', { name: /place order/i })).toBeDisabled();
  fireEvent.click(screen.getByRole('checkbox'));
  expect(screen.getByRole('button', { name: /place order/i })).toBeEnabled();
});

// createQuote rejects server-side (unpublished product, raised MOQ, removed
// variant, stale artwork ref, past needed_by, ...) and the store records the
// real 422 reason in `actionError`. The page used to discard it and show a
// hardcoded generic string, leaving the buyer with no idea what to fix - and
// the persisted cart would reproduce the same failure forever.
it('shows the store\'s specific rejection reason when the order is rejected', async () => {
  renderCheckoutAsBuyer();
  useQuoteStore.setState({
    createQuote: async () => {
      useQuoteStore.setState({ actionError: "The product 'Custom Mug' is no longer available." } as any);
      return null;
    },
  } as any);

  fireEvent.click(screen.getByRole('checkbox'));
  fireEvent.click(screen.getByRole('button', { name: /place order/i }));

  expect(
    await screen.findByText("The product 'Custom Mug' is no longer available."),
  ).toBeInTheDocument();
  expect(screen.queryByText(/review your cart and try again/i)).not.toBeInTheDocument();
});

// No specific reason from the store (e.g. a network failure with no server
// message) - the generic fallback must still show rather than a blank alert.
it('falls back to the generic message when the store has no specific reason', async () => {
  renderCheckoutAsBuyer();
  useQuoteStore.setState({
    createQuote: async () => null,
  } as any);

  fireEvent.click(screen.getByRole('checkbox'));
  fireEvent.click(screen.getByRole('button', { name: /place order/i }));

  expect(
    await screen.findByText(/could not place your order\. please review your cart and try again/i),
  ).toBeInTheDocument();
});
