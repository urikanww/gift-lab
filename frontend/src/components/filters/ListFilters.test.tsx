import { afterEach, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { ThemeProvider } from '../../ui';
import ListFilters, { FilterBadges } from './ListFilters';
import type { FilterField, FilterValues } from './types';

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

function renderFilters(value: FilterValues = {}, onChange = vi.fn()) {
  render(
    <ThemeProvider>
      <ListFilters fields={FIELDS} value={value} onChange={onChange} />
    </ThemeProvider>,
  );
  return onChange;
}

function renderBadges(value: FilterValues = {}, onChange = vi.fn()) {
  render(
    <ThemeProvider>
      <FilterBadges fields={FIELDS} value={value} onChange={onChange} />
    </ThemeProvider>,
  );
  return onChange;
}

it('multi-select defaults to all; unchecking one narrows, and only Apply commits', () => {
  const onChange = renderFilters();

  fireEvent.click(screen.getByRole('button', { name: /^filters/i }));
  // Status defaults to all-checked (no filter); unchecking one narrows it.
  fireEvent.click(screen.getByLabelText('Sent')); // was checked (all) → now just Draft
  // Editing inside the popup must not have fired anything yet.
  expect(onChange).not.toHaveBeenCalled();

  fireEvent.click(screen.getByRole('button', { name: 'Apply' }));
  expect(onChange).toHaveBeenCalledWith({ status: ['DRAFT'] });
});

it('renders a partial multi-select as one summary badge that clears back to all', () => {
  const onChange = renderBadges({ status: ['DRAFT'] });

  expect(screen.getByText('Status: Draft')).toBeInTheDocument();

  fireEvent.click(screen.getByRole('button', { name: 'Remove filter Status: Draft' }));
  expect(onChange).toHaveBeenCalledWith({ status: undefined });
});

it('shows the active-filter count on the trigger button', () => {
  renderFilters({ status: ['DRAFT'], sort: 'total_desc' });
  expect(screen.getByRole('button', { name: /filters/i })).toHaveTextContent('2');
});

it('clears every filter from the badges row', () => {
  const onChange = renderBadges({ status: ['DRAFT'], sort: 'total_desc' });
  fireEvent.click(screen.getByRole('button', { name: /clear all/i }));
  expect(onChange).toHaveBeenCalledWith({});
});
