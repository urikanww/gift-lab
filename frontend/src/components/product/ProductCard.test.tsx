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

  // The label also appears as the text link's sr-only summary, so these scope to
  // the visual badge; the summary is covered by the AT describe block below.
  it('still renders the "Made to order" badge for made_to_order', () => {
    renderCard({ ...base, availability: 'made_to_order' });
    expect(screen.getByText(/made to order/i, { selector: ':not(.sr-only)' })).toBeInTheDocument();
  });

  it('still renders the "Out of stock" badge for out_of_stock', () => {
    renderCard({ ...base, availability: 'out_of_stock' });
    expect(screen.getByText(/out of stock/i, { selector: ':not(.sr-only)' })).toBeInTheDocument();
  });

  it('renders no availability badge for in_stock', () => {
    renderCard({ ...base, availability: 'in_stock' });
    expect(screen.queryByText(/in stock/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/made to order/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/out of stock/i)).not.toBeInTheDocument();
  });
});

function renderCardWithMeta(product: Product, showMeta: boolean) {
  return render(
    <MemoryRouter>
      <ProductCard product={product} to={`/products/${product.id}`} showMeta={showMeta} />
    </MemoryRouter>,
  );
}

describe('ProductCard MOQ badge', () => {
  it('shows the MOQ badge for a bulk-only product when showMeta is true', () => {
    renderCardWithMeta({ ...base, min_order_qty: 50 }, true);
    expect(screen.getByText(/min\. 50 units/i)).toBeInTheDocument();
  });

  it('hides the MOQ badge when min_order_qty is 1', () => {
    renderCardWithMeta({ ...base, min_order_qty: 1 }, true);
    expect(screen.queryByText(/min\./i)).not.toBeInTheDocument();
  });

  it('hides the MOQ badge when min_order_qty is absent', () => {
    renderCardWithMeta({ ...base, min_order_qty: undefined }, true);
    expect(screen.queryByText(/min\./i)).not.toBeInTheDocument();
  });

  it('hides the MOQ badge when showMeta is false, even for a bulk-only product', () => {
    renderCardWithMeta({ ...base, min_order_qty: 50 }, false);
    expect(screen.queryByText(/min\./i)).not.toBeInTheDocument();
  });

  // The badges live inside the image link, whose aria-label swallows them, so
  // the MOQ reaches AT users through the text link's content instead.
  it('exposes the MOQ through the text link accessible name', () => {
    renderCardWithMeta({ ...base, min_order_qty: 50 }, true);
    expect(screen.getByRole('link', { name: /minimum 50 units/i })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Ceramic Mug' })).toBeInTheDocument();
  });

  it('leaves the image link accessible name as the plain product name when not bulk-only', () => {
    renderCardWithMeta({ ...base, min_order_qty: 1 }, true);
    expect(screen.getByRole('link', { name: 'Ceramic Mug' })).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /minimum/i })).not.toBeInTheDocument();
  });

  // Parity: no badge renders without showMeta, so the accessible name must not
  // announce an MOQ that sighted users can't see.
  it('keeps the MOQ out of the accessible name when showMeta is false', () => {
    renderCardWithMeta({ ...base, min_order_qty: 50 }, false);
    expect(screen.getByRole('link', { name: 'Ceramic Mug' })).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /minimum/i })).not.toBeInTheDocument();
  });
});

/**
 * The badges are painted over the image link, whose aria-label replaces its
 * subtree for assistive tech - so every assertion here goes through an
 * accessible name (what AT actually announces), never through raw text
 * presence, which would pass even while the badge is being swallowed.
 */
describe('ProductCard meta announced to assistive tech', () => {
  it('announces "Made to order" through the text link accessible name', () => {
    renderCardWithMeta({ ...base, availability: 'made_to_order' }, true);
    expect(screen.getByRole('link', { name: /made to order/i })).toBeInTheDocument();
  });

  it('announces "Out of stock" through the text link accessible name', () => {
    renderCardWithMeta({ ...base, availability: 'out_of_stock' }, true);
    expect(screen.getByRole('link', { name: /out of stock/i })).toBeInTheDocument();
  });

  it('announces nothing about availability for in_stock', () => {
    renderCardWithMeta({ ...base, availability: 'in_stock' }, true);
    expect(screen.queryByRole('link', { name: /in stock/i })).not.toBeInTheDocument();
  });

  // Parity: the availability badge is NOT gated on showMeta, so its
  // announcement must not be either.
  it('announces availability even when showMeta is false', () => {
    renderCardWithMeta({ ...base, availability: 'made_to_order' }, false);
    expect(screen.getByRole('link', { name: /made to order/i })).toBeInTheDocument();
    // ...and stays silent about the meta that showMeta suppressed.
    expect(screen.queryByRole('link', { name: /drinkware/i })).not.toBeInTheDocument();
  });

  it('announces nothing and does not throw for an unknown availability value', () => {
    expect(() =>
      renderCardWithMeta({ ...base, availability: 'discontinued' as any }, true),
    ).not.toThrow();
    expect(screen.queryByRole('link', { name: /discontinued/i })).not.toBeInTheDocument();
    // The category still announces; only the unknown availability drops out.
    expect(screen.getByRole('link', { name: /drinkware/i })).toBeInTheDocument();
  });

  it('announces the category when showMeta is true', () => {
    renderCardWithMeta(base, true);
    expect(screen.getByRole('link', { name: /drinkware/i })).toBeInTheDocument();
  });

  it('announces category, availability and MOQ together', () => {
    renderCardWithMeta(
      { ...base, availability: 'made_to_order', min_order_qty: 50 },
      true,
    );
    expect(
      screen.getByRole('link', { name: /drinkware.*made to order.*minimum 50 units/i }),
    ).toBeInTheDocument();
  });

  it('hides the visual badges from AT so nothing is announced twice', () => {
    renderCardWithMeta({ ...base, availability: 'out_of_stock', min_order_qty: 50 }, true);
    // The badge text is on screen twice over (badge + summary) but must reach
    // AT exactly once, via the text link.
    expect(screen.getAllByRole('link', { name: /drinkware/i })).toHaveLength(1);
    expect(screen.getAllByRole('link', { name: /out of stock/i })).toHaveLength(1);
    expect(screen.getAllByRole('link', { name: /minimum 50 units/i })).toHaveLength(1);
  });
});
