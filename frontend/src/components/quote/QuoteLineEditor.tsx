import { useMemo, useState } from 'react';
import { Button, Input } from '../../ui';
import ProductCombobox, { type ProductOption } from '../ProductCombobox';
import type { AmendPayload } from '../../stores/quoteStore';
import type { LineItem, Quote } from '../../types';

/**
 * Staff editor for a DRAFT's lines, delivery and notes.
 *
 * Exists because staff confirm every order against the real source before it
 * goes out: stock the supplier actually holds, a marketplace price that moved
 * overnight, delivery that drops when the goods fold and stack. That is where
 * the margin is made, so it is a first-class screen rather than a dialog.
 *
 * Two behaviours worth knowing:
 *
 *  - **Only changed lines are submitted.** The service merges amendments over
 *    the quote's full line set, so an untouched line keeps its price without
 *    being re-validated. That matters: an order quoted under an older margin
 *    floor would otherwise become permanently unsaveable the moment anything
 *    else on it changed.
 *  - **Removal is explicit.** Omitting a line means "leave it alone", so
 *    removals travel in their own list. Nothing is destroyed until Save, and
 *    a pending removal can be undone.
 */

interface EditorRow {
  /** Stable local key; rows are added and removed before any of them have ids. */
  key: string;
  /** Absent for a row being added. */
  lineId?: number;
  productId: number;
  productName: string;
  variantId: number | null;
  qty: string;
  unitPrice: string;
  removed: boolean;
}

function toRows(items: LineItem[]): EditorRow[] {
  return items.map((li) => ({
    key: `line-${li.id}`,
    lineId: li.id,
    productId: li.product_id,
    productName: li.product?.name ?? `Product #${li.product_id}`,
    variantId: li.variant_id,
    qty: String(li.qty),
    unitPrice: li.unit_price,
    removed: false,
  }));
}

/** Money parse that treats a half-typed value as 0 rather than NaN. */
function num(value: string): number {
  const n = Number(value);
  return Number.isFinite(n) ? n : 0;
}

export default function QuoteLineEditor({
  quote,
  onCancel,
  onSave,
}: {
  quote: Quote;
  onCancel: () => void;
  /** Resolves to field errors keyed as the API sends them; empty means saved. */
  onSave: (payload: AmendPayload) => Promise<Record<string, string>>;
}) {
  const original = useMemo(() => quote.line_items ?? [], [quote.line_items]);
  const [rows, setRows] = useState<EditorRow[]>(() => toRows(original));
  const [delivery, setDelivery] = useState(quote.delivery);
  const [notes, setNotes] = useState(quote.notes ?? '');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const [addedSeq, setAddedSeq] = useState(0);

  const originalById = useMemo(
    () => new Map(original.map((li) => [li.id, li])),
    [original],
  );

  const patch = (key: string, changes: Partial<EditorRow>) =>
    setRows((rs) => rs.map((r) => (r.key === key ? { ...r, ...changes } : r)));

  const addRow = (product: ProductOption) => {
    setRows((rs) => [
      ...rs,
      {
        key: `new-${addedSeq}`,
        productId: product.id,
        productName: product.name,
        variantId: null,
        qty: '1',
        unitPrice: '0.00',
        removed: false,
      },
    ]);
    setAddedSeq((n) => n + 1);
  };

  const live = rows.filter((r) => !r.removed);
  const subtotal = live.reduce((sum, r) => sum + num(r.qty) * num(r.unitPrice), 0);
  const total = subtotal + num(delivery);

  /** A row differs from what the server holds, so it must be submitted. */
  const isChanged = (row: EditorRow): boolean => {
    if (row.lineId === undefined) return true;
    const before = originalById.get(row.lineId);
    if (!before) return true;
    return num(row.qty) !== before.qty || num(row.unitPrice) !== num(before.unit_price);
  };

  // Submitted rows, in payload order. Held as one list so a server error keyed
  // `lines.2.unit_price` can be mapped back to the row that produced index 2.
  const submitted = live.filter(isChanged);
  const removedLineIds = rows
    .filter((r) => r.removed && r.lineId !== undefined)
    .map((r) => r.lineId as number);

  const deliveryChanged = num(delivery) !== num(quote.delivery);
  const notesChanged = notes !== (quote.notes ?? '');
  const dirty =
    submitted.length > 0 || removedLineIds.length > 0 || deliveryChanged || notesChanged;

  const errorFor = (row: EditorRow, field: 'qty' | 'unit_price'): string | undefined => {
    const index = submitted.indexOf(row);
    return index === -1 ? undefined : errors[`lines.${index}.${field}`];
  };

  const save = async () => {
    setSaving(true);
    try {
      const result = await onSave({
        lines: submitted.map((r) => ({
          ...(r.lineId !== undefined ? { id: r.lineId } : { product_id: r.productId }),
          variant_id: r.variantId,
          qty: num(r.qty),
          unit_price: num(r.unitPrice),
        })),
        delivery: deliveryChanged ? num(delivery) : undefined,
        notes: notesChanged ? notes : undefined,
        removed_line_ids: removedLineIds,
      });
      setErrors(result);
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="flex flex-col gap-4 px-5 py-4">
      {errors._form && (
        <p role="alert" className="rounded-md border border-danger/30 bg-danger-bg p-3 text-sm text-fg">
          {errors._form}
        </p>
      )}
      {errors.removed_line_ids && (
        <p role="alert" className="rounded-md border border-danger/30 bg-danger-bg p-3 text-sm text-fg">
          {errors.removed_line_ids}
        </p>
      )}

      <ul className="flex flex-col divide-y divide-border">
        {rows.map((row) => (
          <li
            key={row.key}
            className={
              'flex flex-col gap-3 py-3 md:flex-row md:items-start md:gap-4 ' +
              (row.removed ? 'opacity-50' : '')
            }
          >
            <div className="min-w-0 flex-1">
              <span className="block text-sm font-medium text-fg">{row.productName}</span>
              {row.lineId === undefined && <span className="text-xs text-fg-subtle">New line</span>}
              {row.removed && <span className="text-xs text-fg-subtle">Will be removed on save</span>}
            </div>

            <div className="w-full md:w-24">
              <Input
                label="Qty"
                type="number"
                min={1}
                inputMode="numeric"
                value={row.qty}
                disabled={row.removed}
                error={errorFor(row, 'qty')}
                onChange={(e) => patch(row.key, { qty: e.target.value })}
              />
            </div>

            <div className="w-full md:w-36">
              <Input
                label="Unit price"
                type="number"
                min={0}
                step="0.01"
                inputMode="decimal"
                value={row.unitPrice}
                disabled={row.removed}
                error={errorFor(row, 'unit_price')}
                onChange={(e) => patch(row.key, { unitPrice: e.target.value })}
              />
            </div>

            <div className="w-full text-right tabular-nums text-sm text-fg md:w-28 md:pt-7">
              {quote.currency} {(num(row.qty) * num(row.unitPrice)).toFixed(2)}
            </div>

            <div className="md:pt-6">
              {row.removed ? (
                <Button variant="ghost" size="sm" onClick={() => patch(row.key, { removed: false })}>
                  Undo
                </Button>
              ) : (
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() =>
                    row.lineId === undefined
                      ? setRows((rs) => rs.filter((r) => r.key !== row.key))
                      : patch(row.key, { removed: true })
                  }
                >
                  Remove
                </Button>
              )}
            </div>
          </li>
        ))}
      </ul>

      <div className="max-w-sm">
        {/* Price is left at 0.00 for staff to fill: the whole reason for this
            screen is that the catalogue price is not what the supplier is
            charging today. The margin floor is enforced on save. */}
        <ProductCombobox value={null} onChange={addRow} label="Add a line" />
      </div>

      <div className="flex flex-col gap-3 border-t border-border pt-4 md:flex-row md:items-start md:justify-between">
        <div className="w-full md:max-w-xs">
          <Input
            label="Delivery"
            type="number"
            min={0}
            step="0.01"
            inputMode="decimal"
            value={delivery}
            error={errors.delivery}
            onChange={(e) => setDelivery(e.target.value)}
          />
          <div className="mt-3">
            <Input
              label="Notes"
              value={notes}
              error={errors.notes}
              onChange={(e) => setNotes(e.target.value)}
            />
          </div>
        </div>

        <dl className="ml-auto flex w-full max-w-xs flex-col gap-2">
          <div className="flex justify-between text-sm">
            <dt className="text-fg-muted">Subtotal</dt>
            <dd className="tabular-nums text-fg">
              {quote.currency} {subtotal.toFixed(2)}
            </dd>
          </div>
          <div className="flex justify-between text-sm">
            <dt className="text-fg-muted">Delivery</dt>
            <dd className="tabular-nums text-fg">
              {quote.currency} {num(delivery).toFixed(2)}
            </dd>
          </div>
          <div className="mt-1 flex items-baseline justify-between border-t border-border pt-2">
            <dt className="font-medium text-fg">Total</dt>
            <dd className="font-display text-xl text-fg">
              {quote.currency} {total.toFixed(2)}
            </dd>
          </div>
        </dl>
      </div>

      <div className="flex justify-end gap-2 border-t border-border pt-4">
        <Button variant="ghost" onClick={onCancel} disabled={saving}>
          Cancel
        </Button>
        <Button onClick={() => void save()} loading={saving} disabled={!dirty || live.length === 0}>
          Save changes
        </Button>
      </div>
    </div>
  );
}
