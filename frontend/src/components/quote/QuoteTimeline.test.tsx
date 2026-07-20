import { expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import QuoteTimeline from './QuoteTimeline';
import type { QuoteState } from '../../types';

const renderTimeline = (state: QuoteState) => render(<QuoteTimeline state={state} />);

it('summarises the current state, the next state and the position', () => {
  renderTimeline('PROOFING');

  expect(screen.getByText('Proofing')).toBeInTheDocument();
  expect(screen.getByText(/next: Proof approved/i)).toBeInTheDocument();
  expect(screen.getByText('step 4 of 9')).toBeInTheDocument();
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
  // CHANGES_REQUESTED has no place on the happy path - it falls back to index 0
  // for positioning only. Reading that fallback as a lifecycle position would
  // tell the buyer their next step is "Sent", which is simply untrue.
  renderTimeline('CHANGES_REQUESTED');

  expect(screen.getByText('Changes requested')).toBeInTheDocument();
  expect(screen.queryByText(/next:/i)).not.toBeInTheDocument();
  expect(screen.queryByText(/next: Sent/i)).not.toBeInTheDocument();
  expect(screen.queryByText(/step \d+ of \d+/i)).not.toBeInTheDocument();
});
