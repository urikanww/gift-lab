import { Card } from '../../ui';
import type { AmendmentLogEntry } from '../../types';

/**
 * Staff-only trail of DRAFT edits: what changed, who changed it and when. Fed
 * from the order's `amendment_log`, which the API returns to staff only - it
 * carries internal prices and margins, so this component must never render on a
 * buyer's view. The caller guards on role; this one guards on emptiness.
 *
 * Entries from a single save share a `batch`, so they are grouped into one
 * "Ada Ops · 21 Jul 2026, 14:02" block rather than shown as loose rows. Newest
 * save first - the most recent change is what staff reach for.
 */

interface Batch {
  key: string;
  by: string;
  at: string | null;
  items: AmendmentLogEntry[];
}

/** Preserve within-save order, but surface the most recent save first. */
function groupByBatch(entries: AmendmentLogEntry[]): Batch[] {
  const groups: Batch[] = [];
  const byKey = new Map<string, Batch>();
  for (const e of entries) {
    // Fall back to a per-entry key for legacy rows written before batching, so
    // each still renders as its own group rather than silently merging.
    const key = e.batch ?? `${e.at ?? 'unknown'}-${e.by ?? 'unknown'}-${groups.length}`;
    let group = byKey.get(key);
    if (!group) {
      group = { key, by: e.by_name?.trim() || 'System', at: e.at ?? null, items: [] };
      byKey.set(key, group);
      groups.push(group);
    }
    group.items.push(e);
  }
  return groups.reverse();
}

function formatAt(at: string | null): string | null {
  if (!at) return null;
  const d = new Date(at);
  if (Number.isNaN(d.getTime())) return null;
  return d.toLocaleString(undefined, {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

function money(value: unknown, currency: string): string {
  const n = Number(value);
  if (Number.isNaN(n)) return String(value ?? '');
  return `${currency} ${n.toFixed(2)}`;
}

function num(value: unknown): string {
  const n = Number(value);
  return Number.isNaN(n) ? String(value ?? '') : String(n);
}

/** One human-readable line describing a single change. */
function describe(entry: AmendmentLogEntry, currency: string): string {
  const name = entry.product_name ?? (entry.line_item_id ? `Line #${entry.line_item_id}` : 'Item');
  const from = entry.from ?? {};
  const to = entry.to ?? {};
  switch (entry.action) {
    case 'added':
      return `Added ${name}: ${num(to.qty)} × ${money(to.unit_price, currency)}`;
    case 'removed':
      return `Removed ${name}: was ${num(from.qty)} × ${money(from.unit_price, currency)}`;
    case 'delivery':
      return `Delivery: ${money(from.delivery, currency)} → ${money(to.delivery, currency)}`;
    case 'notes':
      return 'Notes updated';
    case 'edited':
    default:
      return `${name}: ${num(from.qty)} × ${money(from.unit_price, currency)} → ${num(to.qty)} × ${money(to.unit_price, currency)}`;
  }
}

export default function AmendmentHistory({
  entries,
  currency,
}: {
  entries: AmendmentLogEntry[];
  currency: string;
}) {
  // An order that was never amended has nothing to show. Render nothing rather
  // than an empty card competing for space in the staff column.
  if (!entries || entries.length === 0) return null;

  const batches = groupByBatch(entries);

  return (
    <Card padding="lg" aria-labelledby="edits-heading">
      <h2 id="edits-heading" className="font-display text-xl text-fg">
        Edit history
      </h2>
      <p className="mt-1 text-sm text-fg-muted">
        Changes made to this order while it was a draft. Staff-only.
      </p>

      <ul className="mt-4 flex flex-col divide-y divide-border">
        {batches.map((batch) => {
          const when = formatAt(batch.at);
          return (
            <li key={batch.key} className="flex flex-col gap-2 py-4 first:pt-0">
              <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                <span className="font-medium text-fg">{batch.by}</span>
                {when && (
                  <time dateTime={batch.at ?? undefined} className="text-sm tabular-nums text-fg-muted">
                    {when}
                  </time>
                )}
              </div>
              <ul className="flex flex-col gap-1">
                {batch.items.map((item, i) => (
                  <li key={`${batch.key}-${i}`} className="text-sm text-fg-muted">
                    {describe(item, currency)}
                  </li>
                ))}
              </ul>
            </li>
          );
        })}
      </ul>
    </Card>
  );
}
