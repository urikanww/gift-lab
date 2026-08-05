import { afterEach, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';

vi.mock('../lib/api', () => ({
  default: { get: vi.fn(), post: vi.fn() },
  apiError: (e: unknown) => (e instanceof Error ? e.message : String(e)),
  ensureCsrf: async () => {},
}));

import { ThemeProvider, ToastProvider } from '../ui';
import ProcurementPage from './ProcurementPage';
import { useProcurementStore, type BuyListRow } from '../stores/procurementStore';

const initialStore = useProcurementStore.getState();

afterEach(() => {
  cleanup();
  useProcurementStore.setState(initialStore, true);
});

const row = (overrides: Partial<BuyListRow> = {}): BuyListRow => ({
  id: 1,
  product_id: 9,
  quote_id: 5,
  quote_reference: 'GL-5',
  qty: 4,
  product: { name: 'Blue Mug', class: 'SCRAPED_UV', source_url: 's', affiliate_url: 'https://shopee/x' },
  ...overrides,
});

function seed(overrides: Record<string, unknown> = {}) {
  useProcurementStore.setState({
    buyList: [row()],
    loading: false,
    error: null,
    fetchBuyList: async () => {},
    markBought: vi.fn(),
    markProductBought: vi.fn(),
    ...overrides,
  } as never);
}

function renderPage() {
  return render(
    <MemoryRouter>
      <ThemeProvider>
        <ToastProvider>
          <ProcurementPage />
        </ToastProvider>
      </ThemeProvider>
    </MemoryRouter>,
  );
}

it('shows the empty state once loading finishes with no rows', () => {
  seed({ buyList: [], loading: false, error: null });
  renderPage();

  expect(screen.getByText(/nothing to buy/i)).toBeInTheDocument();
});

it('does not show the empty state while the initial fetch is loading', () => {
  seed({ buyList: [], loading: true });
  renderPage();

  expect(screen.queryByText(/nothing to buy/i)).not.toBeInTheDocument();
});

it('renders a marketplace row with the affiliate buy link (product view)', () => {
  seed({ buyList: [row()] });
  renderPage();

  expect(screen.getByText('Blue Mug')).toBeInTheDocument();
  expect(screen.getByRole('link', { name: /buy/i })).toHaveAttribute('href', 'https://shopee/x');
  expect(screen.getByRole('button', { name: /mark all bought/i })).toBeInTheDocument();
});

it('uses the source page as the buy link for a 3D item', () => {
  seed({
    buyList: [row({ product: { name: 'Dragon', class: 'MODEL_3D', source_url: 'https://maker/y', affiliate_url: null } })],
  });
  renderPage();

  expect(screen.getByRole('link', { name: /buy/i })).toHaveAttribute('href', 'https://maker/y');
});

it('marks a whole product bought from the product view', async () => {
  const markProductBought = vi.fn().mockResolvedValue(undefined);
  seed({ markProductBought });
  renderPage();

  await userEvent.click(screen.getByRole('button', { name: /mark all bought/i }));

  expect(markProductBought).toHaveBeenCalledWith(9);
});

it('marks a single line bought from the order view', async () => {
  const markBought = vi.fn().mockResolvedValue(undefined);
  seed({ markBought });
  renderPage();

  await userEvent.click(screen.getByRole('button', { name: /by order/i }));
  await userEvent.click(screen.getByRole('button', { name: /^bought$/i }));

  expect(markBought).toHaveBeenCalledWith(1);
});
