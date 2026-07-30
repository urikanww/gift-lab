import { describe, expect, it, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';

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

import NeedsAttentionPanel from './NeedsAttentionPanel';
import { useQueueStore } from '../../stores/queueStore';
import { ThemeProvider, ToastProvider } from '../../ui';

function renderPanel() {
  return render(
    <ThemeProvider>
      <ToastProvider>
        <NeedsAttentionPanel />
      </ToastProvider>
    </ThemeProvider>,
  );
}

describe('NeedsAttentionPanel', () => {
  beforeEach(() => {
    useQueueStore.setState({
      needsAttention: [
        {
          id: 9,
          quote_id: 3,
          quote_reference: 'GL-ABC1234567',
          state: 'SHIPPED',
          track: 'UV',
          ready_at: null,
          print_method: null,
          qty: 1,
          consignment_ref: 'NVSG123',
          last_courier_status: 'Delivery unsuccessful — returned',
        } as never,
      ],
      fetchNeedsAttention: vi.fn().mockResolvedValue(undefined),
      resolveReturn: vi.fn().mockResolvedValue(true),
    });
  });

  it('renders returned parcels and reship calls resolveReturn', async () => {
    renderPanel();
    expect(screen.getByText(/GL-ABC1234567/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /reship/i }));
    await waitFor(() =>
      expect(useQueueStore.getState().resolveReturn).toHaveBeenCalledWith(9, 'reship', undefined),
    );
  });

  it('cancel & credit opens a confirm before calling resolveReturn', async () => {
    renderPanel();
    fireEvent.click(screen.getByRole('button', { name: /cancel & credit/i }));
    expect(useQueueStore.getState().resolveReturn).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: /confirm/i }));
    await waitFor(() =>
      expect(useQueueStore.getState().resolveReturn).toHaveBeenCalledWith(9, 'cancel_credit', undefined),
    );
  });

  it('renders nothing when empty', () => {
    useQueueStore.setState({ needsAttention: [] });
    const { container } = renderPanel();
    expect(container).toBeEmptyDOMElement();
  });
});
