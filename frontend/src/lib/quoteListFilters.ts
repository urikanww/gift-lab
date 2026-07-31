import type {
  FilterField,
  FilterValues,
  NumberRangeValue,
  RangeValue,
} from '../components/filters/types';
import { humanizeState } from './quoteStatus';

/**
 * Filter config + API-param mapping for the Quotes list. Kept out of the page so
 * the same shape is easy to reuse and test. Status values are the raw order
 * states (the API filters on them); labels are humanised for staff.
 */

const STATUSES = [
  'DRAFT',
  'SENT',
  'CHANGES_REQUESTED',
  'ACCEPTED',
  'PROOFING',
  'ARTWORK_APPROVED',
  'PROOF_APPROVED',
  'CONFIRMED',
  'PROCURING',
  'READY',
  'CLOSED',
  'CANCELLED',
];

export function quoteFilterFields(staff: boolean): FilterField[] {
  const fields: FilterField[] = [
    {
      key: 'status',
      label: 'Status',
      type: 'multiselect',
      options: STATUSES.map((s) => ({ value: s, label: humanizeState(s) })),
    },
  ];

  // Payment / company / value are staff-only: a buyer sees only their own
  // orders, so filtering by company is meaningless and payment state is staff AR.
  if (staff) {
    fields.push(
      {
        key: 'payment',
        label: 'Payment',
        type: 'multiselect',
        options: [
          { value: 'UNPAID', label: 'Unpaid' },
          { value: 'PARTIAL', label: 'Partial' },
          { value: 'PAID', label: 'Paid' },
        ],
      },
      { key: 'company', label: 'Company', type: 'text', placeholder: 'Company name' },
      { key: 'total', label: 'Order value', type: 'numberrange' },
    );
  }

  fields.push(
    { key: 'created', label: 'Created', type: 'daterange' },
    { key: 'needed', label: 'Needed by', type: 'daterange' },
    {
      key: 'sort',
      label: 'Sort',
      type: 'select',
      placeholder: 'Newest first',
      options: [
        { value: 'created_asc', label: 'Oldest first' },
        { value: 'total_desc', label: 'Value: high to low' },
        { value: 'total_asc', label: 'Value: low to high' },
      ],
    },
  );

  return fields;
}

/** Turn the popup's values into the API query params the index understands. */
export function quoteFiltersToParams(v: FilterValues): Record<string, string> {
  const p: Record<string, string> = {};

  if (Array.isArray(v.status) && v.status.length) p.status = v.status.join(',');
  if (Array.isArray(v.payment) && v.payment.length) p.payment = v.payment.join(',');
  if (typeof v.company === 'string' && v.company) p.company = v.company;

  const total = v.total as NumberRangeValue | undefined;
  if (total?.min) p.min_total = total.min;
  if (total?.max) p.max_total = total.max;

  const created = v.created as RangeValue | undefined;
  if (created?.from) p.created_from = created.from;
  if (created?.to) p.created_to = created.to;

  const needed = v.needed as RangeValue | undefined;
  if (needed?.from) p.needed_from = needed.from;
  if (needed?.to) p.needed_to = needed.to;

  if (typeof v.sort === 'string' && v.sort) p.sort = v.sort;

  return p;
}

/** The dashboard "Delivered · unpaid" tile deep-links here; seed those filters. */
export function deliveredUnpaidSeed(): FilterValues {
  return { status: ['CLOSED'], payment: ['UNPAID', 'PARTIAL'], sort: 'created_asc' };
}
