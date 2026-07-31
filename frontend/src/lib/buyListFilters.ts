import type { FilterField, FilterValues, RangeValue } from '../components/filters/types';

/**
 * Filter config + API-param mapping for the Buy-list (supplier reorders). Kept
 * out of the page so the same shape is easy to reuse and test, mirroring
 * quoteListFilters. State values are the raw ReorderState enum cases (the API
 * filters on them); labels are humanised for staff.
 */

// The raw ReorderState enum values (app/Enums/ReorderState.php). Passing an
// explicit state filter overrides the list's default "open only" constraint, so
// RECEIVED is selectable here even though the default view hides it.
const STATES = ['DRAFT', 'APPROVED', 'ORDERED', 'RECEIVED'];

/** Title-case a raw upper-snake state (DRAFT → Draft, ORDERED → Ordered). */
function humanizeReorderState(state: string): string {
  return state
    .toLowerCase()
    .split('_')
    .map((w) => w.charAt(0).toUpperCase() + w.slice(1))
    .join(' ');
}

export function buyListFilterFields(): FilterField[] {
  return [
    {
      key: 'kind',
      label: 'Kind',
      type: 'select',
      placeholder: 'All kinds',
      options: [
        { value: 'variant', label: 'Variant' },
        { value: 'filament', label: 'Filament' },
      ],
    },
    {
      key: 'state',
      label: 'State',
      type: 'multiselect',
      options: STATES.map((s) => ({ value: s, label: humanizeReorderState(s) })),
    },
    {
      // No checkbox field type exists, so a single-option select is the clean way
      // to express this boolean toggle: "Any stock" (default) vs negative-only.
      key: 'negative_only',
      label: 'Stock',
      type: 'select',
      placeholder: 'Any stock',
      options: [{ value: '1', label: 'Negative on-hand only' }],
    },
    { key: 'created', label: 'Created', type: 'daterange' },
    {
      key: 'sort',
      label: 'Sort',
      type: 'select',
      placeholder: 'Newest first',
      options: [{ value: 'oldest', label: 'Oldest first' }],
    },
  ];
}

/** Turn the popup's values into the API query params the index understands. */
export function buyListFiltersToParams(v: FilterValues): Record<string, string> {
  const p: Record<string, string> = {};

  if (typeof v.kind === 'string' && v.kind) p.kind = v.kind;
  if (Array.isArray(v.state) && v.state.length) p.state = v.state.join(',');
  if (typeof v.negative_only === 'string' && v.negative_only) p.negative_only = v.negative_only;

  const created = v.created as RangeValue | undefined;
  if (created?.from) p.created_from = created.from;
  if (created?.to) p.created_to = created.to;

  if (typeof v.sort === 'string' && v.sort) p.sort = v.sort;

  return p;
}
