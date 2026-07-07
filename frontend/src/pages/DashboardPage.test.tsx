import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import DashboardPage from './DashboardPage';
import { useDashboardStore } from '../stores/dashboardStore';

const base = {
  pipeline: { SENT: 2, ACCEPTED: 1 },
  production: { byState: { READY: 3, IN_PRODUCTION: 1 }, wip: 1, overdue: 2 },
  atRisk: [{ jobId: 5, quoteId: 9, track: 'UV', state: 'READY', readyAt: null }],
  queues: { proofsPending: 4, procurementToReconfirm: 2, cataloguePending: 6, reordersOpen: 3 },
  activity: [{ id: 1, actor: 'Ops', event: 'quote.amended', auditableType: 'Quote', auditableId: 9, at: null }],
  valueBooked: null,
};

beforeEach(() => useDashboardStore.setState({ data: base, loading: false, error: null }));

const renderPage = () => render(<MemoryRouter><DashboardPage /></MemoryRouter>);

describe('DashboardPage', () => {
  it('renders queue and production figures', () => {
    renderPage();
    expect(screen.getByText(/proofs pending/i)).toBeInTheDocument();
    expect(screen.getByText('4')).toBeInTheDocument();
    expect(screen.getAllByText(/at.risk|overdue/i).length).toBeGreaterThan(0);
  });

  it('humanizes raw enum states in the pipeline and production health', () => {
    renderPage();
    // Pipeline rows: SENT → "Sent" (no raw enum tokens).
    expect(screen.getAllByText('Sent').length).toBeGreaterThan(0);
    expect(screen.queryByText('SENT')).not.toBeInTheDocument();
    // Production byState: IN_PRODUCTION → "In production".
    expect(screen.getByText(/^In production:/)).toBeInTheDocument();
    expect(screen.queryByText(/IN_PRODUCTION/)).not.toBeInTheDocument();
  });

  it('shows an error state', () => {
    useDashboardStore.setState({ data: null, loading: false, error: 'boom' });
    renderPage();
    expect(screen.getByText(/boom|could not/i)).toBeInTheDocument();
  });
});
