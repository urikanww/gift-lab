import { expect, it } from 'vitest';
import { fireEvent, render, screen, within } from '@testing-library/react';

import AmendmentHistory from './AmendmentHistory';
import type { AmendmentLogEntry } from '../../types';

/** Renders, then opens the disclosure - the trail is collapsed by default. */
const renderHistory = (entries: AmendmentLogEntry[]) => {
  const result = render(<AmendmentHistory entries={entries} currency="SGD" />);
  const toggle = screen.queryByRole('button', { name: /show \d+ edit/i });
  if (toggle) fireEvent.click(toggle);
  return result;
};

it('renders nothing when there are no edits', () => {
  const { container } = renderHistory([]);
  expect(container).toBeEmptyDOMElement();
});

it('is collapsed by default and opens on click', () => {
  render(
    <AmendmentHistory
      entries={[
        {
          batch: 'b1', action: 'edited', by: 1, by_name: 'Ada Ops', at: '2026-07-21T06:02:00Z',
          product_name: 'Enamel Mug', from: { unit_price: 10, qty: 4 }, to: { unit_price: 12.5, qty: 6 },
        },
      ]}
      currency="SGD"
    />,
  );

  // Heading always shows; the trail is hidden until opened.
  expect(screen.getByRole('heading', { name: /edit history/i })).toBeInTheDocument();
  expect(screen.queryByText(/Enamel Mug/)).not.toBeInTheDocument();

  fireEvent.click(screen.getByRole('button', { name: 'Show 1 edit' }));
  expect(screen.getByText(/Enamel Mug/)).toBeInTheDocument();
});

it('groups every change from one save under a single editor and time', () => {
  renderHistory([
    {
      batch: 'b1', action: 'edited', by: 1, by_name: 'Ada Ops', at: '2026-07-21T06:02:00Z',
      product_name: 'Enamel Mug', from: { unit_price: 10, qty: 4 }, to: { unit_price: 12.5, qty: 6 },
    },
    {
      batch: 'b1', action: 'delivery', by: 1, by_name: 'Ada Ops', at: '2026-07-21T06:02:00Z',
      from: { delivery: 5 }, to: { delivery: 20 },
    },
  ]);

  // One save -> one editor heading, both changes beneath it.
  expect(screen.getAllByText('Ada Ops')).toHaveLength(1);
  const rows = screen.getAllByRole('listitem');
  // Outer batch row + two change rows.
  expect(screen.getByText(/Enamel Mug: 4 × SGD 10.00 → 6 × SGD 12.50/)).toBeInTheDocument();
  expect(screen.getByText(/Delivery: SGD 5.00 → SGD 20.00/)).toBeInTheDocument();
  expect(rows.length).toBeGreaterThanOrEqual(3);
});

it('describes added and removed lines by product name', () => {
  renderHistory([
    {
      batch: 'b1', action: 'added', by: 1, by_name: 'Ada', at: '2026-07-21T06:00:00Z',
      product_name: 'Tote Bag', from: null, to: { unit_price: 8, qty: 3 },
    },
    {
      batch: 'b1', action: 'removed', by: 1, by_name: 'Ada', at: '2026-07-21T06:00:00Z',
      product_name: 'Sticker Pack', from: { unit_price: 2, qty: 50 }, to: null,
    },
  ]);

  expect(screen.getByText(/Added Tote Bag: 3 × SGD 8.00/)).toBeInTheDocument();
  expect(screen.getByText(/Removed Sticker Pack: was 50 × SGD 2.00/)).toBeInTheDocument();
});

it('shows the most recent save first', () => {
  renderHistory([
    {
      batch: 'old', action: 'edited', by: 1, by_name: 'First Editor', at: '2026-07-20T06:00:00Z',
      product_name: 'Mug', from: { unit_price: 10, qty: 1 }, to: { unit_price: 11, qty: 1 },
    },
    {
      batch: 'new', action: 'edited', by: 2, by_name: 'Second Editor', at: '2026-07-21T06:00:00Z',
      product_name: 'Mug', from: { unit_price: 11, qty: 1 }, to: { unit_price: 12, qty: 1 },
    },
  ]);

  const editors = screen.getAllByText(/Editor$/);
  expect(editors[0]).toHaveTextContent('Second Editor');
  expect(editors[1]).toHaveTextContent('First Editor');
});

it('names a system change when no editor was recorded', () => {
  renderHistory([
    {
      batch: 'b1', action: 'edited', by: null, by_name: null, at: '2026-07-21T06:00:00Z',
      product_name: 'Mug', from: { unit_price: 10, qty: 1 }, to: { unit_price: 12, qty: 1 },
    },
  ]);

  expect(screen.getByText('System')).toBeInTheDocument();
});

it('keeps the raw instant machine-readable', () => {
  renderHistory([
    {
      batch: 'b1', action: 'notes', by: 1, by_name: 'Ada', at: '2026-07-21T06:02:00Z',
      from: { notes: 'a' }, to: { notes: 'b' },
    },
  ]);

  const time = document.querySelector('time');
  expect(time).toHaveAttribute('datetime', '2026-07-21T06:02:00Z');
  expect(within(document.body).getByText('Notes updated')).toBeInTheDocument();
});
