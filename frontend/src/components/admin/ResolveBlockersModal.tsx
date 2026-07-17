import { useState } from 'react';
import { Badge, Button, Input, Modal, Select } from '../../ui';
import { apiFieldErrors } from '../../lib/api';
import { blockerHelp, blockerLabel } from '../../lib/blockerCopy';
import { useCatalogueAdminStore, type ResolveBlockersPayload } from '../../stores/catalogueAdminStore';
import type { AdminCatalogueItem } from '../../types';

/**
 * The scraped-gate blockers a staffer can clear by typing a fact off the source
 * listing. Everything else (stock_unreadable, source_dead, needs_re-review) is
 * source-truth and resolves on the next sync - see the design spec.
 */
export const FIXABLE_BLOCKERS = ['missing_dimensions', 'not_printable', 'missing_price'] as const;

export function isFixableBlocker(token: string): boolean {
  return (FIXABLE_BLOCKERS as readonly string[]).includes(token);
}

/**
 * Mirrors the server's sanity ceilings so a typo fails before a round-trip.
 * Ceilings only: the floor is "greater than zero" for every field, which
 * `parsePositive` enforces on its own.
 */
const MAX = {
  dimension: 2000,
  weight: 100000,
  price: 1000000,
} as const;

interface Props {
  product: AdminCatalogueItem;
  open: boolean;
  onClose: () => void;
  /** Called after a successful save; `published` reflects the server's verdict. */
  onResolved: (published: boolean) => void;
}

function parsePositive(raw: string, max: number): number | 'empty' | 'invalid' {
  const trimmed = raw.trim();
  if (trimmed === '') return 'empty';
  const value = Number(trimmed);
  if (!Number.isFinite(value) || value <= 0 || value > max) return 'invalid';
  return value;
}

export default function ResolveBlockersModal({ product, open, onClose, onResolved }: Props) {
  const resolveBlockers = useCatalogueAdminStore((s) => s.resolveBlockers);

  const reasons = product.cannot_publish_reasons ?? [];
  const needsDims = reasons.includes('missing_dimensions');
  const needsPrintable = reasons.includes('not_printable');
  const needsPrice = reasons.includes('missing_price');

  const [length, setLength] = useState(String(product.dimensions?.l ?? ''));
  const [width, setWidth] = useState(String(product.dimensions?.w ?? ''));
  const [height, setHeight] = useState(String(product.dimensions?.h ?? ''));
  const [weight, setWeight] = useState(product.weight ?? '');
  const [printMethod, setPrintMethod] = useState<'UV' | 'FDM' | 'RESIN'>(product.print_method ?? 'UV');
  // Blocked on price means the stored base_cost is null/zero - prefilling it
  // would just seed the field with the value the gate already rejected.
  const [baseCost, setBaseCost] = useState(needsPrice ? '' : product.base_cost);

  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  /** Set when the save persisted but the row is still blocked by source-truth. */
  const [remaining, setRemaining] = useState<string[] | null>(null);

  const submit = async () => {
    const nextErrors: Record<string, string> = {};
    const payload: ResolveBlockersPayload = {};

    // `missing_dimensions` is one token covering TWO field groups: the l/w/h
    // triple and the weight. Both have to be sound for the token to clear.
    if (needsDims) {
      const dims = { l: length, w: width, h: height };
      const parsed: Record<string, number> = {};
      (Object.keys(dims) as Array<keyof typeof dims>).forEach((key) => {
        const value = parsePositive(dims[key], MAX.dimension);
        if (value === 'empty') nextErrors[`dimensions.${key}`] = 'Required.';
        else if (value === 'invalid')
          nextErrors[`dimensions.${key}`] = `Enter a number between 1 and ${MAX.dimension} mm.`;
        else parsed[key] = value;
      });

      const w = parsePositive(weight, MAX.weight);
      if (w === 'empty') nextErrors.weight = 'Required.';
      else if (w === 'invalid') nextErrors.weight = `Enter a number between 1 and ${MAX.weight} g.`;
      else payload.weight = w;

      if (Object.keys(parsed).length === 3) {
        payload.dimensions = { l: parsed.l, w: parsed.w, h: parsed.h };
      }
    }

    if (needsPrintable) {
      payload.is_printable = true;
      payload.print_method = printMethod;
    }

    if (needsPrice) {
      const price = parsePositive(baseCost, MAX.price);
      if (price === 'empty') nextErrors.base_cost = 'Required.';
      else if (price === 'invalid') nextErrors.base_cost = 'Enter a price greater than 0.';
      else payload.base_cost = price;
    }

    setErrors(nextErrors);
    if (Object.keys(nextErrors).length > 0) return;

    setBusy(true);
    try {
      const result = await resolveBlockers(product.id, payload);
      if (result.published) {
        onResolved(true);
        onClose();
        return;
      }
      // Saved, but a source-truth blocker survives. Keep the popup open and say
      // so - the typed work persisted, which is the whole point of the 200.
      setRemaining(result.cannot_publish_reasons ?? []);
      onResolved(false);
    } catch (err) {
      // A 422 is bad input only. Map it back onto the fields it names and leave
      // the popup open so the staffer's typing survives.
      setErrors(apiFieldErrors(err));
    } finally {
      setBusy(false);
    }
  };

  if (remaining !== null) {
    return (
      <Modal
        open={open}
        onClose={onClose}
        title="Saved, but still blocked"
        description={product.name}
        footer={<Button onClick={onClose}>Close</Button>}
      >
        <div className="flex flex-col gap-3">
          <p className="text-sm text-fg-muted">
            Your changes were saved. These blockers can&apos;t be fixed here:
          </p>
          <ul className="flex flex-col gap-2">
            {remaining.map((token) => {
              const help = blockerHelp(token);
              return (
                <li key={token} className="flex flex-col gap-1">
                  <Badge tone="warning" size="sm">
                    {blockerLabel(token)}
                  </Badge>
                  {help && <span className="text-sm text-fg-subtle">{help}</span>}
                </li>
              );
            })}
          </ul>
        </div>
      </Modal>
    );
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Resolve blockers"
      description={product.name}
      footer={
        <>
          <Button variant="ghost" onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button onClick={() => void submit()} loading={busy}>
            Save and publish
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        {needsDims && (
          <div className="flex flex-col gap-3">
            <div className="grid grid-cols-3 gap-3">
              <Input
                label="Length (mm)"
                inputMode="decimal"
                value={length}
                error={errors['dimensions.l']}
                onChange={(e) => setLength(e.target.value)}
              />
              <Input
                label="Width (mm)"
                inputMode="decimal"
                value={width}
                error={errors['dimensions.w']}
                onChange={(e) => setWidth(e.target.value)}
              />
              <Input
                label="Height (mm)"
                inputMode="decimal"
                value={height}
                error={errors['dimensions.h']}
                onChange={(e) => setHeight(e.target.value)}
              />
            </div>
            <Input
              label="Weight (g)"
              inputMode="decimal"
              value={weight}
              error={errors.weight}
              hint="Per unit, in grams."
              onChange={(e) => setWeight(e.target.value)}
            />
          </div>
        )}

        {needsPrintable && (
          <Select
            label="Print method"
            value={printMethod}
            error={errors.print_method}
            hint="Marks the blank printable."
            options={[
              { value: 'UV', label: 'UV (decorate a sourced blank)' },
              { value: 'FDM', label: 'FDM (filament)' },
              { value: 'RESIN', label: 'Resin' },
            ]}
            onChange={(e) => setPrintMethod(e.target.value as 'UV' | 'FDM' | 'RESIN')}
          />
        )}

        {needsPrice && (
          <Input
            label="Base cost (SGD)"
            inputMode="decimal"
            value={baseCost}
            error={errors.base_cost}
            hint="What we pay the supplier per unit."
            onChange={(e) => setBaseCost(e.target.value)}
          />
        )}
      </div>
    </Modal>
  );
}
