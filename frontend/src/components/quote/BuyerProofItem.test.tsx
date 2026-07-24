import { afterEach, expect, it, vi } from 'vitest';
import type { ComponentProps } from 'react';
import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

// Stub the uploader: attaching yields the ref the server would return, so the
// reference-image wiring can be exercised without a real upload round-trip.
vi.mock('./ProofFileInput', () => ({
  default: ({ label, onChange }: { label: string; onChange: (ref: string, name: string | null) => void }) => (
    <button type="button" onClick={() => onChange('artwork/ref.png', 'ref.png')}>
      {`attach:${label}`}
    </button>
  ),
}));

import { ThemeProvider } from '../../ui';
import BuyerProofItem from './BuyerProofItem';
import type { Proof, ProofState } from '../../types';

afterEach(cleanup);

function proofOf(state: ProofState, overrides: Partial<Proof> = {}): Proof {
  return {
    id: 9,
    quote_id: 42,
    line_item_id: 5,
    product_name: 'Enamel Mug',
    version: 1,
    artwork_version_ref: 'proofs/v1.png',
    artwork_url: 'https://cdn.test/proofs/v1.png',
    state,
    approved_by: null,
    approved_at: null,
    notes: null,
    ...overrides,
  };
}

function renderItem(overrides: Partial<ComponentProps<typeof BuyerProofItem>> = {}) {
  const props = {
    proof: proofOf('SENT'),
    busy: false,
    onApprove: vi.fn(),
    onRequestChanges: vi.fn(),
    ...overrides,
  };
  render(
    <ThemeProvider>
      <BuyerProofItem {...props} />
    </ThemeProvider>,
  );
  return props;
}

it('renders the product name and version', () => {
  renderItem();
  expect(screen.getByText('Enamel Mug')).toBeInTheDocument();
  expect(screen.getByText('v1')).toBeInTheDocument();
});

it('fires onApprove when the buyer approves', async () => {
  const { onApprove } = renderItem();
  await userEvent.click(screen.getByRole('button', { name: /approve proof/i }));
  expect(onApprove).toHaveBeenCalled();
});

it('requires a note before request-changes fires', async () => {
  const { onRequestChanges } = renderItem();

  await userEvent.click(screen.getByRole('button', { name: /request changes/i }));
  // Send stays disabled with no reason.
  expect(screen.getByRole('button', { name: /send request/i })).toBeDisabled();
  expect(onRequestChanges).not.toHaveBeenCalled();

  await userEvent.type(screen.getByLabelText(/what should we change/i), '  Use the darker blue.  ');
  const send = screen.getByRole('button', { name: /send request/i });
  expect(send).toBeEnabled();
  await userEvent.click(send);

  // Notes are trimmed; no attachment yields null.
  expect(onRequestChanges).toHaveBeenCalledWith('Use the darker blue.', null);
});

it('passes an attached reference image ref to onRequestChanges', async () => {
  const { onRequestChanges } = renderItem();

  await userEvent.click(screen.getByRole('button', { name: /request changes/i }));
  await userEvent.type(screen.getByLabelText(/what should we change/i), 'Match this.');
  await userEvent.click(screen.getByRole('button', { name: 'attach:Attach a reference (optional)' }));
  await userEvent.click(screen.getByRole('button', { name: /send request/i }));

  expect(onRequestChanges).toHaveBeenCalledWith('Match this.', 'artwork/ref.png');
});

it('renders a non-actionable note for a line being revised, showing the buyer note', () => {
  renderItem({ proof: proofOf('CHANGES_REQUESTED', { notes: 'Move the logo up.' }) });

  expect(screen.getByText(/being revised/i)).toBeInTheDocument();
  expect(screen.getByText(/Move the logo up\./)).toBeInTheDocument();
  // No approve / request-changes controls while it is being revised.
  expect(screen.queryByRole('button', { name: /approve proof/i })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: /request changes/i })).not.toBeInTheDocument();
});
