import { afterEach, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';

// Every store action is stubbed per test - the network is never actually hit.
vi.mock('../lib/api', () => ({
  default: { get: vi.fn(), post: vi.fn() },
  apiError: (e: unknown) => (e instanceof Error ? e.message : String(e)),
  ensureCsrf: async () => {},
}));

import { ThemeProvider, ToastProvider } from '../ui';
import ProcurementPage from './ProcurementPage';
import { useProcurementStore } from '../stores/procurementStore';
import type { ReconfirmAlert } from '../stores/procurementStore';

const initialProcurementStore = useProcurementStore.getState();

afterEach(() => {
  cleanup();
  useProcurementStore.setState(initialProcurementStore, true);
});

const alert = (overrides: Partial<ReconfirmAlert> = {}): ReconfirmAlert => ({
  line_item_id: 5,
  quote_id: 9,
  quote_reference: 'ORD-1',
  reason: 'qty_short',
  ordered_qty: 10,
  procured_qty: 4,
  unit_price: '15.00',
  procured_price: null,
  ...overrides,
});

/** Seed the store with lifecycle no-ops (the page's mount effect calls
 * fetchAlerts/subscribe, unmount calls unsubscribe - none of that is what
 * these tests are about) plus per-test overrides. */
function seed(overrides: Record<string, unknown> = {}) {
  useProcurementStore.setState({
    alerts: [alert()],
    loading: false,
    error: null,
    fetchAlerts: async () => {},
    subscribe: () => {},
    unsubscribe: () => {},
    reconfirm: vi.fn(),
    ...overrides,
  } as any);
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

// Bug: the page rendered "No lines awaiting reconfirmation." the instant
// alerts.length was 0, including during the initial fetch - staff saw "all
// clear" for a moment before the real (possibly non-empty) list arrived.
it('does not show the empty state while the initial fetch is loading', () => {
  seed({ alerts: [], loading: true });
  renderPage();

  expect(screen.queryByText(/no lines awaiting reconfirmation/i)).not.toBeInTheDocument();
});

it('does not show the empty state alongside a load error', () => {
  seed({ alerts: [], loading: false, error: 'Could not load the desk.' });
  renderPage();

  expect(screen.queryByText(/no lines awaiting reconfirmation/i)).not.toBeInTheDocument();
  expect(screen.getByRole('alert')).toHaveTextContent('Could not load the desk.');
});

it('shows the empty state once loading finishes with no error and no alerts', () => {
  seed({ alerts: [], loading: false, error: null });
  renderPage();

  expect(screen.getByText(/no lines awaiting reconfirmation/i)).toBeInTheDocument();
});

// Bug: the card rendered the raw backend enum ("qty_short") instead of a
// human label.
it('shows a humanized reason badge instead of the raw enum', () => {
  seed({ alerts: [alert({ reason: 'qty_short' })] });
  renderPage();

  expect(screen.getByText('Quantity short')).toBeInTheDocument();
  expect(screen.queryByText('qty_short')).not.toBeInTheDocument();
});

it('humanizes the price_jumped reason too', () => {
  seed({ alerts: [alert({ reason: 'price_jumped', procured_qty: 10 })] });
  renderPage();

  expect(screen.getByText('Price jumped')).toBeInTheDocument();
});

// Bug: the backend rejects `approve` outright when procured_qty < 1
// (reconfirmLine throws "Nothing could be sourced..."), but the button was
// always enabled, so staff hit a 422 with no warning.
it('disables Accept as-is when nothing could be procured', () => {
  seed({ alerts: [alert({ procured_qty: 0 })] });
  renderPage();

  expect(screen.getByRole('button', { name: /accept as-is/i })).toBeDisabled();
  expect(screen.getByText(/use drop instead/i)).toBeInTheDocument();
});

it('enables Accept as-is once at least one unit was procured', () => {
  seed({ alerts: [alert({ procured_qty: 4 })] });
  renderPage();

  expect(screen.getByRole('button', { name: /accept as-is/i })).not.toBeDisabled();
});

// The consequence differs by reason (per QuoteService::reconfirmLine's approve
// branch): a qty shortfall re-totals down; a price jump is absorbed, not
// passed to the buyer. The card must say which one applies.
it('labels the qty_short consequence of accepting as-is', () => {
  seed({ alerts: [alert({ reason: 'qty_short', procured_qty: 4 })] });
  renderPage();

  expect(screen.getByText(/bill only what we can supply/i)).toBeInTheDocument();
});

it('labels the price_jumped consequence of accepting as-is', () => {
  seed({ alerts: [alert({ reason: 'price_jumped', procured_qty: 10 })] });
  renderPage();

  expect(screen.getByText(/absorb the price rise/i)).toBeInTheDocument();
});

// Bug: run() used to infer success from alerts.length shrinking. Now the
// toast is driven by the store's real returned outcome.
it('toasts success off the real outcome returned by reconfirm', async () => {
  const reconfirm = vi.fn().mockResolvedValue({
    success: true,
    line_state: 'DROPPED',
    quote_reference: 'ORD-1',
  });
  seed({ reconfirm });
  renderPage();

  await userEvent.click(screen.getByRole('button', { name: /drop line/i }));

  expect(await screen.findByText('Line #5 dropped.')).toBeInTheDocument();
  expect(reconfirm).toHaveBeenCalledWith(5, 'drop', undefined);
});

it('toasts a danger message when reconfirm reports failure', async () => {
  const reconfirm = vi.fn().mockResolvedValue({
    success: false,
    error: 'Nothing could be sourced for this line.',
  });
  seed({ reconfirm });
  renderPage();

  await userEvent.click(screen.getByRole('button', { name: /drop line/i }));

  expect(await screen.findByText('Could not resolve line')).toBeInTheDocument();
  expect(screen.getByText('Nothing could be sourced for this line.')).toBeInTheDocument();
});

// Bug: an amend that re-blocks (still AWAITING_RECONFIRM) used to be treated
// as a success because the request itself didn't throw.
it('warns instead of celebrating when an amend re-blocks the line', async () => {
  const reconfirm = vi.fn().mockResolvedValue({
    success: true,
    line_state: 'AWAITING_RECONFIRM',
  });
  seed({ reconfirm, alerts: [alert({ procured_qty: 4 })] });
  renderPage();

  await userEvent.type(screen.getByLabelText(/amend qty/i), '7');
  await userEvent.type(screen.getByLabelText(/unit price/i), '16');
  await userEvent.click(screen.getByRole('button', { name: /amend & re-procure/i }));

  expect(await screen.findByText(/still needs a decision/i)).toBeInTheDocument();
  expect(screen.queryByText(/re-procured\./i)).not.toBeInTheDocument();
});
