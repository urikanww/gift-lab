import { expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import QuoteTimeline from './QuoteTimeline';
import type { QuoteState } from '../../types';

const renderTimeline = (state: QuoteState) => render(<QuoteTimeline state={state} />);

it('summarises the current state, the next state and the position', () => {
  renderTimeline('PROOFING');

  expect(screen.getByText('Proofing')).toBeInTheDocument();
  // The arrow is decorative and aria-hidden, so match on the sentence around it.
  expect(screen.getByText(/next: Proof approved/i, { exact: false })).toBeInTheDocument();
  expect(screen.getByText('step 4 of 9')).toBeInTheDocument();
});

it('points the disclosure at the region it reveals', async () => {
  const user = userEvent.setup();
  renderTimeline('PROOFING');

  const toggle = screen.getByRole('button', { name: 'Show all steps' });
  const target = toggle.getAttribute('aria-controls');
  expect(target).toBeTruthy();

  await user.click(toggle);

  expect(document.getElementById(target!)).toBeInTheDocument();
});

it('hides the full stepper until the disclosure is opened', async () => {
  const user = userEvent.setup();
  renderTimeline('PROOFING');

  // Collapsed: a late step is not rendered at all.
  expect(screen.queryByText('Procuring')).not.toBeInTheDocument();

  const toggle = screen.getByRole('button', { name: 'Show all steps' });
  expect(toggle).toHaveAttribute('aria-expanded', 'false');

  await user.click(toggle);

  expect(screen.getByText('Procuring')).toBeInTheDocument();
  const open = screen.getByRole('button', { name: 'Hide all steps' });
  expect(open).toHaveAttribute('aria-expanded', 'true');

  // Positive control for the off-path tests below: an on-path state DOES mark
  // a current step, so their `queryByText(/current status/i)` is a real check
  // and not a query that never matches anything.
  expect(screen.getByText(/current status/i)).toBeInTheDocument();
});

it('promises no next step on the last step of the happy path', () => {
  renderTimeline('READY');

  expect(screen.getByText('Ready')).toBeInTheDocument();
  expect(screen.queryByText(/next:/i)).not.toBeInTheDocument();
  expect(screen.getByText('step 9 of 9')).toBeInTheDocument();
});

it('promises no next step and no position for a cancelled order', () => {
  renderTimeline('CANCELLED');

  expect(screen.getByText('Cancelled')).toBeInTheDocument();
  expect(screen.queryByText(/next:/i)).not.toBeInTheDocument();
  expect(screen.queryByText(/step \d+ of \d+/i)).not.toBeInTheDocument();
});

it('promises no next step and no position when changes are requested', () => {
  // CHANGES_REQUESTED has no place on the happy path. Any implementation that
  // pins it to an end of the path for layout and then reads that position back
  // would tell the buyer their next step is "Sent", which is simply untrue.
  renderTimeline('CHANGES_REQUESTED');

  expect(screen.getByText('Changes requested')).toBeInTheDocument();
  expect(screen.queryByText(/next:/i)).not.toBeInTheDocument();
  expect(screen.queryByText(/next: Sent/i)).not.toBeInTheDocument();
  expect(screen.queryByText(/step \d+ of \d+/i)).not.toBeInTheDocument();
});

it('marks no step as current in the expanded stepper when off the path', async () => {
  const user = userEvent.setup();
  renderTimeline('CHANGES_REQUESTED');
  await user.click(screen.getByRole('button', { name: 'Show all steps' }));

  // The stepper is reachable, so the index fallback must not survive into it:
  // CHANGES_REQUESTED maps to 0, which would announce "Draft (current status)".
  expect(screen.getByText('Draft')).toBeInTheDocument();
  expect(screen.queryByText(/current status/i)).not.toBeInTheDocument();
  expect(screen.getByText(/left the standard path/i)).toBeInTheDocument();
});

it('reads a closed order as finished rather than positioned', async () => {
  const user = userEvent.setup();
  renderTimeline('CLOSED');

  expect(screen.getByText('Closed')).toBeInTheDocument();
  expect(screen.getByText('All steps complete')).toBeInTheDocument();
  expect(screen.queryByText(/next:/i)).not.toBeInTheDocument();
  // READY owns step 9; CLOSED must not claim the same slot.
  expect(screen.queryByText(/step \d+ of \d+/i)).not.toBeInTheDocument();

  await user.click(screen.getByRole('button', { name: 'Show all steps' }));

  // Every step done, none current - the order is finished, not in progress.
  expect(screen.getAllByText('✓')).toHaveLength(9);
  expect(screen.queryByText(/current status/i)).not.toBeInTheDocument();
});
