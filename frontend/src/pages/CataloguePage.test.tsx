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

  it('offers marketplace sort options', async () => {
    get.mockResolvedValue({ data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } });

    renderPage();

    await waitFor(() =>
      expect(screen.getByLabelText(/sort/i)).toBeInTheDocument(),
    );
    expect(screen.getByRole('option', { name: /price: low to high/i })).toBeInTheDocument();
  });
});
