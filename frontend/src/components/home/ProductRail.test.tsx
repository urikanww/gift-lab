import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import ProductRail from './ProductRail';
import type { Product } from '../../types';

const items: Product[] = [1, 2, 3].map(
  (id) =>
    ({
      id,
      name: `Product ${id}`,
      class: 'CORE',
      category: 'stationery',
      from_price: 7.58,
      currency: 'SGD',
      is_printable: true,
      availability: 'in_stock',
    }) as Product,
);

const renderRail = () =>
  render(
    <MemoryRouter>
      <ProductRail items={items} label="new arrivals" />
    </MemoryRouter>,
  );

describe('ProductRail', () => {
  it('renders one card per item', () => {
    renderRail();
    items.forEach((p) => expect(screen.getByText(p.name)).toBeInTheDocument());
  });

  it('labels its buttons from the label prop', () => {
    renderRail();
    expect(screen.getByRole('button', { name: /previous new arrivals/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /next new arrivals/i })).toBeInTheDocument();
  });

  it('disables the previous button at the start', () => {
    renderRail();
    // jsdom reports 0 for every scroll metric, so the rail is simultaneously at
    // start and at end. Only the start edge is meaningfully assertable here.
    expect(screen.getByRole('button', { name: /previous new arrivals/i })).toBeDisabled();
  });
});
