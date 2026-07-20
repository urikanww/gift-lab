import { beforeEach, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';

const fetchQuoteHistory = vi.fn();

vi.mock('../../lib/quotes', () => ({
  fetchQuoteHistory: (reference: string) => fetchQuoteHistory(reference),
}));

import StatusHistory from './StatusHistory';

beforeEach(() => {
  fetchQuoteHistory.mockReset();
});

it('lists each transition newest first', async () => {
  // The API returns oldest first; the buyer cares about the latest change.
  fetchQuoteHistory.mockResolvedValue([
    { from: 'DRAFT', to: 'SENT', changed_at: '2026-07-20T10:00:00+00:00', actor_name: 'Ada Buyer' },
    { from: 'SENT', to: 'ACCEPTED', changed_at: '2026-07-21T10:00:00+00:00', actor_name: 'Bo Staff' },
  ]);

  render(<StatusHistory reference="ORD-123" />);

  // Positive control: the entries are genuinely on the page, so the ordering
  // assertion below is reading real rendered content and not an empty list.
  expect(await screen.findByText('Accepted')).toBeInTheDocument();
  expect(screen.getByText('Sent')).toBeInTheDocument();
  expect(screen.getByText('Ada Buyer')).toBeInTheDocument();
  expect(screen.getByText('Bo Staff')).toBeInTheDocument();

  const rows = screen.getAllByRole('listitem');
  expect(rows).toHaveLength(2);
  expect(rows[0]).toHaveTextContent('Accepted');
  expect(rows[1]).toHaveTextContent('Sent');
});

it('renders the tracking-started note when there is no history', async () => {
  fetchQuoteHistory.mockResolvedValue([]);

  render(<StatusHistory reference="ORD-123" />);

  // An order that predates transition logging has no history and never will -
  // say when tracking began rather than implying it never moved.
  expect(await screen.findByText(/status tracking started on 20 july 2026/i)).toBeInTheDocument();
  expect(screen.getByText(/were not recorded/i)).toBeInTheDocument();
  expect(screen.queryByRole('listitem')).not.toBeInTheDocument();
});

it('names a system transition rather than leaving the actor blank', async () => {
  fetchQuoteHistory.mockResolvedValue([
    { from: 'PROCURING', to: 'READY', changed_at: '2026-07-22T10:00:00+00:00', actor_name: null },
  ]);

  render(<StatusHistory reference="ORD-123" />);

  expect(await screen.findByText('Ready')).toBeInTheDocument();
  expect(screen.getByText('System')).toBeInTheDocument();
  expect(screen.queryByText('null')).not.toBeInTheDocument();
});

it('falls back to the empty state when the fetch fails, never an error', async () => {
  fetchQuoteHistory.mockRejectedValue(new Error('network down'));

  render(<StatusHistory reference="ORD-123" />);

  // History is supplementary: a failure must not surface where the order
  // details are, and must not take the page down.
  expect(await screen.findByText(/status tracking started on 20 july 2026/i)).toBeInTheDocument();
  expect(screen.queryByText(/something went wrong/i)).not.toBeInTheDocument();
  expect(screen.queryByText(/network down/i)).not.toBeInTheDocument();
});

it('passes the order reference, not an id, to the fetcher', async () => {
  fetchQuoteHistory.mockResolvedValue([]);

  render(<StatusHistory reference="ORD-123" />);

  await screen.findByText(/status tracking started/i);
  expect(fetchQuoteHistory).toHaveBeenCalledWith('ORD-123');
});
