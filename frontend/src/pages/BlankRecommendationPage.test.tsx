import { expect, it, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { ThemeProvider } from '../ui';
import BlankRecommendationPage from './BlankRecommendationPage';
import * as recs from '../lib/recommendations';

beforeEach(() => {
  vi.restoreAllMocks();
  // The page loads the featured-management list on mount; default to empty.
  vi.spyOn(recs, 'listFeatured').mockResolvedValue([]);
});

function candidate(id: string, name: string): recs.Candidate {
  return {
    source_product_id: id, name, price: 9.9, currency: 'SGD',
    image_url: `https://cf.shopee.sg/${id}.jpg`, product_link: `https://shopee.sg/product/${id}`, offer_link: `https://s.shopee.sg/${id}`,
    sales: 300, rating_star: 4.9, shop_name: 'S2', commission_rate: 0.18, ip_flag: null, material_flag: null,
  };
}

function featuredItem(id: number, name: string): recs.FeaturedItem {
  return {
    id, source_product_id: `${id}_x`, name, image_url: null, price: 5, currency: 'SGD',
    shop_name: 'S', offer_link: `https://s.shopee.sg/${id}`, product_link: `https://shopee.sg/p/${id}`, ip_flagged: false,
  };
}

function renderPage() {
  render(<ThemeProvider><MemoryRouter><BlankRecommendationPage /></MemoryRouter></ThemeProvider>);
}

it('searches and renders ranked candidates, then shows the end label', async () => {
  vi.spyOn(recs, 'searchCandidates').mockResolvedValue({
    data: [candidate('3_4', 'Plain Ceramic Mug 440ml')], page: 1, has_more: false,
  });
  renderPage();

  await userEvent.type(screen.getByLabelText(/keyword/i), 'mug');
  await userEvent.click(screen.getByRole('button', { name: /^search$/i }));

  await waitFor(() => expect(screen.getByText('Plain Ceramic Mug 440ml')).toBeInTheDocument());
  expect(screen.getByText(/300 sold/i)).toBeInTheDocument();
  expect(screen.getByText(/18% comm/i)).toBeInTheDocument();
  expect(screen.getByText(/end of results/i)).toBeInTheDocument();
});

it('re-runs the search with the chosen sort', async () => {
  const spy = vi.spyOn(recs, 'searchCandidates').mockResolvedValue({
    data: [candidate('3_4', 'Sort Mug')], page: 1, has_more: false,
  });
  renderPage();

  await userEvent.type(screen.getByLabelText(/keyword/i), 'mug');
  await userEvent.click(screen.getByRole('button', { name: /^search$/i }));
  await waitFor(() => expect(screen.getByText('Sort Mug')).toBeInTheDocument());

  await userEvent.selectOptions(screen.getByLabelText(/sort by/i), 'commission');

  await waitFor(() => expect(spy).toHaveBeenLastCalledWith('mug', expect.any(Number), 1, 'commission'));
});

it('searches on Enter key', async () => {
  const spy = vi.spyOn(recs, 'searchCandidates').mockResolvedValue({
    data: [candidate('3_4', 'Enter Mug')], page: 1, has_more: false,
  });
  renderPage();

  await userEvent.type(screen.getByLabelText(/keyword/i), 'mug{Enter}');

  await waitFor(() => expect(screen.getByText('Enter Mug')).toBeInTheDocument());
  expect(spy).toHaveBeenCalledWith('mug', expect.any(Number), 1, 'sales');
});

it('opens a zoom modal when the card image is clicked', async () => {
  vi.spyOn(recs, 'searchCandidates').mockResolvedValue({
    data: [candidate('3_4', 'Zoom Mug')], page: 1, has_more: false,
  });
  renderPage();

  await userEvent.type(screen.getByLabelText(/keyword/i), 'mug');
  await userEvent.click(screen.getByRole('button', { name: /^search$/i }));
  await waitFor(() => expect(screen.getByText('Zoom Mug')).toBeInTheDocument());

  await userEvent.click(screen.getByRole('button', { name: /zoom image of zoom mug/i }));

  const dialog = await screen.findByRole('dialog');
  expect(dialog).toBeInTheDocument();
  expect(screen.getByRole('img', { name: 'Zoom Mug' })).toBeInTheDocument();
});

it('opens a help modal from the title icon explaining the actions', async () => {
  renderPage();

  await userEvent.click(screen.getByRole('button', { name: /what do these actions do/i }));

  const dialog = await screen.findByRole('dialog');
  expect(dialog).toHaveTextContent(/import into your catalogue/i);
  expect(dialog).toHaveTextContent(/affiliate link/i);
});

it('renders a Shopee link to the plain product listing on each card', async () => {
  vi.spyOn(recs, 'searchCandidates').mockResolvedValue({
    data: [candidate('3_4', 'Shopee Mug')], page: 1, has_more: false,
  });
  renderPage();

  await userEvent.type(screen.getByLabelText(/keyword/i), 'mug');
  await userEvent.click(screen.getByRole('button', { name: /^search$/i }));
  await waitFor(() => expect(screen.getByText('Shopee Mug')).toBeInTheDocument());

  const link = screen.getByRole('link', { name: /view on shopee/i });
  expect(link).toHaveAttribute('href', 'https://shopee.sg/product/3_4'); // plain product_link, not offer_link
});

it('lists featured items and removes one', async () => {
  vi.spyOn(recs, 'listFeatured').mockResolvedValue([featuredItem(7, 'Featured Mug')]);
  const del = vi.spyOn(recs, 'unfeature').mockResolvedValue();
  renderPage();

  await waitFor(() => expect(screen.getByText('Featured Mug')).toBeInTheDocument());
  expect(screen.getByText(/featured on gift-ideas \(1\)/i)).toBeInTheDocument();

  await userEvent.click(screen.getByRole('button', { name: /remove/i }));

  expect(del).toHaveBeenCalledWith(7);
  await waitFor(() => expect(screen.queryByText('Featured Mug')).not.toBeInTheDocument());
});

it('loads the next page and appends results', async () => {
  const spy = vi.spyOn(recs, 'searchCandidates')
    .mockResolvedValueOnce({ data: [candidate('1_1', 'Mug One')], page: 1, has_more: true })
    .mockResolvedValueOnce({ data: [candidate('2_2', 'Mug Two')], page: 2, has_more: false });
  renderPage();

  await userEvent.type(screen.getByLabelText(/keyword/i), 'mug');
  await userEvent.click(screen.getByRole('button', { name: /^search$/i }));
  await waitFor(() => expect(screen.getByText('Mug One')).toBeInTheDocument());

  await userEvent.click(screen.getByRole('button', { name: /load more/i }));

  await waitFor(() => expect(screen.getByText('Mug Two')).toBeInTheDocument());
  expect(screen.getByText('Mug One')).toBeInTheDocument(); // kept, not replaced
  expect(screen.getByText(/end of results/i)).toBeInTheDocument();
  expect(spy).toHaveBeenLastCalledWith('mug', expect.any(Number), 2, 'sales');
});
