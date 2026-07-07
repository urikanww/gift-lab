import { useState } from 'react';
import { Button, Input } from '../ui';
import type { FieldMeta } from '../lib/pricingMeta';

export interface ConfigRow {
  id: number;
  group: string;
  key: string;
  value: unknown;
  label: string | null;
  is_money: boolean;
  currency: string | null;
  updated_at: string | null;
}

type SaveFn = (row: ConfigRow, value: unknown) => Promise<boolean>;

/**
 * Shared frame: plain-language label + one-line help, and a Save button that
 * only lights up when the value changed. Toggle-style fields save on change and
 * pass `instant` to hide the button.
 */
function FieldShell({
  meta,
  dirty,
  onSave,
  instant = false,
  children,
}: {
  meta: FieldMeta;
  dirty: boolean;
  onSave?: () => Promise<void>;
  instant?: boolean;
  children: React.ReactNode;
}) {
  const [saving, setSaving] = useState(false);
  return (
    <div className="rounded-lg border border-border p-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="text-sm font-medium text-fg">{meta.label}</p>
          {meta.help && <p className="mt-0.5 text-xs text-fg-muted">{meta.help}</p>}
        </div>
        {!instant && (
          <Button
            size="sm"
            variant="outline"
            disabled={!dirty || saving}
            loading={saving}
            onClick={async () => {
              if (!onSave) return;
              setSaving(true);
              await onSave();
              setSaving(false);
            }}
          >
            Save
          </Button>
        )}
      </div>
      <div className="mt-3">{children}</div>
    </div>
  );
}

/** Numeric input with a leading S$ (money) or trailing unit (%, days, min/g…). */
function Affixed({
  prefix,
  suffix,
  ...props
}: { prefix?: string; suffix?: string } & React.InputHTMLAttributes<HTMLInputElement>) {
  return (
    <span className="inline-flex items-center gap-2">
      {prefix && <span className="text-sm text-fg-muted">{prefix}</span>}
      <input
        type="number"
        className="w-32 rounded-md border border-border bg-surface px-3 py-2 text-sm text-fg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        {...props}
      />
      {suffix && <span className="text-sm text-fg-muted">{suffix}</span>}
    </span>
  );
}

function affixesFor(meta: FieldMeta, isMoney: boolean, currency: string | null) {
  const prefix = meta.editor === 'money' || isMoney ? (currency ?? 'SGD') : undefined;
  const suffix =
    meta.editor === 'percent' ? '%' : meta.editor === 'days' ? 'days' : meta.unit;
  return { prefix, suffix };
}

/** money / percent / number / days — a single numeric value. */
export function ScalarField({ row, meta, onSave }: { row: ConfigRow; meta: FieldMeta; onSave: SaveFn }) {
  const [draft, setDraft] = useState(String(row.value ?? ''));
  const dirty = draft !== String(row.value ?? '');
  const { prefix, suffix } = affixesFor(meta, row.is_money, row.currency);
  const step = meta.editor === 'money' ? '0.01' : meta.editor === 'percent' ? '0.1' : '1';

  return (
    <FieldShell
      meta={meta}
      dirty={dirty && draft.trim() !== '' && !Number.isNaN(Number(draft))}
      onSave={async () => {
        await onSave(row, Number(draft));
      }}
    >
      <Affixed
        prefix={prefix}
        suffix={suffix}
        step={step}
        min={meta.editor === 'money' || meta.editor === 'percent' ? '0' : undefined}
        value={draft}
        aria-label={meta.label}
        onChange={(e) => setDraft(e.target.value)}
      />
    </FieldShell>
  );
}

/** Boolean on/off — saves the moment it's flipped. */
export function ToggleField({ row, meta, onSave }: { row: ConfigRow; meta: FieldMeta; onSave: SaveFn }) {
  const on = row.value === true;
  return (
    <FieldShell meta={meta} dirty={false} instant>
      <label className="inline-flex cursor-pointer items-center gap-2 text-sm text-fg">
        <input type="checkbox" className="h-4 w-4" checked={on} onChange={(e) => void onSave(row, e.target.checked)} />
        {on ? 'On' : 'Off'}
      </label>
    </FieldShell>
  );
}

/** pay_now_cutoff object → a single "B2C pay-now" toggle; other keys preserved. */
export function B2cToggleField({ row, meta, onSave }: { row: ConfigRow; meta: FieldMeta; onSave: SaveFn }) {
  const obj = (row.value ?? {}) as Record<string, unknown>;
  const on = obj.b2c_enabled === true;
  return (
    <FieldShell meta={meta} dirty={false} instant>
      <label className="inline-flex cursor-pointer items-center gap-2 text-sm text-fg">
        <input
          type="checkbox"
          className="h-4 w-4"
          checked={on}
          onChange={(e) => void onSave(row, { ...obj, b2c_enabled: e.target.checked })}
        />
        {on ? 'Enabled' : 'Disabled'}
      </label>
    </FieldShell>
  );
}

/** Fixed-key map of numbers (S/M/L amounts, UV/FDM/RESIN costs, UV/3D days). */
export function MapField({ row, meta, onSave }: { row: ConfigRow; meta: FieldMeta; onSave: SaveFn }) {
  const initial = (row.value ?? {}) as Record<string, number>;
  const [draft, setDraft] = useState<Record<string, string>>(
    Object.fromEntries(Object.entries(initial).map(([k, v]) => [k, String(v)])),
  );
  const dirty = Object.keys(initial).some((k) => draft[k] !== String(initial[k]));
  const money = meta.editor === 'moneyMap';
  const { prefix, suffix } = money
    ? { prefix: row.currency ?? 'SGD', suffix: undefined }
    : { prefix: undefined, suffix: 'days' };

  return (
    <FieldShell
      meta={meta}
      dirty={dirty}
      onSave={async () => {
        const out: Record<string, number> = {};
        for (const k of Object.keys(initial)) out[k] = Number(draft[k]);
        await onSave(row, out);
      }}
    >
      <div className="flex flex-col gap-2">
        {Object.keys(initial).map((k) => (
          <div key={k} className="flex items-center justify-between gap-3">
            <span className="text-sm text-fg">{meta.keyLabels?.[k] ?? k}</span>
            <Affixed
              prefix={prefix}
              suffix={suffix}
              step={money ? '0.01' : '1'}
              min="0"
              value={draft[k] ?? ''}
              aria-label={meta.keyLabels?.[k] ?? k}
              onChange={(e) => setDraft((d) => ({ ...d, [k]: e.target.value }))}
            />
          </div>
        ))}
      </div>
    </FieldShell>
  );
}

interface Tier {
  max_weight_g: number | null;
  price: number;
}

/** Delivery weight bands. Blank weight = the "and above" catch-all tier. */
export function DeliveryTiersField({ row, meta, onSave }: { row: ConfigRow; meta: FieldMeta; onSave: SaveFn }) {
  const initial = (Array.isArray(row.value) ? row.value : []) as Tier[];
  const [rows, setRows] = useState(
    initial.map((t) => ({ weight: t.max_weight_g == null ? '' : String(t.max_weight_g), price: String(t.price) })),
  );
  const dirty = JSON.stringify(rows) !== JSON.stringify(
    initial.map((t) => ({ weight: t.max_weight_g == null ? '' : String(t.max_weight_g), price: String(t.price) })),
  );

  const setRow = (i: number, field: 'weight' | 'price', v: string) =>
    setRows((rs) => rs.map((r, idx) => (idx === i ? { ...r, [field]: v } : r)));

  return (
    <FieldShell
      meta={meta}
      dirty={dirty}
      onSave={async () => {
        const tiers: Tier[] = rows.map((r) => ({
          max_weight_g: r.weight.trim() === '' ? null : Number(r.weight),
          price: Number(r.price),
        }));
        // Weighted tiers ascending, catch-all (null) last, so the lookup reads
        // top-to-bottom correctly.
        tiers.sort((a, b) => (a.max_weight_g ?? Infinity) - (b.max_weight_g ?? Infinity));
        await onSave(row, tiers);
      }}
    >
      <div className="flex flex-col gap-2">
        <div className="grid grid-cols-[1fr_1fr_auto] gap-2 text-xs text-fg-subtle">
          <span>Up to weight (g)</span>
          <span>Price</span>
          <span />
        </div>
        {rows.map((r, i) => (
          <div key={i} className="grid grid-cols-[1fr_1fr_auto] items-center gap-2">
            <input
              type="number"
              min="0"
              placeholder="and above"
              className="rounded-md border border-border bg-surface px-3 py-2 text-sm text-fg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              value={r.weight}
              aria-label={`Tier ${i + 1} max weight (grams)`}
              onChange={(e) => setRow(i, 'weight', e.target.value)}
            />
            <span className="inline-flex items-center gap-2">
              <span className="text-sm text-fg-muted">{row.currency ?? 'SGD'}</span>
              <input
                type="number"
                min="0"
                step="0.01"
                className="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm text-fg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                value={r.price}
                aria-label={`Tier ${i + 1} price`}
                onChange={(e) => setRow(i, 'price', e.target.value)}
              />
            </span>
            <Button
              size="sm"
              variant="ghost"
              aria-label={`Remove tier ${i + 1}`}
              onClick={() => setRows((rs) => rs.filter((_, idx) => idx !== i))}
            >
              ✕
            </Button>
          </div>
        ))}
        <div>
          <Button size="sm" variant="ghost" onClick={() => setRows((rs) => [...rs, { weight: '', price: '' }])}>
            + Add band
          </Button>
        </div>
      </div>
    </FieldShell>
  );
}

/** Array of strings as removable chips + an add box. */
export function TagListField({ row, meta, onSave }: { row: ConfigRow; meta: FieldMeta; onSave: SaveFn }) {
  const initial = (Array.isArray(row.value) ? row.value : []) as string[];
  const [tags, setTags] = useState<string[]>(initial);
  const [entry, setEntry] = useState('');
  const dirty = JSON.stringify(tags) !== JSON.stringify(initial);

  const add = () => {
    const v = entry.trim().toLowerCase();
    if (v && !tags.includes(v)) setTags((t) => [...t, v]);
    setEntry('');
  };

  return (
    <FieldShell
      meta={meta}
      dirty={dirty}
      onSave={async () => {
        await onSave(row, tags);
      }}
    >
      <div className="flex flex-col gap-3">
        <div className="flex flex-wrap gap-2">
          {tags.length === 0 && <span className="text-xs text-fg-subtle">No keywords.</span>}
          {tags.map((t) => (
            <span key={t} className="inline-flex items-center gap-1 rounded-full bg-surface-2 px-2.5 py-1 text-xs text-fg">
              {t}
              <button
                type="button"
                aria-label={`Remove ${t}`}
                className="text-fg-muted hover:text-danger"
                onClick={() => setTags((ts) => ts.filter((x) => x !== t))}
              >
                ✕
              </button>
            </span>
          ))}
        </div>
        <div className="flex items-end gap-2">
          <Input
            label=""
            aria-label="Add keyword"
            placeholder="Add a keyword…"
            value={entry}
            onChange={(e) => setEntry(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Enter' || e.key === ',') {
                e.preventDefault();
                add();
              }
            }}
          />
          <Button size="sm" variant="outline" onClick={add} disabled={!entry.trim()}>
            Add
          </Button>
        </div>
      </div>
    </FieldShell>
  );
}

/** Dispatch a config row to the right editor for its metadata. */
export function ConfigField({ row, meta, onSave }: { row: ConfigRow; meta: FieldMeta; onSave: SaveFn }) {
  switch (meta.editor) {
    case 'toggle':
      return <ToggleField row={row} meta={meta} onSave={onSave} />;
    case 'b2cToggle':
      return <B2cToggleField row={row} meta={meta} onSave={onSave} />;
    case 'moneyMap':
    case 'daysMap':
      return <MapField row={row} meta={meta} onSave={onSave} />;
    case 'deliveryTiers':
      return <DeliveryTiersField row={row} meta={meta} onSave={onSave} />;
    case 'tagList':
      return <TagListField row={row} meta={meta} onSave={onSave} />;
    default:
      return <ScalarField row={row} meta={meta} onSave={onSave} />;
  }
}
