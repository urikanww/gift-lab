import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import DashboardPage from './DashboardPage';
import { useDashboardStore } from '../stores/dashboardStore';

const base = {
  pipeline: { SENT: 2, ACCEPTED: 1 },
  production: { byState: { READY: 3, IN_PRODUCTION: 1 }, wip: 1, overdue: 2 },
  atRisk: [
    { jobId: 5, quoteId: 9, quoteReference: '9BWVKWCDXH', track: 'UV', state: 'READY', readyAt: null },
  ],
  queues: { proofsPending: 4, changesRequested: 1, procurementToReconfirm: 2, cataloguePending: 6, reordersOpen: 3 },
  activity: [
    { id: 1, actor: 'Ops', event: 'quote.amended', auditableType: 'Quote', auditableId: 9, auditableLabel: 'Order 9BWVKWCDXH', at: null },
    { id: 2, actor: 'Ops', event: 'product.updated', auditableType: 'Product', auditableId: 12, auditableLabel: 'Product #12', at: null },
  ],
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

  it('identifies an at-risk job by order reference, never by the sequential id', () => {
    renderPage();

    // Scoped to the at-risk row on purpose: the job id is a genuine ordinal
    // and stays.
    const row = screen.getByRole('link', { name: /Job #5/ });
    // Positive control: the reference IS rendered, so dropping the identifier
    // outright could not pass the negative assertion below.
    expect(row).toHaveTextContent('Order 9BWVKWCDXH');
    expect(row).not.toHaveTextContent(/Quote #\d+/);
  });

  it('names an activity row by its server-composed label, never the sequential id', () => {
    renderPage();

    // Quote row: the opaque reference, not "(Quote #9)".
    expect(screen.getByText(/quote\.amended/)).toHaveTextContent('(Order 9BWVKWCDXH)');
    // Every other audited type keeps the id shape it always had.
    expect(screen.getByText(/product\.updated/)).toHaveTextContent('(Product #12)');
    // The whole feed is free of the numeric quote id.
    expect(screen.queryByText(/Quote #\d+/)).not.toBeInTheDocument();
  });

  it('shows an error state', () => {
    useDashboardStore.setState({ data: null, loading: false, error: 'boom' });
    renderPage();
    expect(screen.getByText(/boom|could not/i)).toBeInTheDocument();
  });
});
