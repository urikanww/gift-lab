import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi, beforeEach } from 'vitest';
import ReportsPage from './ReportsPage';
import * as reports from '../lib/reports';

describe('ReportsPage', () => {
  beforeEach(() => {
    vi.spyOn(reports, 'fetchReports').mockResolvedValue({
      revenueTrend: [{ month: '2026-07', bookings: 200, billed: 180 }],
      topProducts: [{ productId: 1, name: 'Pen', units: 100, revenue: 200 }],
      repeatCustomerRate: { activeCompanies: 4, repeatCompanies: 1, rate: 0.25 },
      range: { from: '2026-05-01', to: '2026-07-31' },
    });
  });

  it('renders the trend, top products, repeat rate, and a CSV link', async () => {
    render(<MemoryRouter><ReportsPage /></MemoryRouter>);
    expect(await screen.findByText('Pen')).toBeInTheDocument();
    expect(screen.getByText(/25%/)).toBeInTheDocument();
    expect(screen.getByText('2026-07')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /download csv/i })).toBeInTheDocument();
    await waitFor(() => expect(reports.fetchReports).toHaveBeenCalled());
  });
});
