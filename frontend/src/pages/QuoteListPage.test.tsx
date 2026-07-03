import { afterEach, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { ThemeProvider } from '../ui';
import QuoteListPage from './QuoteListPage';
import { useAuthStore } from '../stores/authStore';
import { useQuoteStore } from '../stores/quoteStore';

const initialQuoteStore = useQuoteStore.getState();
const initialAuthStore = useAuthStore.getState();
afterEach(() => {
  useQuoteStore.setState(initialQuoteStore, true);
  useAuthStore.setState(initialAuthStore, true);
});

const sampleQuote = {
  id: 42,
  company_id: 7,
  company_name: 'Acme Gifts Pte Ltd',
  state: 'SENT',
  currency: 'SGD',
  subtotal: '100.00',
  delivery: '5.00',
  total: '105.00',
  price_snapshot_at: null,
  notes: null,
  created_at: '2026-07-01T00:00:00Z',
} as any;

function seedQuotes() {
  useQuoteStore.setState({
    quotes: [sampleQuote],
    loading: false,
    error: null,
    page: 1,
    lastPage: 1,
    fetchQuotes: async () => {},
  } as any);
}

function renderPage() {
  return render(
    <ThemeProvider>
      <MemoryRouter>
        <QuoteListPage />
      </MemoryRouter>
    </ThemeProvider>,
  );
}

it('shows the company column and staff copy for staff', () => {
  seedQuotes();
  useAuthStore.setState({
    user: { id: 1, company_id: null, name: 'Ops', email: 'ops@x.test', role: 'staff_admin' },
    status: 'ready',
    error: null,
  });

  renderPage();

  expect(screen.getByText('Company')).toBeInTheDocument();
  // Rendered in both the desktop table and the mobile card list.
  expect(screen.getAllByText('Acme Gifts Pte Ltd').length).toBeGreaterThanOrEqual(1);
  expect(screen.getByText(/across every company/i)).toBeInTheDocument();
  // Staff keep the operational "Quotes" title.
  expect(screen.getByRole('heading', { name: 'Quotes' })).toBeInTheDocument();
});

it('hides the company column and keeps buyer copy for buyers', () => {
  seedQuotes();
  useAuthStore.setState({
    user: { id: 2, company_id: 7, name: 'Ada', email: 'ada@x.test', role: 'buyer' },
    status: 'ready',
    error: null,
  });

  renderPage();

  expect(screen.queryByText('Company')).not.toBeInTheDocument();
  expect(screen.queryByText('Acme Gifts Pte Ltd')).not.toBeInTheDocument();
  expect(screen.getByText(/track your gift orders/i)).toBeInTheDocument();
  // Buyers arrive via the "My Orders" nav item — the title matches it.
  expect(screen.getByRole('heading', { name: 'My Orders' })).toBeInTheDocument();
  expect(screen.queryByRole('heading', { name: 'Quotes' })).not.toBeInTheDocument();
});
