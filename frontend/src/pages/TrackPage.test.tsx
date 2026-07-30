import { afterEach, describe, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { TrackResult } from '../types';

const { post } = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock('../lib/api', () => ({
  default: { post },
  apiError: (e: unknown) => String(e),
  ensureCsrf: vi.fn(),
}));

// Only the websocket transport is faked. Capturing the listener registered on
// the public track.{reference} channel lets tests fire a synthetic
// `.order.tracking-updated` event and assert on the REAL merge logic in
// TrackPage, matching the pattern used for echo-backed stores elsewhere.
let capturedListener: ((e: unknown) => void) | null = null;
let leftChannel: string | null = null;
vi.mock('../lib/echo', () => ({
  getEcho: () => ({
    channel: (name: string) => ({
      listen: (_event: string, cb: (e: unknown) => void) => {
        capturedListener = cb;
        return { listen: () => {} };
      },
      _name: name,
    }),
    leaveChannel: (name: string) => {
      leftChannel = name;
    },
  }),
}));

import TrackPage from './TrackPage';

const payload: TrackResult = {
  reference: 'GL-ABC123',
  stage: 'PRODUCING',
  stage_label: 'In production',
  cancelled: false,
  stages: [
    { code: 'CONFIRMED', label: 'Confirmed' },
    { code: 'PRODUCING', label: 'In production' },
    { code: 'SHIPPED', label: 'Shipped' },
    { code: 'DELIVERED', label: 'Delivered' },
  ],
  placed_at: '2026-07-01T00:00:00Z',
  updated_at: '2026-07-05T00:00:00Z',
  needed_by: null,
  items_total: 2,
  items_completed: 0,
    items: [],
  shipments: [],
};

async function trackAnOrder() {
  render(<TrackPage />);
  fireEvent.change(screen.getByLabelText(/order reference/i), { target: { value: 'GL-ABC123' } });
  fireEvent.change(screen.getByLabelText(/order email/i), { target: { value: 'buyer@example.com' } });
  fireEvent.click(screen.getByRole('button', { name: /track order/i }));
  await screen.findByText('GL-ABC123');
}

describe('TrackPage', () => {
  afterEach(() => {
    cleanup();
    post.mockClear();
    capturedListener = null;
    leftChannel = null;
  });

  it('posts the unified reference (not tracking_code) and renders the result on success', async () => {
    post.mockResolvedValue({ data: payload });
    await trackAnOrder();

    expect(post).toHaveBeenCalledWith('/track', {
      reference: 'GL-ABC123',
      email: 'buyer@example.com',
    });
    // The success payload's reference is rendered in the result view.
    expect(await screen.findByText('GL-ABC123')).toBeInTheDocument();
  });

  it('applies live shipments + item counts from an OrderTrackingUpdated broadcast without a refetch', async () => {
    post.mockResolvedValue({ data: payload });
    await trackAnOrder();

    expect(capturedListener).not.toBeNull();
    // Only one fetch so far - the update below must come from the socket event,
    // not a second /track call.
    expect(post).toHaveBeenCalledTimes(1);

    act(() => {
      capturedListener?.({
        stage: 'SHIPPED',
        stage_label: 'Shipped',
        cancelled: false,
        updated_at: '2026-07-06T00:00:00Z',
        shipments: [
          {
            carrier_label: 'NinjaVan',
            tracking_url: 'https://ninjavan.co/track/xyz',
            ref: 'NV999',
            status: 'Out for delivery',
            status_at: '2026-07-06T08:00:00Z',
            delivered_at: null,
          },
        ],
        items_completed: 1,
        items_total: 2,
      });
    });

    expect(await screen.findByRole('link', { name: /Track with NinjaVan \(NV999\)/ })).toHaveAttribute(
      'href',
      'https://ninjavan.co/track/xyz',
    );
    expect(screen.getByText(/1 of 2 items shipped/)).toBeInTheDocument();
    expect(screen.getByText(/Out for delivery/)).toBeInTheDocument();
    expect(post).toHaveBeenCalledTimes(1);
  });

  it('keeps the previous shipments/counts when a broadcast omits them', async () => {
    post.mockResolvedValue({
      data: {
        ...payload,
        items_completed: 1,
        items_total: 2,
        shipments: [
          {
            carrier_label: 'NinjaVan',
            tracking_url: 'https://ninjavan.co/track/xyz',
            ref: 'NV999',
            status: 'Out for delivery',
            status_at: '2026-07-06T08:00:00Z',
            delivered_at: null,
          },
        ],
      },
    });
    await trackAnOrder();

    act(() => {
      // Stage-only event (fields absent) must not wipe the shipment/count state.
      capturedListener?.({
        stage: 'DELIVERED',
        stage_label: 'Delivered',
        cancelled: false,
        updated_at: '2026-07-07T00:00:00Z',
      });
    });

    await screen.findByText(/1 of 2 items shipped/);
    expect(screen.getAllByText('Delivered').length).toBeGreaterThan(0);
    expect(screen.getByRole('link', { name: /Track with NinjaVan \(NV999\)/ })).toBeInTheDocument();
    expect(screen.getByText(/1 of 2 items shipped/)).toBeInTheDocument();
  });

  it('leaves the public track channel on unmount', async () => {
    post.mockResolvedValue({ data: payload });
    const { unmount } = render(<TrackPage />);
    fireEvent.change(screen.getByLabelText(/order reference/i), { target: { value: 'GL-ABC123' } });
    fireEvent.change(screen.getByLabelText(/order email/i), { target: { value: 'buyer@example.com' } });
    fireEvent.click(screen.getByRole('button', { name: /track order/i }));
    await screen.findByText('GL-ABC123');

    unmount();
    expect(leftChannel).toBe('track.GL-ABC123');
  });
});
