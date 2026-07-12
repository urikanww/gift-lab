import { expect, it, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { ThemeProvider } from '../ui';
import GiftIdeasPage from './GiftIdeasPage';
import api from '../lib/api';

beforeEach(() => vi.restoreAllMocks());

it('renders featured products with affiliate links, disclosure and cross-sell', async () => {
  vi.spyOn(api, 'get').mockResolvedValue({ data: { data: [
    { name: 'Plain Mug', image_url: null, offer_link: 'https://s.shopee.sg/ok', price: 9.9, currency: 'SGD', shop_name: 'S2' },
  ] } } as any);

  render(<ThemeProvider><MemoryRouter><GiftIdeasPage /></MemoryRouter></ThemeProvider>);

  await waitFor(() => expect(screen.getByText('Plain Mug')).toBeInTheDocument());
  expect(screen.getByText(/affiliate links/i)).toBeInTheDocument();
  const buy = screen.getByRole('link', { name: /buy on shopee/i });
  expect(buy).toHaveAttribute('href', 'https://s.shopee.sg/ok');
  expect(buy).toHaveAttribute('rel', expect.stringContaining('sponsored'));
  expect(screen.getByRole('link', { name: /personalize with us/i })).toHaveAttribute('href', '/products');
});
