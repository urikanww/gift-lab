import { afterEach, describe, expect, it, vi } from 'vitest';
import type { ComponentProps } from 'react';
import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

// Stub the uploader: attaching yields the ref the server would return, so the
// row's onStage wiring can be exercised without a real upload round-trip.
vi.mock('./ProofFileInput', () => ({
  default: ({
    label,
    onChange,
    trailing,
  }: {
    label: string;
    onChange: (ref: string, name: string | null) => void;
    trailing?: React.ReactNode;
  }) => (
    <div>
      <button type="button" onClick={() => onChange('proofs/new.png', 'new.png')}>
        {`attach:${label}`}
      </button>
      {trailing}
    </div>
  ),
}));

import { ThemeProvider } from '../../ui';
import LineProofRow from './LineProofRow';
import type { LineItem, Proof, ProofState } from '../../types';

afterEach(cleanup);

const line = {
  id: 5,
  quote_id: 42,
  product_id: 3,
  variant_id: null,
  qty: 10,
  unit_price: '10.00',
  currency: 'SGD',
  line_total: '100.00',
  line_state: 'PENDING',
  procured_qty: null,
  procured_price: null,
  lead_time_days: null,
  customization: { mode: 'designer', artwork_ref: 'artwork/mug.png' },
  product: { id: 3, name: 'Enamel Mug', image_url: null },
} as unknown as LineItem;

function proofOf(state: ProofState): Proof {
  return {
    id: 1,
    quote_id: 42,
    line_item_id: 5,
    version: 1,
    artwork_version_ref: 'proofs/v1.png',
    state,
    approved_by: null,
    approved_at: null,
    notes: null,
  };
}

function renderRow(overrides: Partial<ComponentProps<typeof LineProofRow>> = {}) {
  const props = {
    line,
    proof: null as Proof | null,
    artworkOptions: [],
    busy: false,
    onStage: vi.fn(),
    onPickExisting: vi.fn(),
    onDrop: vi.fn(),
    ...overrides,
  };
  render(
    <ThemeProvider>
      <LineProofRow {...props} />
    </ThemeProvider>,
  );
  return props;
}

it('renders the product name', () => {
  renderRow();
  expect(screen.getByText('Enamel Mug')).toBeInTheDocument();
});

it('shows "Not prepared" when the line has no proof', () => {
  renderRow({ proof: null });
  expect(screen.getByText('Not prepared')).toBeInTheDocument();
});

describe('state badge labels', () => {
  it.each([
    ['DRAFT', 'Staged'],
    ['SENT', 'Sent'],
    ['CHANGES_REQUESTED', 'In changes'],
    ['APPROVED', 'Approved'],
  ] as const)('renders %s as "%s"', (state, label) => {
    renderRow({ proof: proofOf(state) });
    expect(screen.getByText(label)).toBeInTheDocument();
  });
});

it('fires onStage when the uploader yields a ref', async () => {
  const { onStage } = renderRow();
  await userEvent.click(screen.getByRole('button', { name: 'attach:Proof for Enamel Mug' }));
  expect(onStage).toHaveBeenCalledWith('proofs/new.png');
});

it('offers the reuse shortcut only when artwork options exist', () => {
  const { onPickExisting } = renderRow({
    artworkOptions: [{ ref: 'artwork/mug.png', name: 'Buyer’s design' }],
  });
  expect(screen.getByRole('button', { name: /use existing artwork/i })).toBeInTheDocument();
  screen.getByRole('button', { name: /use existing artwork/i }).click();
  expect(onPickExisting).toHaveBeenCalled();
});

it('hides the reuse shortcut when there are no artwork options', () => {
  renderRow({ artworkOptions: [] });
  expect(screen.queryByRole('button', { name: /use existing artwork/i })).not.toBeInTheDocument();
});

it('fires onDrop from the drop control', async () => {
  const { onDrop } = renderRow();
  await userEvent.click(screen.getByRole('button', { name: /drop item/i }));
  expect(onDrop).toHaveBeenCalled();
});

it('disables the drop control and shows the reason when dropDisabledReason is set', async () => {
  const { onDrop } = renderRow({ dropDisabledReason: 'Items can only be changed while the order is a draft.' });
  const btn = screen.getByRole('button', { name: /drop item/i });
  expect(btn).toBeDisabled();
  expect(screen.getByText(/only be changed while the order is a draft/i)).toBeInTheDocument();
  await userEvent.click(btn);
  expect(onDrop).not.toHaveBeenCalled();
});
