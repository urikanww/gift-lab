import { expect, it, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import { ThemeProvider } from '../ui';
import ProductDetailPage from './ProductDetailPage';
import * as catalogue from '../lib/catalogue';

const PRODUCT = {
  id: 5, name: 'A5 Hardcover Notebook', description: 'Blank core', class: 'CORE',
  category: 'stationery',
  from_price: 7.58, currency: 'SGD', dimensions: { l: 148, w: 15, h: 210, unit: 'mm' },
  weight: '300', print_method: 'UV', stock_mode: 'STOCKED', image_url: null,
  is_printable: true, creator_credit: null, variants: [], availability: 'in_stock',
};

/** Stub the PDP's data deps. `minOrderQty`/`bulk` drive the volume strip. */
function stubPdp(opts: { minOrderQty?: number; bulk?: catalogue.BulkPricing | null } = {}) {
  vi.spyOn(catalogue, 'fetchProduct').mockResolvedValue({
    ...PRODUCT,
    min_order_qty: opts.minOrderQty ?? 1,
  } as any);
  vi.spyOn(catalogue, 'fetchBulkPricing').mockResolvedValue(
    opts.bulk === undefined ? { bulkQty: 50, discountPct: 10 } : opts.bulk,
  );
  // Price each probed quantity, so the tiles reflect the derived quantities.
  vi.spyOn(catalogue, 'fetchTierPrices').mockImplementation(async (_p, _v, quantities) =>
    quantities.map((qty) => ({ qty, unitPrice: qty >= 50 ? 6.82 : 7.58, currency: 'SGD' })),
  );
  vi.spyOn(catalogue, 'fetchCatalogue').mockResolvedValue({
    data: [], meta: { current_page: 1, last_page: 1, total: 0 },
  } as any);
  vi.spyOn(catalogue, 'fetchRelated').mockResolvedValue([]);
}

function renderPdp() {
  render(
    <ThemeProvider><MemoryRouter initialEntries={['/products/5']}>
      <Routes><Route path="/products/:id" element={<ProductDetailPage />} /></Routes>
    </MemoryRouter></ThemeProvider>,
  );
  return waitFor(() =>
    expect(screen.getByRole('heading', { name: /A5 Hardcover Notebook/i })).toBeInTheDocument(),
  );
}

/** The volume-pricing tiles - each is a "<n> pcs" preset button. */
const tileButtons = () => screen.queryAllByRole('button', { name: /pcs/i });

/** The "Volume pricing" strip heading - absent when there's no real break. */
const strip = () => screen.queryByText(/volume pricing/i);

beforeEach(() => vi.restoreAllMocks());

it('renders product name, price, and a Customize CTA linking to the designer', async () => {
  stubPdp();
  await renderPdp();
  // Desktop CTA ("Customize in studio") + mobile sticky bar ("Customize") both link to the designer.
  const ctas = screen.getAllByRole('link', { name: /customize/i });
  expect(ctas.length).toBeGreaterThan(0);
  ctas.forEach((cta) => expect(cta).toHaveAttribute('href', '/design/5'));
});

it('uses the marketplace category for breadcrumb, not the print class', async () => {
  stubPdp();
  await renderPdp();

  const crumb = screen.getByRole('navigation', { name: /breadcrumb/i });
  expect(crumb).toHaveTextContent('Stationery & Office');
  const catLink = screen.getByRole('link', { name: /stationery & office/i });
  expect(catLink).toHaveAttribute('href', '/products?category=stationery');
});

it('shows the strip with two tiers - the MOQ and the bulk threshold - when the MOQ is below the threshold', async () => {
  stubPdp({ minOrderQty: 25, bulk: { bulkQty: 50, discountPct: 10 } });
  await renderPdp();

  await waitFor(() => expect(tileButtons()).toHaveLength(2));
  expect(strip()).toBeInTheDocument();
  expect(tileButtons()[0]).toHaveTextContent('25 pcs');
  expect(tileButtons()[0]).toHaveTextContent('7.58');
  expect(tileButtons()[1]).toHaveTextContent('50 pcs');
  expect(tileButtons()[1]).toHaveTextContent('6.82');
});

it('never advertises a quantity below the MOQ', async () => {
  // The old strip hardcoded a 25 tile: with MOQ 100 it priced 25 units, but
  // clicking it set qty to 100 - a price the buyer could never get.
  stubPdp({ minOrderQty: 100, bulk: { bulkQty: 500, discountPct: 10 } });
  await renderPdp();

  await waitFor(() => expect(tileButtons()).toHaveLength(2));
  expect(tileButtons()[0]).toHaveTextContent('100 pcs');
  expect(screen.queryByText(/25 pcs/)).not.toBeInTheDocument();
});

it('renders no strip but keeps the offer footnote when the MOQ already clears the threshold', async () => {
  stubPdp({ minOrderQty: 100, bulk: { bulkQty: 50, discountPct: 10 } });
  await renderPdp();

  // A lone tile is a broken ladder - the live price line carries the single
  // price. But the offer is real and always applies to this buyer, so the
  // footnote must say so, standing alone with no strip above it.
  await waitFor(() => expect(screen.getByText(/bulk pricing is already applied/i)).toBeInTheDocument());
  expect(strip()).not.toBeInTheDocument();
  expect(tileButtons()).toHaveLength(0);
  expect(screen.queryByText(/10% off at/i)).not.toBeInTheDocument();
});

it('renders no strip and claims no discount when there is no bulk offer', async () => {
  stubPdp({ minOrderQty: 10, bulk: { bulkQty: null, discountPct: 0 } });
  await renderPdp();

  await waitFor(() =>
    expect(screen.getByRole('heading', { name: /A5 Hardcover Notebook/i })).toBeInTheDocument(),
  );
  expect(strip()).not.toBeInTheDocument();
  expect(tileButtons()).toHaveLength(0);
  expect(screen.queryByText(/% off/i)).not.toBeInTheDocument();
  expect(screen.queryByText(/volume discounts apply/i)).not.toBeInTheDocument();
});

it('states the real discount and threshold in the footnote', async () => {
  stubPdp({ minOrderQty: 1, bulk: { bulkQty: 250, discountPct: 7.5 } });
  await renderPdp();

  // Real numbers, no float artifacts, and no invented ladder.
  await waitFor(() => expect(screen.getByText(/7\.5% off at 250\+ units\./i)).toBeInTheDocument());
});

it('renders no strip and claims no discount when the bulk config cannot be fetched', async () => {
  stubPdp({ minOrderQty: 25, bulk: null });
  await renderPdp();

  await waitFor(() =>
    expect(screen.getByRole('heading', { name: /A5 Hardcover Notebook/i })).toBeInTheDocument(),
  );
  // Unknown offer: no strip, and nothing claimed about discounts.
  expect(strip()).not.toBeInTheDocument();
  expect(tileButtons()).toHaveLength(0);
  expect(screen.queryByText(/% off/i)).not.toBeInTheDocument();
  expect(screen.queryByText(/discount/i)).not.toBeInTheDocument();
});

it('probes the derived quantities in a single batched request', async () => {
  stubPdp({ minOrderQty: 25, bulk: { bulkQty: 50, discountPct: 10 } });
  await renderPdp();

  await waitFor(() => expect(tileButtons()).toHaveLength(2));
  // The strip is one call for all tiers (plus the separate live-price probe),
  // not one call per tile.
  const stripCalls = (catalogue.fetchTierPrices as any).mock.calls.filter(
    (c: any[]) => c[2].length > 1,
  );
  expect(stripCalls).toHaveLength(1);
  expect(stripCalls[0][2]).toEqual([25, 50]);
});
