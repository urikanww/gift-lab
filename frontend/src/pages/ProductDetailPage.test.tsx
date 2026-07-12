import { expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import { ThemeProvider } from '../ui';
import ProductDetailPage from './ProductDetailPage';
import * as catalogue from '../lib/catalogue';

vi.spyOn(catalogue, 'fetchProduct').mockResolvedValue({
  id: 5, name: 'A5 Hardcover Notebook', description: 'Blank core', class: 'CORE',
  category: 'stationery',
  from_price: 7.58, currency: 'SGD', dimensions: { l: 148, w: 15, h: 210, unit: 'mm' },
  weight: '300', print_method: 'UV', stock_mode: 'STOCKED', image_url: null,
  is_printable: true, creator_credit: null, variants: [], availability: 'in_stock',
} as any);
vi.spyOn(catalogue, 'fetchTierPrices').mockResolvedValue([{ qty: 25, unitPrice: 7.58, currency: 'SGD' }]);
vi.spyOn(catalogue, 'fetchCatalogue').mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1, total: 0 } } as any);

it('renders product name, price, and a Customize CTA linking to the designer', async () => {
  render(
    <ThemeProvider><MemoryRouter initialEntries={['/products/5']}>
      <Routes><Route path="/products/:id" element={<ProductDetailPage />} /></Routes>
    </MemoryRouter></ThemeProvider>,
  );
  await waitFor(() => expect(screen.getByRole('heading', { name: /A5 Hardcover Notebook/i })).toBeInTheDocument());
  // Desktop CTA ("Customize in studio") + mobile sticky bar ("Customize") both link to the designer.
  const ctas = screen.getAllByRole('link', { name: /customize/i });
  expect(ctas.length).toBeGreaterThan(0);
  ctas.forEach((cta) => expect(cta).toHaveAttribute('href', '/design/5'));
});

it('uses the marketplace category for breadcrumb, not the print class', async () => {
  render(
    <ThemeProvider>
      <MemoryRouter initialEntries={['/products/5']}>
        <Routes>
          <Route path="/products/:id" element={<ProductDetailPage />} />
        </Routes>
      </MemoryRouter>
    </ThemeProvider>,
  );
  await waitFor(() =>
    expect(screen.getByRole('heading', { name: /A5 Hardcover Notebook/i })).toBeInTheDocument(),
  );

  const crumb = screen.getByRole('navigation', { name: /breadcrumb/i });
  expect(crumb).toHaveTextContent('Stationery & Office');
  const catLink = screen.getByRole('link', { name: /stationery & office/i });
  expect(catLink).toHaveAttribute('href', '/products?category=stationery');
});
