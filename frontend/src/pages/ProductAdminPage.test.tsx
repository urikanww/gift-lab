import { beforeEach, expect, it, vi } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { ThemeProvider } from '../ui';
import type { AdminProduct } from '../types';

// api is the default import in the page; mock get/post so no real HTTP fires.
const getMock = vi.fn();
const postMock = vi.fn();
vi.mock('../lib/api', () => ({
  default: {
    get: (...args: unknown[]) => getMock(...args),
    post: (...args: unknown[]) => postMock(...args),
  },
  apiError: (e: unknown) => (e instanceof Error ? e.message : 'error'),
}));

// Staff (non-superadmin) so the CSV import + licence surfaces stay hidden and
// the bulk-archive controls are the only thing under test.
vi.mock('../stores/authStore', () => ({
  useAuthStore: (sel: (s: { user: { role: string } }) => unknown) => sel({ user: { role: 'staff_admin' } }),
}));

import ProductAdminPage from './ProductAdminPage';

function product(id: number, name: string): AdminProduct {
  return {
    id, name, description: null, class: 'CORE', base_cost: '5.00', price_override: null,
    selling_price: '10.00', currency: 'SGD', dimensions: null, weight: null, print_method: null,
    stock_mode: null, allow_backorder: false, category: null, image_url: null, is_printable: false,
    publish_state: 'PUBLISHED', orderable: true, license_tier: 'standard', archived: false, variants: null,
    sold_count: 0, stock_total: 0,
  };
}

function listResponse() {
  return {
    data: {
      data: [product(1, 'Alpha Mug'), product(2, 'Beta Tee')],
      meta: { current_page: 1, last_page: 1, per_page: 15, total: 2 },
    },
  };
}

beforeEach(() => {
  getMock.mockReset();
  postMock.mockReset();
  getMock.mockImplementation((url: string) => {
    if (url === '/admin/products') return Promise.resolve(listResponse());
    if (url === '/admin/catalogue') return Promise.resolve({ data: { meta: { total: 0 } } });
    return Promise.resolve({ data: {} });
  });
});

function renderPage() {
  render(
    <ThemeProvider>
      <MemoryRouter initialEntries={['/product-admin']}>
        <ProductAdminPage />
      </MemoryRouter>
    </ThemeProvider>,
  );
}

it('archives selected products and reflects the outcome', async () => {
  postMock.mockResolvedValue({ data: { data: [], meta: { deleted: 1, failed: 0 } } });
  renderPage();

  await waitFor(() => expect(screen.getByText('Alpha Mug')).toBeInTheDocument());

  // No bulk action until something is picked.
  expect(screen.queryByRole('button', { name: /archive selected/i })).not.toBeInTheDocument();

  await userEvent.click(screen.getByLabelText('Select Alpha Mug'));
  await userEvent.click(screen.getByRole('button', { name: /archive selected \(1\)/i }));

  // Confirm dialog, then the destructive action.
  const dialog = await screen.findByRole('dialog');
  await userEvent.click(within(dialog).getByRole('button', { name: /^archive 1$/i }));

  await waitFor(() =>
    expect(postMock).toHaveBeenCalledWith('/admin/products/bulk-delete', { ids: [1] }),
  );
  // Refetches the list after a successful archive.
  await waitFor(() => expect(getMock).toHaveBeenCalledTimes(3)); // 2 on mount + 1 refetch
  expect(await screen.findByText(/archived 1 product\./i)).toBeInTheDocument();
});

it('select-all picks every row on the page', async () => {
  renderPage();
  await waitFor(() => expect(screen.getByText('Alpha Mug')).toBeInTheDocument());

  await userEvent.click(screen.getByLabelText(/select all products on this page/i));

  expect(screen.getByRole('button', { name: /archive selected \(2\)/i })).toBeInTheDocument();
});

it('surfaces a partial-failure notice from the batch meta', async () => {
  postMock.mockResolvedValue({ data: { data: [], meta: { deleted: 1, failed: 1 } } });
  renderPage();
  await waitFor(() => expect(screen.getByText('Alpha Mug')).toBeInTheDocument());

  await userEvent.click(screen.getByLabelText('Select Alpha Mug'));
  await userEvent.click(screen.getByLabelText('Select Beta Tee'));
  await userEvent.click(screen.getByRole('button', { name: /archive selected \(2\)/i }));
  const dialog = await screen.findByRole('dialog');
  await userEvent.click(within(dialog).getByRole('button', { name: /^archive 2$/i }));

  expect(await screen.findByText(/archived 1, 1 skipped\./i)).toBeInTheDocument();
});
