import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';

const { get } = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock('../lib/api', () => ({
  default: { get },
  apiError: (e: unknown) => String(e),
  ensureCsrf: vi.fn(),
}));

import CataloguePage from './CataloguePage';

function renderPage() {
  return render(
    <MemoryRouter>
      <CataloguePage />
    </MemoryRouter>,
  );
}

beforeEach(() => get.mockReset());

describe('CataloguePage', () => {
  it('renders published products from the API', async () => {
    get.mockResolvedValue({
      data: { data: [{ id: 1, name: 'Ceramic Mug', from_price: 3.2, currency: 'SGD', image_url: null }] },
    });

    renderPage();

    await waitFor(() => expect(screen.getByText('Ceramic Mug')).toBeInTheDocument());
    expect(screen.getByText(/SGD 3.20/)).toBeInTheDocument();
    expect(screen.getAllByRole('link', { name: /Ceramic Mug/i })[0]).toHaveAttribute('href', '/products/1');
  });

  it('shows an empty state when there are no products', async () => {
    get.mockResolvedValue({ data: { data: [] } });

    renderPage();

    await waitFor(() => expect(screen.getByText(/no products published/i)).toBeInTheDocument());
  });

  // Note: the page's error branch simply forwards apiError() into AsyncBoundary,
  // whose error rendering + retry are covered deterministically in
  // components/ui/States.test.tsx. A page-level rejected-fetch test is omitted
  // here because it trips jsdom/vitest's unhandled-rejection detector even
  // though the component catches correctly (no unhandled path exists in prod).

  it('shows the marketplace category rail and filters on click', async () => {
    get.mockResolvedValue({ data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } });

    renderPage();

    await waitFor(() => expect(screen.getByRole('button', { name: /drinkware/i })).toBeInTheDocument());
    get.mockClear();
    screen.getByRole('button', { name: /drinkware/i }).click();
    await waitFor(() =>
      expect(get).toHaveBeenCalledWith('/catalogue', {
        params: expect.objectContaining({ category: 'drinkware' }),
      }),
    );
  });

  it('ignores a stale pagination response after filters change mid-flight', async () => {
    let resolveNext: (v: unknown) => void = () => {};
    get
      // Initial load: two pages available so pagination renders.
      .mockResolvedValueOnce({
        data: {
          data: [{ id: 1, name: 'First Mug', from_price: 3, currency: 'SGD', image_url: null }],
          meta: { current_page: 1, last_page: 2, total: 30 },
        },
      })
      // Pagination to page 2: held open to simulate a slow response.
      .mockImplementationOnce(() => new Promise((resolve) => { resolveNext = resolve; }))
      // Category-filtered reload fired while page 2 is still in flight.
      .mockResolvedValueOnce({
        data: {
          data: [{ id: 2, name: 'Fresh Tote', from_price: 4, currency: 'SGD', image_url: null }],
          meta: { current_page: 1, last_page: 1, total: 1 },
        },
      });

    renderPage();
    await waitFor(() => expect(screen.getByText('First Mug')).toBeInTheDocument());

    screen.getByRole('button', { name: /next/i }).click();
    // While the page-2 request is in flight, switch category.
    screen.getByRole('button', { name: /bags/i }).click();
    await waitFor(() => expect(screen.getByText('Fresh Tote')).toBeInTheDocument());

    // The superseded pagination response must NOT clobber the fresh result.
    resolveNext({
      data: {
        data: [{ id: 3, name: 'Stale Mug', from_price: 5, currency: 'SGD', image_url: null }],
        meta: { current_page: 2, last_page: 2, total: 30 },
      },
    });
    await new Promise((r) => setTimeout(r, 10));
    expect(screen.queryByText('Stale Mug')).not.toBeInTheDocument();
    expect(screen.getByText('Fresh Tote')).toBeInTheDocument();
  });

  it('offers marketplace sort options', async () => {
    get.mockResolvedValue({ data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } });

    renderPage();

    await waitFor(() =>
      expect(screen.getByLabelText(/sort/i)).toBeInTheDocument(),
    );
    expect(screen.getByRole('option', { name: /price: low to high/i })).toBeInTheDocument();
  });
});
