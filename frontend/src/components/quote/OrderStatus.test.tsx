import { expect, it } from 'vitest';
import { fireEvent, render, screen, within } from '@testing-library/react';
import OrderStatus from './OrderStatus';
import type { QuoteHistoryEntry } from '../../lib/quotes';
import type { QuoteState } from '../../types';

const empty = { entries: [] as QuoteHistoryEntry[], loading: false, failed: false };

function renderStatus(state: QuoteState, history = empty) {
  return render(<OrderStatus state={state} history={history} />);
}

function region() {
  return screen.getByRole('region', { name: 'Status history' });
}

it('summarises the current state, the next state and the position', () => {
  renderStatus('PROOFING');

  expect(screen.getByText('Proofing')).toBeInTheDocument();
  expect(screen.getByText(/next: Proof approved/i, { exact: false })).toBeInTheDocument();
  expect(screen.getByText('step 4 of 9')).toBeInTheDocument();
});

it('promises no next step on the last happy-path step', () => {
  renderStatus('READY');

  expect(screen.getByText('Ready')).toBeInTheDocument();
  expect(screen.queryByText(/next:/i)).not.toBeInTheDocument();
  expect(screen.getByText('step 9 of 9')).toBeInTheDocument();
});

it('reads a closed order as finished, not positioned', () => {
  renderStatus('CLOSED');

  expect(screen.getByText('All steps complete')).toBeInTheDocument();
  expect(screen.queryByText(/next:/i)).not.toBeInTheDocument();
  expect(screen.queryByText(/step \d+ of \d+/i)).not.toBeInTheDocument();
});

it('makes no position claim for an off-path state', () => {
  renderStatus('CHANGES_REQUESTED');

  expect(screen.getByText('Changes requested')).toBeInTheDocument();
  expect(screen.queryByText(/step \d+ of \d+/i)).not.toBeInTheDocument();
  expect(screen.queryByText(/next:/i)).not.toBeInTheDocument();
});

it('keeps the status ledger collapsed until opened', () => {
  renderStatus('ACCEPTED', {
    entries: [{ from: 'DRAFT', to: 'SENT', changed_at: '2026-07-20T10:00:00Z', actor_name: 'Bo' }],
    loading: false,
    failed: false,
  });

  // Collapsed: no ledger region yet.
  expect(screen.queryByRole('region', { name: 'Status history' })).not.toBeInTheDocument();

  fireEvent.click(screen.getByRole('button', { name: 'Show history' }));

  // Scope to the ledger so the current-state badge isn't mistaken for a row.
  expect(within(region()).getByText('Sent')).toBeInTheDocument();
  expect(within(region()).getByText('Bo')).toBeInTheDocument();
});

it('shows newest-first with actor and a machine-readable instant', () => {
  renderStatus('ACCEPTED', {
    entries: [
      { from: 'DRAFT', to: 'SENT', changed_at: '2026-07-20T10:00:00Z', actor_name: 'Bo' },
      { from: 'SENT', to: 'ACCEPTED', changed_at: '2026-07-21T10:00:00Z', actor_name: null },
    ],
    loading: false,
    failed: false,
  });
  fireEvent.click(screen.getByRole('button', { name: 'Show history' }));

  const rows = within(region()).getAllByRole('listitem');
  expect(rows[0]).toHaveTextContent('Accepted');
  expect(rows[1]).toHaveTextContent('Sent');
  // Null actor renders as System, never blank.
  expect(within(region()).getByText('System')).toBeInTheDocument();
  expect(region().querySelector('time')).toHaveAttribute('datetime', '2026-07-21T10:00:00Z');
});

it('names the tracking boundary for an empty trail', () => {
  renderStatus('READY');
  fireEvent.click(screen.getByRole('button', { name: 'Show history' }));

  expect(within(region()).getByText(/status tracking started on 20 july 2026/i)).toBeInTheDocument();
});

it('says it could not load - never that the order predates tracking - on failure', () => {
  renderStatus('READY', { entries: [], loading: false, failed: true });
  fireEvent.click(screen.getByRole('button', { name: 'Show history' }));

  expect(within(region()).getByText(/couldn’t load/i)).toBeInTheDocument();
  expect(within(region()).queryByText(/status tracking started/i)).not.toBeInTheDocument();
});

it('marks the ledger busy while loading', () => {
  renderStatus('READY', { entries: [], loading: true, failed: false });
  fireEvent.click(screen.getByRole('button', { name: 'Show history' }));

  expect(region()).toHaveAttribute('aria-busy', 'true');
  expect(within(region()).queryByText(/status tracking started/i)).not.toBeInTheDocument();
});
