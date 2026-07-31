/**
 * Config-driven list filters, shared by every staff list (Quotes, Production,
 * Procurement, …). A page declares its `FilterField[]`; ListFilters renders the
 * popup + active-filter badges and hands back a plain values object the page
 * maps onto its API params. Applying (or removing a badge) is the only thing
 * that triggers a fetch — typing inside the popup does not.
 */

export interface FilterOption {
  value: string;
  label: string;
}

export type FilterFieldType = 'select' | 'multiselect' | 'daterange' | 'numberrange' | 'text';

export interface FilterField {
  /** Key in the values object (and, by the page's mapping, the API param). */
  key: string;
  label: string;
  type: FilterFieldType;
  /** For select / multiselect. */
  options?: FilterOption[];
  placeholder?: string;
}

/** A from/to pair for date ranges (ISO yyyy-mm-dd strings). */
export interface RangeValue {
  from?: string;
  to?: string;
}

/** A min/max pair for number ranges (kept as strings, like form inputs). */
export interface NumberRangeValue {
  min?: string;
  max?: string;
}

/**
 * Per-field value shapes:
 *  - select / text  → string
 *  - multiselect    → string[]
 *  - daterange      → RangeValue
 *  - numberrange    → NumberRangeValue
 */
export type FilterValues = Record<string, string | string[] | RangeValue | NumberRangeValue | undefined>;
