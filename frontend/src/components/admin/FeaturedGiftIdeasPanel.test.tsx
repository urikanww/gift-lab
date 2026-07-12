import { expect, it, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ThemeProvider } from '../../ui';
import FeaturedGiftIdeasPanel from './FeaturedGiftIdeasPanel';
import * as recs from '../../lib/recommendations';

beforeEach(() => vi.restoreAllMocks());

function featuredItem(id: number, name: string): recs.FeaturedItem {
  return {
    id, source_product_id: `${id}_x`, name, image_url: null, price: 5, currency: 'SGD',
    shop_name: 'S', offer_link: `https://s.shopee.sg/${id}`, product_link: `https://shopee.sg/p/${id}`, ip_flagged: false,
  };
}

function renderPanel() {
  render(<ThemeProvider><FeaturedGiftIdeasPanel /></ThemeProvider>);
}

it('lists featured items with a count and links to the plain Shopee listing', async () => {
  vi.spyOn(recs, 'listFeatured').mockResolvedValue([featuredItem(7, 'Featured Mug')]);
  renderPanel();

  await waitFor(() => expect(screen.getByText('Featured Mug')).toBeInTheDocument());
  expect(screen.getByText(/featured on gift-ideas \(1\)/i)).toBeInTheDocument();

  const link = screen.getByRole('link', { name: /featured mug/i });
  expect(link).toHaveAttribute('href', 'https://s.shopee.sg/7'); // affiliate offer_link (preview)
  expect(link).toHaveAttribute('rel', expect.stringContaining('sponsored'));
  expect(link).toHaveAttribute('target', '_blank');
});

it('shows an empty state when nothing is featured', async () => {
  vi.spyOn(recs, 'listFeatured').mockResolvedValue([]);
  renderPanel();

  await waitFor(() => expect(screen.getByText(/nothing featured yet/i)).toBeInTheDocument());
  expect(screen.getByText(/featured on gift-ideas \(0\)/i)).toBeInTheDocument();
});

it('removes a featured item', async () => {
  vi.spyOn(recs, 'listFeatured').mockResolvedValue([featuredItem(7, 'Featured Mug')]);
  const del = vi.spyOn(recs, 'unfeature').mockResolvedValue();
  renderPanel();

  await waitFor(() => expect(screen.getByText('Featured Mug')).toBeInTheDocument());
  await userEvent.click(screen.getByRole('button', { name: /remove/i }));

  expect(del).toHaveBeenCalledWith(7);
  await waitFor(() => expect(screen.queryByText('Featured Mug')).not.toBeInTheDocument());
});
