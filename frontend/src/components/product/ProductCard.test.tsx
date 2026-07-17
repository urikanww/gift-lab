import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { ProductCard } from './ProductCard';
import type { Product } from '../../types';

const base: Product = {
  id: 1,
  name: 'Ceramic Mug',
  description: null,
  class: 'CORE',
  category: 'drinkware',
  from_price: 3.2,
  currency: 'SGD',
  dimensions: null,
  weight: null,
  print_method: null,
  stock_mode: 'stocked',
  availability: 'in_stock',
  image_url: null,
  is_printable: true,
  creator_credit: null,
};

function renderCard(product: Product) {
  return render(
    <MemoryRouter>
      <ProductCard product={product} to={`/products/${product.id}`} />
    </MemoryRouter>,
  );
}

describe('ProductCard availability badge', () => {
  it('renders no badge and does not throw for an unknown availability value', () => {
    expect(() => renderCard({ ...base, availability: 'discontinued' as any })).not.toThrow();
    expect(screen.queryByText(/made to order/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/out of stock/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/in stock/i)).not.toBeInTheDocument();
  });

  it('renders no badge and does not throw when availability is absent', () => {
    expect(() => renderCard({ ...base, availability: undefined as any })).not.toThrow();
    expect(screen.queryByText(/made to order/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/out of stock/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/in stock/i)).not.toBeInTheDocument();
  });

  it('still renders the "Made to order" badge for made_to_order', () => {
    renderCard({ ...base, availability: 'made_to_order' });
    expect(screen.getByText(/made to order/i)).toBeInTheDocument();
  });

  it('still renders the "Out of stock" badge for out_of_stock', () => {
    renderCard({ ...base, availability: 'out_of_stock' });
    expect(screen.getByText(/out of stock/i)).toBeInTheDocument();
  });

  it('renders no availability badge for in_stock', () => {
    renderCard({ ...base, availability: 'in_stock' });
    expect(screen.queryByText(/in stock/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/made to order/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/out of stock/i)).not.toBeInTheDocument();
  });
});
