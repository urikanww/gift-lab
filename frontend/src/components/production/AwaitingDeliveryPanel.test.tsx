import { beforeEach, expect, it, vi } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ProductionJob } from '../../types';

const { get, post } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock('../../lib/api', () => ({
  default: { get, post },
  apiError: (e: unknown) => String(e),
  ensureCsrf: vi.fn(),
}));
vi.mock('../../lib/echo', () => ({
  joinSharedPrivate: () => ({ listen: vi.fn(), stopListening: vi.fn() }),
  leaveSharedPrivate: vi.fn(),
  onEchoReconnect: () => () => {},
}));

import { useQueueStore } from '../../stores/queueStore';
import { ThemeProvider, ToastProvider } from '../../ui';
import AwaitingDeliveryPanel from './AwaitingDeliveryPanel';

const shippedJob: ProductionJob = {
  id: 7,
  quote_id: 10,
  quote_reference: 'ORD-ABC',
  track: 'UV',
  state: 'SHIPPED',
  ready_at: '2026-07-06T00:00:00Z',
  consignment_ref: 'NVSG123',
  carrier_label: 'NinjaVan',
  tracking_url: 'https://track.test/NVSG123',
  print_method: 'UV',
  qty: 5,
};

beforeEach(() => {
  get.mockReset();
  post.mockReset();
  get.mockResolvedValue({ data: { data: [shippedJob] } }); // fetchInTransit on mount
  useQueueStore.setState({ inTransit: [], inTransitLoading: false });
});

function renderPanel() {
  render(
    <ThemeProvider><ToastProvider>
      <AwaitingDeliveryPanel />
    </ToastProvider></ThemeProvider>,
  );
}

it('lists in-transit parcels and marks one delivered via the confirm dialog', async () => {
  post.mockResolvedValue({ data: { data: { ...shippedJob, state: 'CLOSED' } } });
  renderPanel();

  // Loaded from the in-transit fetch.
  expect(await screen.findByText('ORD-ABC')).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'NVSG123' })).toHaveAttribute('href', 'https://track.test/NVSG123');

  await userEvent.click(screen.getByRole('button', { name: /mark delivered/i }));
  const dialog = await screen.findByRole('dialog');
  await userEvent.click(within(dialog).getByRole('button', { name: /mark delivered/i }));

  await waitFor(() =>
    expect(post).toHaveBeenCalledWith('/production-jobs/7/mark-delivered', {}),
  );
  // The parcel drops off the list once confirmed.
  await waitFor(() => expect(screen.queryByText('ORD-ABC')).not.toBeInTheDocument());
});

it('renders nothing when no parcels are in transit', async () => {
  get.mockResolvedValue({ data: { data: [] } });
  const { container } = render(
    <ThemeProvider><ToastProvider>
      <AwaitingDeliveryPanel />
    </ToastProvider></ThemeProvider>,
  );
  await waitFor(() => expect(get).toHaveBeenCalledWith('/production-jobs/in-transit'));
  expect(container).toBeEmptyDOMElement();
});
