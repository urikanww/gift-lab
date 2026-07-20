import { beforeEach, expect, it, vi } from 'vitest';
import { act, render, screen } from '@testing-library/react';

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

  render(<StatusHistory reference="ORD-123" state="SENT" />);

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

  render(<StatusHistory reference="ORD-123" state="SENT" />);

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

  render(<StatusHistory reference="ORD-123" state="SENT" />);

  expect(await screen.findByText('Ready')).toBeInTheDocument();
  expect(screen.getByText('System')).toBeInTheDocument();
  expect(screen.queryByText('null')).not.toBeInTheDocument();
});

it('says it could not load - never that the order predates tracking - when the fetch fails', async () => {
  fetchQuoteHistory.mockRejectedValue(new Error('network down'));

  render(<StatusHistory reference="ORD-123" state="SENT" />);

  // Positive control: something IS rendered, so the not.toBeInTheDocument
  // assertions below are not passing against a blank component.
  expect(await screen.findByText(/couldn’t load the status history/i)).toBeInTheDocument();
  expect(screen.getByRole('heading', { name: /status history/i })).toBeInTheDocument();

  // The old copy asserted a CAUSE the component cannot know. A buyer whose
  // request 500s has not been told, falsely, that their order is too old.
  expect(screen.queryByText(/status tracking started/i)).not.toBeInTheDocument();
  expect(screen.queryByText(/were not recorded/i)).not.toBeInTheDocument();

  // Still supplementary: no error banner, no leaked technical detail.
  expect(screen.queryByText(/something went wrong/i)).not.toBeInTheDocument();
  expect(screen.queryByText(/network down/i)).not.toBeInTheDocument();
});

it('says nothing about tracking until the fetch has actually answered', async () => {
  // Hold the fetch open so the pending render is observable.
  let resolve!: (rows: unknown[]) => void;
  fetchQuoteHistory.mockReturnValue(new Promise((r) => { resolve = r; }));

  render(<StatusHistory reference="ORD-123" state="SENT" />);

  // Positive control: the card IS on screen and marked busy, so the absence
  // below is a component that is waiting, not one that failed to render.
  const card = screen.getByRole('heading', { name: /status history/i }).closest('[aria-busy]');
  expect(card).toBeInTheDocument();
  expect(card).toHaveAttribute('aria-busy', 'true');

  // "Changes before then were not recorded" is a claim about THIS order. Before
  // the fetch answers we know nothing about this order, so we must not make it.
  expect(screen.queryByText(/status tracking started/i)).not.toBeInTheDocument();
  expect(screen.queryByText(/were not recorded/i)).not.toBeInTheDocument();
  // Nor may a pending fetch be mistaken for a failed one.
  expect(screen.queryByText(/couldn’t load/i)).not.toBeInTheDocument();

  await act(async () => { resolve([]); });

  // Once it answers empty, the boundary explanation is earned.
  expect(await screen.findByText(/status tracking started on 20 july 2026/i)).toBeInTheDocument();
  expect(card).toHaveAttribute('aria-busy', 'false');
});

it('does NOT show the load-failure copy for a genuinely empty history', async () => {
  fetchQuoteHistory.mockResolvedValue([]);

  render(<StatusHistory reference="ORD-123" state="SENT" />);

  // The mirror of the test above: resolving empty is a different fact from
  // failing, and must keep the tracking-boundary explanation.
  expect(await screen.findByText(/status tracking started on 20 july 2026/i)).toBeInTheDocument();
  expect(screen.queryByText(/couldn’t load/i)).not.toBeInTheDocument();
});

it('passes the order reference, not an id, to the fetcher', async () => {
  fetchQuoteHistory.mockResolvedValue([]);

  render(<StatusHistory reference="ORD-123" state="SENT" />);

  await screen.findByText(/status tracking started/i);
  expect(fetchQuoteHistory).toHaveBeenCalledWith('ORD-123');
});
