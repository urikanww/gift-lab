import { expect, it, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { ThemeProvider } from '../ui';
import BlankRecommendationPage from './BlankRecommendationPage';
import * as recs from '../lib/recommendations';

beforeEach(() => vi.restoreAllMocks());

const candidate: recs.Candidate = {
  source_product_id: '3_4', name: 'Plain Ceramic Mug 440ml', price: 9.9, currency: 'SGD',
  image_url: null, product_link: 'https://shopee.sg/product/3/4', offer_link: 'https://s.shopee.sg/bb',
  sales: 300, rating_star: 4.9, shop_name: 'S2', ip_flag: null, material_flag: null,
};

it('searches and renders ranked candidates', async () => {
  vi.spyOn(recs, 'searchCandidates').mockResolvedValue([candidate]);
  render(<ThemeProvider><MemoryRouter><BlankRecommendationPage /></MemoryRouter></ThemeProvider>);

  await userEvent.type(screen.getByLabelText(/keyword/i), 'mug');
  await userEvent.click(screen.getByRole('button', { name: /search/i }));

  await waitFor(() => expect(screen.getByText('Plain Ceramic Mug 440ml')).toBeInTheDocument());
  expect(screen.getByText(/300 sold/i)).toBeInTheDocument();
});
