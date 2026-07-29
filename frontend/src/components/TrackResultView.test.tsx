import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { TrackResultView } from './TrackResultView';
import type { TrackResult } from '../types';

const basePayload: TrackResult = {
  reference: 'GL-ABC123',
  stage: 'SHIPPED',
  stage_label: 'Shipped',
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
  items_total: 1,
  items_completed: 1,
    items: [],
  shipments: [],
};

describe('TrackResultView', () => {
  it('renders the order-placed date alongside last updated', () => {
    render(<TrackResultView result={basePayload} />);

    expect(screen.getByText(/Order placed/)).toBeInTheDocument();
    expect(screen.getByText(/Last updated/)).toBeInTheDocument();
  });

  it('renders a shipment courier status and its timestamp', () => {
    const payload: TrackResult = {
      ...basePayload,
      shipments: [
        {
          carrier_label: 'NinjaVan',
          tracking_url: 'https://ninjavan.co/track/abc',
          ref: 'NV123456',
          status: 'Out for delivery',
          status_at: '2026-07-05T09:00:00Z',
          delivered_at: null,
        },
      ],
    };

    render(<TrackResultView result={payload} />);

    expect(screen.getByRole('link', { name: /Track with NinjaVan \(NV123456\)/ })).toHaveAttribute(
      'href',
      'https://ninjavan.co/track/abc',
    );
    expect(screen.getByText(/Out for delivery/)).toBeInTheDocument();
  });

  it('shows "Delivered {date}" once a shipment has a delivered_at', () => {
    const payload: TrackResult = {
      ...basePayload,
      shipments: [
        {
          carrier_label: 'NinjaVan',
          tracking_url: 'https://ninjavan.co/track/abc',
          ref: 'NV123456',
          status: 'Delivered',
          status_at: '2026-07-06T09:00:00Z',
          delivered_at: '2026-07-06T09:00:00Z',
        },
      ],
    };

    render(<TrackResultView result={payload} />);

    expect(screen.getByText(/^Delivered /)).toBeInTheDocument();
  });
});
