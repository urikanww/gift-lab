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
      key: 'status',
      label: 'Status',
      type: 'select',
      // Empty = the API default ("active"), so it isn't shown as an active badge.
      placeholder: 'Active',
      options: [
        { value: 'deactivated', label: 'Deactivated' },
        { value: 'all', label: 'All' },
      ],
    },
    {
      key: 'role',
      label: 'Role',
      type: 'select',
      placeholder: 'All roles',
      options: [
        { value: 'buyer', label: 'Buyer' },
        { value: 'staff_admin', label: 'Staff admin' },
        { value: 'superadmin', label: 'Superadmin' },
      ],
    },
    {
      key: 'company',
      label: 'Company',
      type: 'select',
      placeholder: 'All companies',
      options: companies.map((c) => ({ value: String(c.id), label: c.name })),
    },
    { key: 'created', label: 'Joined', type: 'daterange' },
    {
      key: 'sort',
      label: 'Sort',
      type: 'select',
      placeholder: 'Name: A to Z',
      options: [
        { value: 'name_desc', label: 'Name: Z to A' },
        { value: 'created_desc', label: 'Newest first' },
        { value: 'created_asc', label: 'Oldest first' },
      ],
    },
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
