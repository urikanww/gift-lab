import { expect, it, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import PromoTiles from './PromoTiles';
import * as catalogue from '../../lib/catalogue';

const renderTiles = () =>
  render(
    <MemoryRouter>
      <PromoTiles />
    </MemoryRouter>,
  );

beforeEach(() => vi.restoreAllMocks());

it('links to the kit builder and the catalogue', async () => {
  vi.spyOn(catalogue, 'fetchBulkPricing').mockResolvedValue(null);
  renderTiles();

  expect(screen.getByRole('link', { name: /build a kit/i })).toHaveAttribute('href', '/kits');
  expect(screen.getByRole('link', { name: /bulk pricing/i })).toHaveAttribute('href', '/products');
});

it('states the real offer once the config is known', async () => {
  vi.spyOn(catalogue, 'fetchBulkPricing').mockResolvedValue({ bulkQty: 50, discountPct: 10 });
  renderTiles();

  await waitFor(() =>
    expect(screen.getByText(/10% off at 50\+ units\./i)).toBeInTheDocument(),
  );
});

it('renders a fractional discount without float artifacts', async () => {
  vi.spyOn(catalogue, 'fetchBulkPricing').mockResolvedValue({ bulkQty: 250, discountPct: 7.5 });
  renderTiles();

  await waitFor(() => expect(screen.getByText(/7\.5% off at 250\+ units\./i)).toBeInTheDocument());
});

it('falls back to numberless copy when the fetch fails', async () => {
  vi.spyOn(catalogue, 'fetchBulkPricing').mockResolvedValue(null);
  renderTiles();

  expect(screen.getByText(/unit price drops on larger orders/i)).toBeInTheDocument();
  // Never claim a discount we could not confirm.
  await waitFor(() => expect(screen.queryByText(/% off/i)).not.toBeInTheDocument());
});

it('falls back to numberless copy when there is no bulk offer', async () => {
  vi.spyOn(catalogue, 'fetchBulkPricing').mockResolvedValue({ bulkQty: null, discountPct: 0 });
  renderTiles();

  await waitFor(() =>
    expect(screen.getByText(/unit price drops on larger orders/i)).toBeInTheDocument(),
  );
  expect(screen.queryByText(/% off/i)).not.toBeInTheDocument();
});
