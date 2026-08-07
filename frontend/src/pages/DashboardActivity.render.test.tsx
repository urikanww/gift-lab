import { afterEach, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';

const load = vi.fn();
vi.mock('../stores/dashboardStore', () => ({
  useDashboardStore: () => ({
    data: {
      queues: { proofsPending: 0, cataloguePending: 0, unpaidDelivered: 0 },
      production: { overdue: 0, wip: 0, byState: {} },
      pipeline: {},
      atRisk: [],
      valueBooked: null,
      activity: [
        { id: 1, actor: 'Jane', event: 'quote.amended', auditableType: 'Quote', auditableId: 1, auditableLabel: 'Order 9BWVKW', at: '2026-08-07T10:00:00Z' },
        { id: 2, actor: null, event: 'weird.new_thing', auditableType: 'Product', auditableId: 5, auditableLabel: 'Product #5', at: '2026-08-07T09:00:00Z' },
      ],
    },
    loading: false,
    error: null,
    load,
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

it('renders humanized activity rows, not raw event tokens', () => {
  renderPage();
  expect(screen.getByText('Jane amended Order 9BWVKW')).toBeTruthy();
  expect(screen.getByText('System weird new thing Product #5')).toBeTruthy();
  expect(screen.queryByText(/weird\.new_thing/)).toBeNull();
});
