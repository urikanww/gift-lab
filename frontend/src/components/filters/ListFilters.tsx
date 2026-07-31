import { useEffect, useState } from 'react';
import { Button, Input, Modal, Select } from '../../ui';
import type {
  FilterField,
  FilterValues,
  NumberRangeValue,
  RangeValue,
} from './types';

/**
 * Shared staff-list filter control: a "Filters" button that opens a popup, and
 * a row of removable badges for whatever is active. The popup edits a DRAFT and
 * only commits (→ onChange, which the page turns into a fetch) on Apply; each
 * badge's × removes just that one filter and commits immediately. See types.ts.
 */

type Piece = { label: string; onRemove: () => void };

function asArray(v: unknown): string[] {
  return Array.isArray(v) ? (v as string[]) : [];
}

function optionLabel(field: FilterField, value: string): string {
  return field.options?.find((o) => o.value === value)?.label ?? value;
}

/** The active-filter badges for one field, each with the value that removes it. */
function fieldPieces(field: FilterField, values: FilterValues, commit: (v: FilterValues) => void): Piece[] {
  const v = values[field.key];
  const clear = (next: FilterValues[string]) => commit({ ...values, [field.key]: next });

  switch (field.type) {
    case 'multiselect': {
      const arr = asArray(v);
      return arr.map((val) => ({
        label: `${field.label}: ${optionLabel(field, val)}`,
        onRemove: () => clear(arr.filter((x) => x !== val)),
      }));
    }
    case 'select':
    case 'text': {
      const s = typeof v === 'string' ? v : '';
      if (!s) return [];
      const shown = field.type === 'select' ? optionLabel(field, s) : s;
      return [{ label: `${field.label}: ${shown}`, onRemove: () => clear(undefined) }];
    }
    case 'daterange': {
      const r = (v as RangeValue) ?? {};
      if (!r.from && !r.to) return [];
      return [{ label: `${field.label}: ${r.from ?? '…'} – ${r.to ?? '…'}`, onRemove: () => clear(undefined) }];
    }
    case 'numberrange': {
      const r = (v as NumberRangeValue) ?? {};
      if (!r.min && !r.max) return [];
      return [{ label: `${field.label}: ${r.min ?? '…'} – ${r.max ?? '…'}`, onRemove: () => clear(undefined) }];
    }
    default:
      return [];
  }
}

function countActive(fields: FilterField[], values: FilterValues): number {
  return fields.reduce((n, f) => n + fieldPieces(f, values, () => {}).length, 0);
}

/** One editable control in the popup, bound to the draft. */
function FieldControl({
  field,
  draft,
  setDraft,
}: {
  field: FilterField;
  draft: FilterValues;
  setDraft: (v: FilterValues) => void;
}) {
  const set = (val: FilterValues[string]) => setDraft({ ...draft, [field.key]: val });
  const v = draft[field.key];

  switch (field.type) {
    case 'select':
      return (
        <Select
          label={field.label}
          value={typeof v === 'string' ? v : ''}
          onChange={(e) => set(e.target.value || undefined)}
          options={[{ value: '', label: field.placeholder ?? 'Any' }, ...(field.options ?? [])]}
        />
      );
    case 'multiselect': {
      const arr = asArray(v);
      return (
        <fieldset className="flex flex-col gap-1.5">
          <legend className="mb-1 text-sm font-medium text-fg">{field.label}</legend>
          <div className="flex flex-wrap gap-x-4 gap-y-1.5">
            {(field.options ?? []).map((o) => (
              <label key={o.value} className="inline-flex items-center gap-2 text-sm text-fg">
                <input
                  type="checkbox"
                  className="h-4 w-4"
                  checked={arr.includes(o.value)}
                  onChange={(e) =>
                    set(e.target.checked ? [...arr, o.value] : arr.filter((x) => x !== o.value))
                  }
                />
                {o.label}
              </label>
            ))}
          </div>
        </fieldset>
      );
    }
    case 'daterange': {
      const r = (v as RangeValue) ?? {};
      return (
        <div className="flex flex-col gap-1.5">
          <span className="text-sm font-medium text-fg">{field.label}</span>
          <div className="flex items-center gap-2">
            <Input type="date" aria-label={`${field.label} from`} value={r.from ?? ''} onChange={(e) => set({ ...r, from: e.target.value || undefined })} />
            <span className="text-fg-subtle">–</span>
            <Input type="date" aria-label={`${field.label} to`} value={r.to ?? ''} onChange={(e) => set({ ...r, to: e.target.value || undefined })} />
          </div>
        </div>
      );
    }
    case 'numberrange': {
      const r = (v as NumberRangeValue) ?? {};
      return (
        <div className="flex flex-col gap-1.5">
          <span className="text-sm font-medium text-fg">{field.label}</span>
          <div className="flex items-center gap-2">
            <Input type="number" aria-label={`${field.label} min`} placeholder="Min" value={r.min ?? ''} onChange={(e) => set({ ...r, min: e.target.value || undefined })} />
            <span className="text-fg-subtle">–</span>
            <Input type="number" aria-label={`${field.label} max`} placeholder="Max" value={r.max ?? ''} onChange={(e) => set({ ...r, max: e.target.value || undefined })} />
          </div>
        </div>
      );
    }
    case 'text':
      return (
        <Input
          label={field.label}
          placeholder={field.placeholder}
          value={typeof v === 'string' ? v : ''}
          onChange={(e) => set(e.target.value || undefined)}
        />
      );
    default:
      return null;
  }
}

export default function ListFilters({
  fields,
  value,
  onChange,
}: {
  fields: FilterField[];
  value: FilterValues;
  onChange: (v: FilterValues) => void;
}) {
  const [open, setOpen] = useState(false);
  const [draft, setDraft] = useState<FilterValues>(value);

  // Re-seed the draft from the committed value whenever the popup opens, so a
  // cancelled edit never leaks into the next open.
  useEffect(() => {
    if (open) setDraft(value);
  }, [open, value]);

  const activeCount = countActive(fields, value);
  const badges = fields.flatMap((f) => fieldPieces(f, value, onChange));

  return (
    <div className="flex flex-wrap items-center gap-2">
      <Button variant="outline" size="sm" onClick={() => setOpen(true)}>
        Filters{activeCount > 0 ? ` (${activeCount})` : ''}
      </Button>

      {badges.map((b, i) => (
        <span
          key={`${b.label}-${i}`}
          className="inline-flex items-center gap-1.5 rounded-full border border-border bg-surface-2 py-1 pl-3 pr-1.5 text-xs text-fg"
        >
          {b.label}
          <button
            type="button"
            aria-label={`Remove filter ${b.label}`}
            onClick={b.onRemove}
            className="flex h-4 w-4 items-center justify-center rounded-full text-fg-subtle hover:bg-surface-3 hover:text-fg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          >
            <span aria-hidden="true">×</span>
          </button>
        </span>
      ))}

      {activeCount > 0 && (
        <Button variant="ghost" size="sm" onClick={() => onChange({})}>
          Clear all
        </Button>
      )}

      <Modal
        open={open}
        onClose={() => setOpen(false)}
        title="Filters"
        description="Set filters, then apply."
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="ghost" onClick={() => setDraft({})}>
              Clear
            </Button>
            <Button
              variant="primary"
              onClick={() => {
                onChange(draft);
                setOpen(false);
              }}
            >
              Apply
            </Button>
          </div>
        }
      >
        <div className="flex flex-col gap-5">
          {fields.map((f) => (
            <FieldControl key={f.key} field={f} draft={draft} setDraft={setDraft} />
          ))}
        </div>
      </Modal>
    </div>
  );
}
