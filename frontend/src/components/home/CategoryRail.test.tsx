import { expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import CategoryRail from './CategoryRail';
import { CATEGORIES } from '../../lib/categories';

it('renders every category as a link to its filtered catalogue', () => {
  render(
    <MemoryRouter>
      <CategoryRail />
    </MemoryRouter>,
  );

  const links = screen.getAllByRole('link');
  expect(links).toHaveLength(CATEGORIES.length);
  CATEGORIES.forEach((c) => {
    expect(screen.getByRole('link', { name: new RegExp(c.label, 'i') })).toHaveAttribute(
      'href',
      `/products?category=${c.key}`,
    );
  });
});
