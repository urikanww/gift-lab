import { expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { ThemeProvider } from '../ui';
import HomePage from './HomePage';
import * as catalogue from '../lib/catalogue';

vi.spyOn(catalogue, 'fetchCatalogue').mockResolvedValue({
  data: [{ id: 5, name: 'A5 Notebook', class: 'CORE', from_price: 7.58, currency: 'SGD', is_printable: true } as any],
  meta: { current_page: 1, last_page: 1, total: 1 },
} as any);

it('renders hero, categories, and popular products', async () => {
  render(<ThemeProvider><MemoryRouter><HomePage /></MemoryRouter></ThemeProvider>);
  expect(screen.getByRole('heading', { level: 1 })).toBeInTheDocument();
  expect(screen.getByText(/shop by category/i)).toBeInTheDocument();
  await waitFor(() => expect(screen.getByText(/A5 Notebook/)).toBeInTheDocument());
});
