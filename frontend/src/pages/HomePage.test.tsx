import { afterEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { ThemeProvider } from '../ui';
import HomePage from './HomePage';
import * as catalogue from '../lib/catalogue';
import * as quotes from '../lib/quotes';
import { useAuthStore } from '../stores/authStore';
import type { Product, Quote, User } from '../types';

const product = (id: number): Product =>
  ({
    id,
    name: `Product ${id}`,
    class: 'CORE',
    category: 'stationery',
    from_price: 7.58,
    currency: 'SGD',
    is_printable: true,
    availability: 'in_stock',
  }) as Product;

const page = (ids: number[], current = 1, last = 1) => ({
  data: ids.map(product),
  meta: { current_page: current, last_page: last, total: ids.length },
});

const testUser: User = {
  id: 1,
  company_id: null,
  name: 'Ada Buyer',
  email: 'ada@example.com',
  role: 'buyer',
};

const renderHome = () =>
  render(
    <ThemeProvider>
      <MemoryRouter>
        <HomePage />
      </MemoryRouter>
    </ThemeProvider>,
  );

// Replace-mode reset so no per-test user leaks - same idiom as SiteHeader.test.tsx.
const initialStore = useAuthStore.getState();
afterEach(() => {
  vi.restoreAllMocks();
  useAuthStore.setState(initialStore, true);
});

describe('HomePage', () => {
  it('roots the document outline with an h1, even though nothing here is a visible headline', async () => {
    vi.spyOn(catalogue, 'fetchCatalogue').mockResolvedValue(page([1]) as any);
    renderHome();

    await waitFor(() => expect(screen.getAllByText('Product 1').length).toBeGreaterThan(0));
    expect(screen.getByRole('heading', { level: 1 })).toBeInTheDocument();
  });

  it('keeps the reorder rail away from staff - they see every company\'s quotes', async () => {
    vi.spyOn(catalogue, 'fetchCatalogue').mockResolvedValue(page([1]) as any);
    const spy = vi.spyOn(quotes, 'fetchRecentQuotes').mockResolvedValue([
      { id: 7, currency: 'SGD', total: '250.00' } as Quote,
    ]);
    useAuthStore.setState({ user: { ...testUser, role: 'staff_admin' }, status: 'ready', error: null });
    renderHome();

    await waitFor(() => expect(screen.getAllByText('Product 1').length).toBeGreaterThan(0));
    expect(screen.queryByText(/reorder from a past quote/i)).not.toBeInTheDocument();
    expect(spy).not.toHaveBeenCalled();
  });

  it('has no search - the header owns the only one', async () => {
    vi.spyOn(catalogue, 'fetchCatalogue').mockResolvedValue(page([1]) as any);
    renderHome();

    await waitFor(() => expect(screen.getAllByText('Product 1').length).toBeGreaterThan(0));
    expect(screen.queryByRole('search')).not.toBeInTheDocument();
    expect(screen.queryByRole('searchbox')).not.toBeInTheDocument();
  });

  it('leaves category navigation to the header - the band was a third copy of the same 8 links', async () => {
    vi.spyOn(catalogue, 'fetchCatalogue').mockResolvedValue(page([1]) as any);
    renderHome();

    await waitFor(() => expect(screen.getAllByText('Product 1').length).toBeGreaterThan(0));
    expect(
      screen.queryByRole('navigation', { name: /shop by category/i }),
    ).not.toBeInTheDocument();
  });

  it('drops the Featured gifts section - there is no popularity signal', async () => {
    vi.spyOn(catalogue, 'fetchCatalogue').mockResolvedValue(page([1]) as any);
    renderHome();

    await waitFor(() => expect(screen.getAllByText('Product 1').length).toBeGreaterThan(0));
    expect(screen.queryByText(/featured gifts/i)).not.toBeInTheDocument();
  });

  it('hides the reorder rail when signed out and never asks for quotes', async () => {
    vi.spyOn(catalogue, 'fetchCatalogue').mockResolvedValue(page([1]) as any);
    const spy = vi.spyOn(quotes, 'fetchRecentQuotes').mockResolvedValue([]);
    renderHome();

    await waitFor(() => expect(screen.getAllByText('Product 1').length).toBeGreaterThan(0));
    expect(screen.queryByText(/reorder from a past quote/i)).not.toBeInTheDocument();
    expect(spy).not.toHaveBeenCalled();
  });

  it('shows the reorder rail when signed in with quotes', async () => {
    vi.spyOn(catalogue, 'fetchCatalogue').mockResolvedValue(page([1]) as any);
    vi.spyOn(quotes, 'fetchRecentQuotes').mockResolvedValue([
      { id: 7, currency: 'SGD', total: '250.00' } as Quote,
    ]);
    useAuthStore.setState({ user: testUser, status: 'ready', error: null });
    renderHome();

    await waitFor(() => expect(screen.getByText(/reorder from a past quote/i)).toBeInTheDocument());
  });

  it('appends the next page on Load more, then hides the button at the last page', async () => {
    // Keyed on the query, not swapped mid-test: page 2 only ever resolves for a
    // request that actually asked for page 2, so this fails if the page never advances.
    vi.spyOn(catalogue, 'fetchCatalogue').mockImplementation((q: catalogue.CatalogueQuery = {}) =>
      Promise.resolve(
        (q.sort === 'newest' ? page([9]) : page([q.page === 2 ? 2 : 1], q.page ?? 1, 2)) as any,
      ),
    );
    renderHome();

    await userEvent.click(await screen.findByRole('button', { name: /load more/i }));

    await waitFor(() => expect(screen.getByText('Product 2')).toBeInTheDocument());
    expect(screen.getByText('Product 1')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /load more/i })).not.toBeInTheDocument();
  });

  it('surfaces a retry when the catalogue fails', async () => {
    vi.spyOn(catalogue, 'fetchCatalogue').mockRejectedValue(new Error('down'));
    renderHome();

    await waitFor(() => expect(screen.getByText(/could not load products/i)).toBeInTheDocument());
  });
});
