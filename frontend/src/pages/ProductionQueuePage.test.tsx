import { afterEach, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

// The page only ever needs apiError() to turn a rejection into displayable
// text here (the network itself is never hit - every store action is stubbed
// per test). Mirror the real helper closely enough that an Error's message
// comes through unchanged.
vi.mock('../lib/api', () => ({
  default: { get: vi.fn(), post: vi.fn() },
  apiError: (e: unknown) => (e instanceof Error ? e.message : String(e)),
}));

import { ThemeProvider, ToastProvider } from '../ui';
import ProductionQueuePage from './ProductionQueuePage';
import { useQueueStore } from '../stores/queueStore';
import type { ProductionJob } from '../types';

const initialQueueStore = useQueueStore.getState();

afterEach(() => {
  cleanup();
  useQueueStore.setState(initialQueueStore, true);
});

const job: ProductionJob = {
  id: 5,
  quote_id: 50,
  quote_reference: 'ORDER-5',
  track: '3D',
  state: 'READY',
  ready_at: '2026-07-06T00:00:00Z',
  print_method: 'FDM',
  qty: 3,
};

/** Seed the store with a single job plus no-op lifecycle hooks (the page's
 * mount effect calls fetchQueue/subscribe, and unmount calls unsubscribe -
 * none of that is what these tests are about). Per-test overrides (advance,
 * advanceNext, ...) are what actually get exercised. */
function seed(overrides: Record<string, unknown> = {}) {
  useQueueStore.setState({
    jobs: [job],
    loading: false,
    error: null,
    fetchQueue: async () => {},
    subscribe: () => {},
    unsubscribe: () => {},
    ...overrides,
  } as any);
}

function renderPage() {
  return render(
    <ThemeProvider>
      <ToastProvider>
        <ProductionQueuePage />
      </ToastProvider>
    </ThemeProvider>,
  );
}

// Bug: advanceNext used to swallow its failure into store.error, which
// fetchQueue's own reset then wiped before onScan ever read it - an operator
// scanning a wrong-state job (the 422 SHIPPED-guard) got zero feedback. Now
// advanceNext rejects and onScan catches it directly.
it('toasts the API error when a scan fails to advance a job', async () => {
  const advanceNext = vi.fn().mockRejectedValue(new Error('422 job already SHIPPED'));
  seed({ advanceNext });
  renderPage();

  const input = screen.getByLabelText(/scan to advance/i);
  await userEvent.type(input, '5{Enter}');

  expect(await screen.findByText('422 job already SHIPPED')).toBeInTheDocument();
  expect(advanceNext).toHaveBeenCalledWith(5);
  // The scan field still clears even though the advance failed, so the
  // operator can immediately retry.
  expect(input).toHaveValue('');
});

it('does not toast anything for a successful scan (happy path stays quiet)', async () => {
  const advanceNext = vi.fn().mockResolvedValue(undefined);
  seed({ advanceNext });
  renderPage();

  const input = screen.getByLabelText(/scan to advance/i);
  await userEvent.type(input, '5{Enter}');

  expect(advanceNext).toHaveBeenCalledWith(5);
  expect(screen.queryByRole('alert')).not.toBeInTheDocument();
});

// Same bug, for the button-driven advance path (e.g. "Start production" /
// "Mark shipped" / "Mark delivered") rather than the scanner.
it('toasts the API error when a button-driven advance fails', async () => {
  const advance = vi.fn().mockRejectedValue(new Error('422 job is not READY'));
  seed({ advance });
  renderPage();

  await userEvent.click(screen.getByRole('button', { name: /start production/i }));

  expect(await screen.findByText('422 job is not READY')).toBeInTheDocument();
  expect(advance).toHaveBeenCalledWith(5, 'IN_PRODUCTION', undefined, undefined);
});

// Bug: the "Create NinjaVan shipment" button is disabled on first load for
// every job because addressReady only ever gets populated by opening the
// DeliveryAddressPanel - even a job that already has a saved address renders
// with an unexplained, permanently-greyed primary action. The production job
// resource does not expose address-readiness, so the fix is to explain the
// gate rather than silently leave it disabled.
it('explains why the create-shipment button is disabled before the address panel is opened', async () => {
  seed({ jobs: [{ ...job, state: 'IN_PRODUCTION' }] });
  renderPage();

  const button = screen.getByRole('button', { name: /create ninjavan shipment/i });
  expect(button).toBeDisabled();
  expect(screen.getByText(/open delivery address to confirm before booking/i)).toBeInTheDocument();
});
