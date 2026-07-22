import { expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';

import StatusHistory from './StatusHistory';
import type { QuoteHistoryEntry } from '../../lib/quotes';

/** Convenience wrapper: StatusHistory is presentational; the fetch and its
 *  loading/failed bookkeeping live in useQuoteHistory (tested separately). */
function renderHistory(props: {
  entries?: QuoteHistoryEntry[];
  loading?: boolean;
  failed?: boolean;
}) {
  return render(
    <StatusHistory
      entries={props.entries ?? []}
      loading={props.loading ?? false}
      failed={props.failed ?? false}
    />,
  );
}

it('lists each transition newest first', () => {
  // The hook returns oldest first; the buyer cares about the latest change.
  renderHistory({
    entries: [
      { from: 'DRAFT', to: 'SENT', changed_at: '2026-07-20T10:00:00+00:00', actor_name: 'Ada Buyer' },
      { from: 'SENT', to: 'ACCEPTED', changed_at: '2026-07-21T10:00:00+00:00', actor_name: 'Bo Staff' },
    ],
  });

  expect(screen.getByText('Accepted')).toBeInTheDocument();
  expect(screen.getByText('Sent')).toBeInTheDocument();
  expect(screen.getByText('Ada Buyer')).toBeInTheDocument();
  expect(screen.getByText('Bo Staff')).toBeInTheDocument();

  const rows = screen.getAllByRole('listitem');
  expect(rows).toHaveLength(2);
  expect(rows[0]).toHaveTextContent('Accepted');
  expect(rows[1]).toHaveTextContent('Sent');
});

// Several transitions routinely land on the same day - the endpoint orders by
// (created_at, id) precisely because they can share a second. A date alone
// renders them identically, so the buyer cannot tell what happened when.
it('timestamps each entry to the minute, not just the day', () => {
  renderHistory({
    entries: [
      { from: 'DRAFT', to: 'SENT', changed_at: '2026-07-20T09:15:00Z', actor_name: 'Ada Buyer' },
      { from: 'SENT', to: 'ACCEPTED', changed_at: '2026-07-20T16:42:00Z', actor_name: 'Bo Staff' },
    ],
  });

  const rows = screen.getAllByRole('listitem');

  // Machine-readable instants survive intact for assistive tech and tooling.
  expect(rows[0].querySelector('time')).toHaveAttribute('datetime', '2026-07-20T16:42:00Z');
  expect(rows[1].querySelector('time')).toHaveAttribute('datetime', '2026-07-20T09:15:00Z');

  // Same day, so a date-only format would make these two strings identical.
  const shown = rows.map((r) => r.querySelector('time')?.textContent ?? '');
  expect(shown[0]).not.toBe(shown[1]);
  expect(shown[0]).toMatch(/\d/);
});

it('renders the tracking-started note when there is no history', () => {
  renderHistory({ entries: [] });

  // An order that predates transition logging has no history and never will -
  // say when tracking began rather than implying it never moved.
  expect(screen.getByText(/status tracking started on 20 july 2026/i)).toBeInTheDocument();
  expect(screen.getByText(/were not recorded/i)).toBeInTheDocument();
  expect(screen.queryByRole('listitem')).not.toBeInTheDocument();
});

it('names a system transition rather than leaving the actor blank', () => {
  renderHistory({
    entries: [{ from: 'PROCURING', to: 'READY', changed_at: '2026-07-22T10:00:00+00:00', actor_name: null }],
  });

  expect(screen.getByText('Ready')).toBeInTheDocument();
  expect(screen.getByText('System')).toBeInTheDocument();
  expect(screen.queryByText('null')).not.toBeInTheDocument();
});

it('says it could not load - never that the order predates tracking - when the fetch failed', () => {
  renderHistory({ entries: [], failed: true });

  expect(screen.getByText(/couldn’t load the status history/i)).toBeInTheDocument();
  expect(screen.getByRole('heading', { name: /status history/i })).toBeInTheDocument();

  // The old copy asserted a CAUSE the component cannot know. A buyer whose
  // request 500s has not been told, falsely, that their order is too old.
  expect(screen.queryByText(/status tracking started/i)).not.toBeInTheDocument();
  expect(screen.queryByText(/were not recorded/i)).not.toBeInTheDocument();
  expect(screen.queryByText(/something went wrong/i)).not.toBeInTheDocument();
});

it('says nothing about tracking while still loading, and marks the card busy', () => {
  renderHistory({ entries: [], loading: true });

  const card = screen.getByRole('heading', { name: /status history/i }).closest('[aria-busy]');
  expect(card).toBeInTheDocument();
  expect(card).toHaveAttribute('aria-busy', 'true');

  // "Changes before then were not recorded" is a claim about THIS order. While
  // loading we know nothing about it, so we must not make it - nor may a pending
  // load be mistaken for a failed one.
  expect(screen.queryByText(/status tracking started/i)).not.toBeInTheDocument();
  expect(screen.queryByText(/were not recorded/i)).not.toBeInTheDocument();
  expect(screen.queryByText(/couldn’t load/i)).not.toBeInTheDocument();
});

it('does NOT show the load-failure copy for a genuinely empty history', () => {
  renderHistory({ entries: [], failed: false });

  // The mirror of the failure test: resolving empty is a different fact from
  // failing, and must keep the tracking-boundary explanation.
  expect(screen.getByText(/status tracking started on 20 july 2026/i)).toBeInTheDocument();
  expect(screen.queryByText(/couldn’t load/i)).not.toBeInTheDocument();
});
