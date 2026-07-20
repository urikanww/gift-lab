import { expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import Breadcrumb from './Breadcrumb';

const renderCrumbs = (items: Parameters<typeof Breadcrumb>[0]['items']) =>
  render(
    <MemoryRouter>
      <Breadcrumb items={items} />
    </MemoryRouter>,
  );

it('links every ancestor and marks the last crumb as the current page', () => {
  renderCrumbs([
    { label: 'My account', to: '/account' },
    { label: 'Saved addresses' },
  ]);

  expect(screen.getByRole('link', { name: 'My account' })).toHaveAttribute('href', '/account');
  const current = screen.getByText('Saved addresses');
  expect(current).toHaveAttribute('aria-current', 'page');
  expect(screen.queryByRole('link', { name: 'Saved addresses' })).not.toBeInTheDocument();
});

it('never links the final crumb even when a `to` is supplied', () => {
  renderCrumbs([
    { label: 'My account', to: '/account' },
    { label: 'Orders', to: '/quotes' },
  ]);

  // A link back to the page you are already on is a dead end.
  expect(screen.queryByRole('link', { name: 'Orders' })).not.toBeInTheDocument();
  expect(screen.getByText('Orders')).toHaveAttribute('aria-current', 'page');
});

it('renders nothing when there are no crumbs', () => {
  const { container } = renderCrumbs([]);

  expect(container).toBeEmptyDOMElement();
});
