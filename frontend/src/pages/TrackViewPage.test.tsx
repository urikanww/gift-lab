import { describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import type { TrackResult } from '../types';

const { get } = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock('../lib/api', () => ({
  default: { get },
  // Return empty so the page falls back to its friendly invalid-link copy.
  apiError: () => '',
  ensureCsrf: vi.fn(),
}));

import TrackViewPage from './TrackViewPage';

const payload: TrackResult = {
  reference: 'GL-ABC123',
  stage: 'PRODUCING',
  stage_label: 'In production',
  cancelled: false,
  stages: [
    { code: 'CONFIRMED', label: 'Confirmed' },
    { code: 'PRODUCING', label: 'In production' },
    { code: 'SHIPPED', label: 'Shipped' },
  ],
  placed_at: '2026-07-01T00:00:00Z',
  updated_at: '2026-07-05T00:00:00Z',
  needed_by: '2026-07-20',
  items_total: 3,
  items_completed: 1,
  shipments: [],
};

function renderAt(path: string) {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/track/view" element={<TrackViewPage />} />
      </Routes>
    </MemoryRouter>,
  );
}

// NOTE: no beforeEach mockClear/mockReset here. Clearing the mock between tests
// interferes with vitest 2.x's internal promise-result tracking and makes the
// rejected-fetch case surface as a (false) unhandled rejection. Each test sets
// its own mock implementation, so no reset is needed.
describe('TrackViewPage', () => {
  it('renders the signed tracker payload', async () => {
    get.mockResolvedValue({ data: payload });

    renderAt('/track/view?code=GL-ABC123&signature=abc');

    await waitFor(() => expect(screen.getByText('GL-ABC123')).toBeInTheDocument());
    // stage_label shows in both the badge and the active stage row.
    expect(screen.getAllByText('In production').length).toBeGreaterThan(0);
    // Enriched partial-progress line renders.
    expect(screen.getByText('1 of 3 items shipped')).toBeInTheDocument();
    // The signed route receives the exact query string.
    expect(get).toHaveBeenCalledWith('/track/view?code=GL-ABC123&signature=abc');
  });

  it('shows the invalid-link message when the fetch fails', async () => {
    get.mockRejectedValue(new Error('boom'));

    renderAt('/track/view?code=GL-ABC123&signature=bad');

    await waitFor(() =>
      expect(screen.getByText(/this tracking link is invalid or has expired/i)).toBeInTheDocument(),
    );
    expect(screen.getByRole('link', { name: /track manually instead/i })).toBeInTheDocument();
  });
});
