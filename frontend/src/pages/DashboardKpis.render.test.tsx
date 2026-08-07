import { afterEach, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';

vi.mock('../stores/dashboardStore', () => ({
  useDashboardStore: () => ({
    data: {
      queues: { proofsPending: 0, changesRequested: 0, procurementToReconfirm: 0, cataloguePending: 0, reordersOpen: 0, unpaidDelivered: 0 },
      production: { overdue: 0, wip: 0, byState: {} },
      pipeline: {},
      atRisk: [],
      valueBooked: { currency: 'SGD', amount: 1200 },
      activity: [],
      kpis: {
        ordersThisWeek: 4,
        bookedThisMonth: { currency: 'SGD', amount: 3400 },
        outstanding: { currency: 'SGD', amount: 900 },
      },
      trends: [
        { weekStart: '2026-06-16', orders: 2, bookedValue: 500 },
        { weekStart: '2026-06-23', orders: 3, bookedValue: 800 },
      ],
    },
    loading: false,
    error: null,
    load: vi.fn(),
  }),
}));

import { ThemeProvider } from '../ui';
import DashboardPage from './DashboardPage';

afterEach(cleanup);

function renderPage() {
  return render(
    <ThemeProvider>
      <MemoryRouter>
        <DashboardPage />
      </MemoryRouter>
    </ThemeProvider>,
  );
}

it('renders the three KPI tiles with their values', () => {
  renderPage();
  expect(screen.getByText('Orders this week')).toBeTruthy();
  expect(screen.getByText('4')).toBeTruthy();
  expect(screen.getByText('Booked value (this month)')).toBeTruthy();
  expect(screen.getByText('Outstanding to collect')).toBeTruthy();
});

it('renders the trend chart section', () => {
  renderPage();
  expect(screen.getByText(/last 8 weeks/i)).toBeTruthy();
});
