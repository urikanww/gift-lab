import { expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

vi.mock('../ProductCombobox', () => ({
  default: ({ onChange, label }: { onChange: (p: { id: number; name: string }) => void; label: string }) => (
    <button type="button" onClick={() => onChange({ id: 77, name: 'Folding Tote' })}>
      {label}
    </button>
  ),
}));

import QuoteLineEditor from './QuoteLineEditor';
import type { Quote } from '../../types';

const quote = {
  id: 9,
  currency: 'SGD',
  subtotal: '50.00',
  delivery: '30.00',
  total: '80.00',
  notes: null,
  line_items: [
    {
      id: 1,
      product_id: 10,
      variant_id: null,
      qty: 2,
      unit_price: '15.00',
      line_total: '30.00',
      currency: 'SGD',
      product: { name: 'Enamel Mug' },
    },
    {
      id: 2,
      product_id: 11,
      variant_id: null,
      qty: 1,
      unit_price: '20.00',
      line_total: '20.00',
      currency: 'SGD',
      product: { name: 'Canvas Pouch' },
    },
  ],
} as unknown as Quote;

const renderEditor = (onSave = vi.fn().mockResolvedValue({})) => {
  render(<QuoteLineEditor quote={quote} onCancel={vi.fn()} onSave={onSave} />);
  return onSave;
};

// The whole point of the merge behaviour on the service: sending a subset must
// be safe. If the editor posted every row, an order quoted under an older
// margin floor would be re-validated and become unsaveable.
it('submits only the lines that actually changed', async () => {
  const onSave = renderEditor();
  const user = userEvent.setup();

  const qtyInputs = screen.getAllByLabelText('Qty');
  await user.clear(qtyInputs[0]);
  await user.type(qtyInputs[0], '5');
  await user.click(screen.getByRole('button', { name: 'Save changes' }));

  await waitFor(() => expect(onSave).toHaveBeenCalled());
  const payload = onSave.mock.calls[0][0];
  expect(payload.lines).toEqual([{ id: 1, variant_id: null, qty: 5, unit_price: 15 }]);
  expect(payload.removed_line_ids).toEqual([]);
});

it('sends a removal in removed_line_ids rather than by omitting the line', async () => {
  const onSave = renderEditor();
  const user = userEvent.setup();

  await user.click(screen.getAllByRole('button', { name: 'Remove' })[1]);
  await user.click(screen.getByRole('button', { name: 'Save changes' }));

  await waitFor(() => expect(onSave).toHaveBeenCalled());
  const payload = onSave.mock.calls[0][0];
  expect(payload.removed_line_ids).toEqual([2]);
  // The surviving line is unchanged, so it is not resubmitted.
  expect(payload.lines).toEqual([]);
});

it('lets a pending removal be undone before saving', async () => {
  const onSave = renderEditor();
  const user = userEvent.setup();

  await user.click(screen.getAllByRole('button', { name: 'Remove' })[1]);
  expect(screen.getByText('Will be removed on save')).toBeInTheDocument();

  await user.click(screen.getByRole('button', { name: 'Undo' }));
  expect(screen.queryByText('Will be removed on save')).not.toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Save changes' })).toBeDisabled();
  expect(onSave).not.toHaveBeenCalled();
});

it('adds a line with product_id and no id', async () => {
  const onSave = renderEditor();
  const user = userEvent.setup();

  await user.click(screen.getByRole('button', { name: 'Add a line' }));
  const priceInputs = screen.getAllByLabelText('Unit price');
  await user.clear(priceInputs[2]);
  await user.type(priceInputs[2], '12');
  await user.click(screen.getByRole('button', { name: 'Save changes' }));

  await waitFor(() => expect(onSave).toHaveBeenCalled());
  expect(onSave.mock.calls[0][0].lines).toEqual([
    { product_id: 77, variant_id: null, qty: 1, unit_price: 12 },
  ]);
});

// Delivery dropping because the goods fold and stack is one of the reasons this
// screen exists, and it must not force the lines back through validation.
it('submits a delivery-only change with no lines', async () => {
  const onSave = renderEditor();
  const user = userEvent.setup();

  const deliveryInput = screen.getByLabelText('Delivery');
  await user.clear(deliveryInput);
  await user.type(deliveryInput, '12');
  await user.click(screen.getByRole('button', { name: 'Save changes' }));

  await waitFor(() => expect(onSave).toHaveBeenCalled());
  const payload = onSave.mock.calls[0][0];
  expect(payload.lines).toEqual([]);
  expect(payload.delivery).toBe(12);
});

it('recalculates the total as the staffer types', async () => {
  renderEditor();
  const user = userEvent.setup();

  const qtyInputs = screen.getAllByLabelText('Qty');
  await user.clear(qtyInputs[0]);
  await user.type(qtyInputs[0], '4');

  // 4 x 15.00 + 1 x 20.00 = 80.00, plus 30.00 delivery.
  expect(screen.getByText('SGD 110.00')).toBeInTheDocument();
});

// A rejected save must leave the staffer's work on screen with the reason next
// to the offending field. The page blanks itself whenever the store's `error`
// is set, which is why amend() returns field errors instead of setting it.
it('shows a rejected unit price against the field and keeps the edits', async () => {
  const onSave = vi.fn().mockResolvedValue({
    'lines.0.unit_price': 'Unit price is below the configured margin floor over landed cost.',
  });
  renderEditor(onSave);
  const user = userEvent.setup();

  const priceInputs = screen.getAllByLabelText('Unit price');
  await user.clear(priceInputs[0]);
  await user.type(priceInputs[0], '1');
  await user.click(screen.getByRole('button', { name: 'Save changes' }));

  expect(await screen.findByRole('alert')).toHaveTextContent('below the configured margin floor');
  // Still editing, with the typed value intact.
  expect(screen.getAllByLabelText('Unit price')[0]).toHaveValue(1);
});

it('refuses to save when every line has been removed', async () => {
  renderEditor();
  const user = userEvent.setup();

  for (const button of screen.getAllByRole('button', { name: 'Remove' })) {
    await user.click(button);
  }

  expect(screen.getByRole('button', { name: 'Save changes' })).toBeDisabled();
});
