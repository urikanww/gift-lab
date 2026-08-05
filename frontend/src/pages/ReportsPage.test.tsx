import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi, beforeEach } from 'vitest';
import ReportsPage from './ReportsPage';
import * as reports from '../lib/reports';

const PAYLOAD: reports.ReportsPayload = {
  revenueTrend: [{ month: '2026-07', bookings: 200, billed: 180 }],
  topProducts: [{ productId: 1, name: 'Pen', units: 100, revenue: 200 }],
  repeatCustomerRate: { activeCompanies: 4, repeatCompanies: 1, rate: 0.25 },
  range: { from: '2026-05-01', to: '2026-07-31' },
};

describe('ReportsPage', () => {
  beforeEach(() => {
    vi.spyOn(reports, 'fetchReports').mockResolvedValue(PAYLOAD);
  });

  const fetchReportsMock = () => vi.mocked(reports.fetchReports);

  it('renders the trend, top products, repeat rate, and a CSV link', async () => {
    render(<MemoryRouter><ReportsPage /></MemoryRouter>);
    expect(await screen.findByText('Pen')).toBeInTheDocument();
    expect(screen.getByText(/25%/)).toBeInTheDocument();
    expect(screen.getByText('2026-07')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /download csv/i })).toBeInTheDocument();
    await waitFor(() => expect(reports.fetchReports).toHaveBeenCalled());
  });

  it('shows an error banner (not stale numbers) with a working retry when the initial fetch fails', async () => {
    fetchReportsMock().mockRejectedValueOnce(new Error('network down'));
    render(<MemoryRouter><ReportsPage /></MemoryRouter>);

    expect(await screen.findByRole('alert')).toHaveTextContent(/could not load reports/i);
    expect(screen.queryByText('Pen')).not.toBeInTheDocument();

    // The range control and CSV link stay usable even while data can't load.
    expect(screen.getByRole('link', { name: /download csv/i })).toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: /retry/i }));
    expect(await screen.findByText('Pen')).toBeInTheDocument();
  });

  it('replaces the previous range with an error banner - never stale numbers - when a refetch fails', async () => {
    render(<MemoryRouter><ReportsPage /></MemoryRouter>);
    expect(await screen.findByText('Pen')).toBeInTheDocument();

    fetchReportsMock().mockRejectedValueOnce(new Error('network down'));
    await userEvent.selectOptions(screen.getByLabelText('Date range'), 'This month');

    expect(await screen.findByRole('alert')).toHaveTextContent(/could not load reports/i);
    // The prior range's numbers must not linger behind/under the error banner.
    expect(screen.queryByText('Pen')).not.toBeInTheDocument();
  });

  it('offers Last month and Custom presets alongside the existing ones', async () => {
    render(<MemoryRouter><ReportsPage /></MemoryRouter>);
    await screen.findByText('Pen');

    const select = screen.getByLabelText('Date range');
    expect(screen.getByRole('option', { name: 'Last month' })).toBeInTheDocument();
    expect(screen.getByRole('option', { name: 'Custom' })).toBeInTheDocument();
    void select;
  });

  it('refetches with the entered dates when Custom is selected', async () => {
    render(<MemoryRouter><ReportsPage /></MemoryRouter>);
    await screen.findByText('Pen');
    fetchReportsMock().mockClear();

    await userEvent.selectOptions(screen.getByLabelText('Date range'), 'Custom');

    const fromInput = screen.getByLabelText(/from/i);
    const toInput = screen.getByLabelText(/to/i);

    fireEvent.change(fromInput, { target: { value: '2026-02-01' } });
    fireEvent.change(toInput, { target: { value: '2026-02-28' } });

    await waitFor(() => expect(reports.fetchReports).toHaveBeenCalledWith('2026-02-01', '2026-02-28'));
  });

  it('shows an empty state instead of header-only tables when a range has no activity', async () => {
    fetchReportsMock().mockResolvedValueOnce({
      revenueTrend: [{ month: '2026-07', bookings: 0, billed: 0 }],
      topProducts: [],
      repeatCustomerRate: { activeCompanies: 0, repeatCompanies: 0, rate: 0 },
      range: { from: '2026-07-01', to: '2026-07-31' },
    });
    render(<MemoryRouter><ReportsPage /></MemoryRouter>);

    expect(await screen.findByText(/no orders in this range/i)).toBeInTheDocument();
    expect(screen.queryByText('Month')).not.toBeInTheDocument();
  });
});
