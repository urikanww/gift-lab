import { expect, it, afterEach, beforeEach, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import { ThemeProvider } from '../ui';
import CheckoutPage from './CheckoutPage';
import { useCartStore } from '../stores/cartStore';
import { useAuthStore } from '../stores/authStore';
import { useSavedAddressStore } from '../stores/savedAddressStore';
import { useQuoteStore } from '../stores/quoteStore';
import api from '../lib/api';
import { DELIVERY_NOTE_RELIABLE, DELIVERY_NOTE_UNRELIABLE } from '../lib/deliveryCopy';

// Captured once so createQuote can be restored after tests that stub it out.
const originalCreateQuote = useQuoteStore.getState().createQuote;

// Every render mounts CheckoutPage's own refreshEstimate() effect, which hits
// the real axios instance. Left unmocked, that's a real network attempt per
// test whose eventual (slow, real-clock) rejection can land during a *later*
// test and clobber its store state via the shared cartStore singleton. Stub
// it to a harmless default so no test leaves a stray network promise behind;
// individual tests may override the resolved value as needed.
beforeEach(() => {
  vi.spyOn(api, 'post').mockResolvedValue({
    data: { currency: 'SGD', lines: [], subtotal: 0, delivery: 0, total: 0, delivery_reliable: true },
  } as any);
});

afterEach(() => {
  vi.restoreAllMocks();
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

// The delivery disclaimer comes from the shared frontend/src/lib/deliveryCopy
// module (also used by CartPage) so the two surfaces can't drift apart. The
// page's mount effect fires the store's real refreshEstimate() against the
// axios instance, so api.post is stubbed rather than hand-setting the store -
// letting the natural flow populate `estimate` avoids racing an unmocked
// network rejection that would otherwise clobber it with an error state.
it('renders the shared delivery disclaimer from lib/deliveryCopy when expanded', async () => {
  const reliableEstimate = {
    currency: 'SGD',
    lines: [{ unit_price: 10, line_total: 10 }],
    subtotal: 10,
    delivery: 5,
    total: 15,
    delivery_reliable: true,
  };
  const postSpy = vi.spyOn(api, 'post').mockResolvedValue({ data: reliableEstimate } as any);

  renderCheckoutAsBuyer();

  fireEvent.click(await screen.findByRole('button', { name: /delivery/i }));
  expect(await screen.findByText(DELIVERY_NOTE_RELIABLE)).toBeInTheDocument();

  const unreliableEstimate = { ...reliableEstimate, delivery: 0, total: 10, delivery_reliable: false };
  postSpy.mockResolvedValue({ data: unreliableEstimate } as any);
  await useCartStore.getState().refreshEstimate();

  expect(await screen.findByText(DELIVERY_NOTE_UNRELIABLE)).toBeInTheDocument();
});
