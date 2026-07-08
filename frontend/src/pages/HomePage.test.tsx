import { expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { ThemeProvider } from '../ui';
import HomePage from './HomePage';
import * as catalogue from '../lib/catalogue';

vi.spyOn(catalogue, 'fetchCatalogue').mockResolvedValue({
  data: [
    {
      id: 5,
      name: 'A5 Notebook',
      class: 'CORE',
      category: 'stationery',
      from_price: 7.58,
      currency: 'SGD',
      is_printable: true,
    } as any,
  ],
  meta: { current_page: 1, last_page: 1, total: 1 },
} as any);

it('renders search hero, category tiles, new arrivals and popular rails - no explainer sections', async () => {
  render(
    <ThemeProvider>
      <MemoryRouter>
        <HomePage />
      </MemoryRouter>
    </ThemeProvider>,
  );

  expect(screen.getByRole('heading', { level: 1 })).toBeInTheDocument();
  expect(screen.getByRole('search')).toBeInTheDocument();
  expect(screen.getByText(/shop by category/i)).toBeInTheDocument();
  // Drinkware appears twice (hero quick-link + category tile) - assert all point at the category URL.
  const drinkwareLinks = screen.getAllByRole('link', { name: /drinkware/i });
  expect(drinkwareLinks.length).toBeGreaterThanOrEqual(2);
  drinkwareLinks.forEach((l) => expect(l).toHaveAttribute('href', '/products?category=drinkware'));
  expect(screen.getByText(/new arrivals/i)).toBeInTheDocument();
  // The product appears in both rails.
  await waitFor(() => expect(screen.getAllByText(/A5 Notebook/).length).toBeGreaterThanOrEqual(2));
  // Marketplace, not a pitch page: explainers must be gone.
  expect(screen.queryByText(/how it works/i)).not.toBeInTheDocument();
  expect(screen.queryByText(/3-day turnaround/i)).not.toBeInTheDocument();
});
