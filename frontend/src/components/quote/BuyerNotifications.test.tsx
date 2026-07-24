import { expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import BuyerNotifications from './BuyerNotifications';
import type { Quote, QuoteReminder } from '../../types';
import type { QuoteHistoryEntry } from '../../lib/quotes';

function quoteWith(reminder: QuoteReminder | undefined, state = 'ARTWORK_APPROVED'): Quote {
  return { state, reminder } as unknown as Quote;
}

const APPROVED_HISTORY: QuoteHistoryEntry[] = [
  { from: 'PROOFING', to: 'ARTWORK_APPROVED', changed_at: '2026-07-23T14:13:00Z', actor_name: 'Super Admin' },
];

it('renders nothing when the order carries no reminder data (buyer payload)', () => {
  const { container } = render(<BuyerNotifications quote={quoteWith(undefined)} history={[]} />);
  expect(container).toBeEmptyDOMElement();
});

it('names the buyer email sent on reaching the current state, with its time', () => {
  render(
    <BuyerNotifications
      quote={quoteWith({
        current_milestone: 'artwork_approved',
        current_milestone_enabled: true,
        last_reminded_at: null,
        next: null,
      })}
      history={APPROVED_HISTORY}
    />,
  );

  expect(screen.getByText('Artwork approved')).toBeInTheDocument();
  // The "sent" time is read from the transition into the current state.
  expect(screen.getByText(/email sent to the buyer/i)).toBeInTheDocument();
  expect(screen.getByText(/Jul 2026/)).toBeInTheDocument();
});

it('says so when the milestone email is switched off', () => {
  render(
    <BuyerNotifications
      quote={quoteWith({
        current_milestone: 'artwork_approved',
        current_milestone_enabled: false,
        last_reminded_at: null,
        next: null,
      })}
      history={APPROVED_HISTORY}
    />,
  );

  expect(screen.getByText(/switched off, so no email was sent/i)).toBeInTheDocument();
});

it('says no email is sent at a silent step', () => {
  render(
    <BuyerNotifications
      quote={quoteWith(
        { current_milestone: null, current_milestone_enabled: false, last_reminded_at: null, next: null },
        'PROOF_APPROVED',
      )}
      history={[]}
    />,
  );

  expect(screen.getByText(/No buyer email is sent at this step/i)).toBeInTheDocument();
});

it('spells out the next scheduled reminder and the ladder', () => {
  render(
    <BuyerNotifications
      quote={quoteWith({
        current_milestone: 'artwork_approved',
        current_milestone_enabled: true,
        last_reminded_at: null,
        next: {
          kind: 'price',
          reminders_sent: 0,
          reminders_remaining: 3,
          exhausted: false,
          next_due_at: '2026-07-26T09:00:00Z',
          ladder_days: [3, 7, 12],
        },
      })}
      history={APPROVED_HISTORY}
    />,
  );

  expect(screen.getByText(/Next reminder:/i)).toBeInTheDocument();
  expect(screen.getByText(/accepts the pricing/i)).toBeInTheDocument();
  expect(screen.getByText(/3 \/ 7 \/ 12-day ladder/i)).toBeInTheDocument();
});

it('reports an exhausted ladder as flagged for a human call', () => {
  render(
    <BuyerNotifications
      quote={quoteWith({
        current_milestone: 'artwork_approved',
        current_milestone_enabled: true,
        last_reminded_at: '2026-07-30T09:00:00Z',
        next: {
          kind: 'price',
          reminders_sent: 3,
          reminders_remaining: 0,
          exhausted: true,
          next_due_at: null,
          ladder_days: [3, 7, 12],
        },
      })}
      history={APPROVED_HISTORY}
    />,
  );

  expect(screen.getByText(/flagged for\s+a staff member to follow up by phone/i)).toBeInTheDocument();
});
