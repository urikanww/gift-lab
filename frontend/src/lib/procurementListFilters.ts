import type { FilterField, FilterValues, RangeValue } from '../components/filters/types';

/**
 * Filter config + API-param mapping for the Procurement desk. Kept out of the
 * page so the same shape is easy to reuse and test, mirroring quoteListFilters.
 *
 * NOTE: `reason` (qty_short / price_jumped) is derived client-side in the store
 * from procured_qty vs qty, so it is deliberately NOT a server filter here.
 */
export function procurementFilterFields(): FilterField[] {
  return [
    { key: 'updated', label: 'Updated', type: 'daterange' },
    {
      key: 'sort',
      label: 'Sort',
      type: 'select',
      // Default (no value) is the server's oldest-first aging order.
      placeholder: 'Oldest first',
      options: [{ value: 'newest', label: 'Newest first' }],
    },
  ];
}

/** Turn the popup's values into the API query params the index understands. */
export function procurementFiltersToParams(v: FilterValues): Record<string, string> {
  const p: Record<string, string> = {};

  const updated = v.updated as RangeValue | undefined;
  if (updated?.from) p.updated_from = updated.from;
  if (updated?.to) p.updated_to = updated.to;

  if (typeof v.sort === 'string' && v.sort) p.sort = v.sort;

  return p;
}
