import { expect, it, afterEach, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { ThemeProvider } from '../ui';
import BuyerDashboardPage from './BuyerDashboardPage';
import { useQuoteStore } from '../stores/quoteStore';
import { useAuthStore } from '../stores/authStore';

const baseSummary = {
  active: 2,
  awaiting: 1,
  in_production: 1,
  completed: 5,
  total: 8,
  awaiting_orders: [{ id: 12, state: 'PROOFING' as const }],
};

function seed(summary = baseSummary) {
  useAuthStore.setState({
    user: { id: 1, name: 'Rachel Tan', role: 'buyer', company_id: 1, company: { id: 1, name: 'Acme' } } as any,
    status: 'ready',
  } as any);
  useQuoteStore.setState({
    summary,
    quotes: [{ id: 12, state: 'PROOFING', currency: 'SGD', total: '457.00' }] as any,
    loading: false,
    fetchSummary: vi.fn(),
    fetchQuotes: vi.fn(),
  } as any);
}

function renderPage() {
  return render(
    <ThemeProvider>
      <MemoryRouter>
        <BuyerDashboardPage />
      </MemoryRouter>
    </ThemeProvider>,
  );
}

afterEach(() => {
  useQuoteStore.setState({ summary: null, quotes: [] } as any);
});

it('greets the buyer and shows order stat tiles', () => {
  seed();
  renderPage();
  expect(screen.getByRole('heading', { name: /welcome back, rachel/i })).toBeInTheDocument();
  expect(screen.getByText('Active')).toBeInTheDocument();
  expect(screen.getByText('In production')).toBeInTheDocument();
  expect(screen.getByText('Completed')).toBeInTheDocument();
});

it('shows the awaiting-you callout with a proof CTA when an order needs a decision', () => {
  seed();
  renderPage();
  expect(screen.getByText(/proof ready to approve/i)).toBeInTheDocument();
  expect(screen.getByRole('link', { name: /review proof/i })).toBeInTheDocument();
});

it('hides the awaiting-you callout when nothing needs a decision', () => {
  seed({ ...baseSummary, awaiting: 0, awaiting_orders: [] });
  renderPage();
  // The stat tile label still exists; the callout heading (an h2) must not.
  expect(screen.queryByRole('heading', { name: /^awaiting you$/i })).not.toBeInTheDocument();
});

it('links to the full orders list', () => {
  seed();
  renderPage();
  expect(screen.getByRole('link', { name: /view all orders/i })).toBeInTheDocument();
});
