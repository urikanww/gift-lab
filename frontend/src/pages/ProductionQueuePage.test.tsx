import { afterEach, expect, it, vi } from 'vitest';
import { act, cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

// The page only ever needs apiError() to turn a rejection into displayable
// text here (the network itself is never hit - every store action is stubbed
// per test). Mirror the real helper closely enough that an Error's message
// comes through unchanged.
vi.mock('../lib/api', () => ({
  default: { get: vi.fn(), post: vi.fn() },
  apiError: (e: unknown) => (e instanceof Error ? e.message : String(e)),
}));

// The camera scanner module is dynamically imported on demand
// (`await import('../lib/scan')`); mock it so tests can capture the callback
// the page registers with `startCameraScan` and invoke it directly, the same
// way a decoded QR frame would.
const scanMock = vi.hoisted(() => ({ startCameraScan: vi.fn() }));
vi.mock('../lib/scan', () => scanMock);

import { ThemeProvider, ToastProvider } from '../ui';
import ProductionQueuePage from './ProductionQueuePage';
import { useQueueStore } from '../stores/queueStore';
import api from '../lib/api';
import type { ProductionJob } from '../types';

const initialQueueStore = useQueueStore.getState();

afterEach(() => {
  cleanup();
  useQueueStore.setState(initialQueueStore, true);
  // A per-test api.get resolution must not leak into the next test (which
  // relies on the bare vi.fn() returning undefined).
  vi.mocked(api.get).mockReset();
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

// P6: booking a NinjaVan shipment is billable, so the button opens a confirm
// modal (which shows the delivery address) rather than firing on one click - and
// it is no longer disabled behind an opaque address-readiness gate (L23).
it('confirms in a modal before booking a NinjaVan shipment, then books on confirm', async () => {
  vi.mocked(api.get).mockResolvedValue({ data: { data: [] } } as any);
  const createShipment = vi.fn().mockResolvedValue({ consignment_ref: 'SP1', tracking_url: null });
  seed({ jobs: [{ ...job, state: 'IN_PRODUCTION' }], createShipment });
  renderPage();

  const button = screen.getByRole('button', { name: /create ninjavan shipment/i });
  expect(button).toBeEnabled();

  await userEvent.click(button);

  // A confirm dialog appears; nothing is booked until it's confirmed.
  expect(await screen.findByRole('dialog')).toBeInTheDocument();
  expect(createShipment).not.toHaveBeenCalled();

  await userEvent.click(screen.getByRole('button', { name: /book shipment/i }));
  await waitFor(() => expect(createShipment).toHaveBeenCalledWith(5));
});

// Bug: the camera scan callback was registered once, when the camera turned
// on (`startCameraScan('qr-reader', (v) => void onScan(v))`), closing over
// that render's `onScan` - and thus that render's `jobs` snapshot. A job that
// arrived via realtime AFTER the camera started was then wrongly rejected as
// "not on the queue" by the stale array. onScan must consult the store's live
// jobs, not the array captured at camera-start time.
it('accepts a job that arrives via realtime after the camera starts (no stale jobs snapshot)', async () => {
  let cameraCallback: ((v: string) => void) | null = null;
  scanMock.startCameraScan.mockImplementation(async (_id: string, cb: (v: string) => void) => {
    cameraCallback = cb;
    return async () => {};
  });
  const advanceNext = vi.fn().mockResolvedValue(undefined);
  seed({ advanceNext });
  renderPage();

  await userEvent.click(screen.getByRole('button', { name: /scan with camera/i }));
  await waitFor(() => expect(cameraCallback).not.toBeNull());

  // A new job (#99) arrives on the shared queue via realtime AFTER the camera
  // was already turned on - i.e. after `onScan` was captured by the callback.
  const arrivedJob = { ...job, id: 99, quote_reference: 'ORDER-99' };
  act(() => {
    useQueueStore.setState({ jobs: [job, arrivedJob] });
  });

  cameraCallback!('99');

  await waitFor(() => expect(advanceNext).toHaveBeenCalledWith(99));
  expect(screen.queryByText(/not on the queue/i)).not.toBeInTheDocument();
});
