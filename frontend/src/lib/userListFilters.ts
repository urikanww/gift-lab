import type { AdminCompany } from '../types';
import type { FilterField, FilterValues, RangeValue } from '../components/filters/types';

/**
 * Filter config + API-param mapping for the Users list (superadmin/Users-section).
 * Mirrors the Quotes pattern: the page keeps free-text search as its own input and
 * puts the structured filters (status, role, company, created, sort) in the popup.
 * Company options are dynamic, so the config is built from the loaded companies.
 */

export function userFilterFields(companies: AdminCompany[]): FilterField[] {
  return [
    {
      key: 'company',
      label: 'Company',
      type: 'select',
      placeholder: 'All companies',
      options: companies.map((c) => ({ value: String(c.id), label: c.name })),
    },
    { key: 'created', label: 'Joined', type: 'daterange' },
  ];
}

/** Turn the popup's values into the API query params the index understands. */
export function userFiltersToParams(v: FilterValues): Record<string, string> {
  const p: Record<string, string> = {};

  if (typeof v.status === 'string' && v.status) p.status = v.status;
  if (typeof v.role === 'string' && v.role) p.role = v.role;
  if (typeof v.company === 'string' && v.company) p.company = v.company;

  const created = v.created as RangeValue | undefined;
  if (created?.from) p.created_from = created.from;
  if (created?.to) p.created_to = created.to;

  if (typeof v.sort === 'string' && v.sort) p.sort = v.sort;

  return p;
}
