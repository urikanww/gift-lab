import { afterEach, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { ThemeProvider } from '../../ui';
import ListFilters from './ListFilters';
import type { FilterField } from './types';

afterEach(cleanup);

const FIELDS: FilterField[] = [
  {
    key: 'status',
    label: 'Status',
    type: 'multiselect',
    options: [
      { value: 'DRAFT', label: 'Draft' },
      { value: 'SENT', label: 'Sent' },
    ],
  },
  {
    key: 'sort',
    label: 'Sort',
    type: 'select',
    placeholder: 'Newest first',
    options: [{ value: 'total_desc', label: 'Value: high to low' }],
  },
];

function renderFilters(value = {}, onChange = vi.fn()) {
  render(
    <ThemeProvider>
      <ListFilters fields={FIELDS} value={value} onChange={onChange} />
    </ThemeProvider>,
  );
  return onChange;
}

it('applies a chosen filter only on Apply, not while editing', () => {
  const onChange = renderFilters();

  fireEvent.click(screen.getByRole('button', { name: /^filters/i }));
  fireEvent.click(screen.getByLabelText('Draft'));
  // Editing inside the popup must not have fired anything yet.
  expect(onChange).not.toHaveBeenCalled();

  fireEvent.click(screen.getByRole('button', { name: 'Apply' }));
  expect(onChange).toHaveBeenCalledWith({ status: ['DRAFT'] });
});

it('renders an active filter as a badge and removes just that one on ×', () => {
  const onChange = renderFilters({ status: ['DRAFT', 'SENT'] });

  expect(screen.getByText('Status: Draft')).toBeInTheDocument();
  expect(screen.getByText('Status: Sent')).toBeInTheDocument();

  fireEvent.click(screen.getByRole('button', { name: 'Remove filter Status: Draft' }));
  expect(onChange).toHaveBeenCalledWith({ status: ['SENT'] });
});

it('shows the active-filter count on the button and clears all', () => {
  const onChange = renderFilters({ status: ['DRAFT'], sort: 'total_desc' });

  expect(screen.getByRole('button', { name: 'Filters (2)' })).toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: /clear all/i }));
  expect(onChange).toHaveBeenCalledWith({});
});
