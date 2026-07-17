import { expect, it, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { AxiosError, AxiosHeaders } from 'axios';
import { ThemeProvider } from '../../ui';
import ResolveBlockersModal from './ResolveBlockersModal';
import { useCatalogueAdminStore } from '../../stores/catalogueAdminStore';
import type { AdminCatalogueItem } from '../../types';

beforeEach(() => vi.restoreAllMocks());

function item(overrides: Partial<AdminCatalogueItem> = {}): AdminCatalogueItem {
  return {
    id: 7,
    name: 'Ceramic Mug',
    class: 'SCRAPED_UV',
    publish_state: 'CANNOT_PUBLISH',
    cannot_publish_reasons: ['missing_dimensions'],
    base_cost: '12.00',
    currency: 'SGD',
    creator_credit: null,
    image_url: null,
    source_url: null,
    source_kind: null,
    filament_material: null,
    filament_color: null,
    est_grams: null,
    estimates_verified: false,
    model_file_ref: null,
    weight: null,
    dimensions: null,
    print_method: null,
    is_printable: false,
    ...overrides,
  };
}

function renderModal(product: AdminCatalogueItem, onResolved = vi.fn()) {
  render(
    <ThemeProvider>
      <ResolveBlockersModal product={product} open onClose={vi.fn()} onResolved={onResolved} />
    </ThemeProvider>,
  );
}

function mockResolve(result: { published: boolean; cannot_publish_reasons: string[] | null }) {
  const fn = vi.fn().mockResolvedValue({ publish_state: 'PUBLISHED', ...result });
  useCatalogueAdminStore.setState({ resolveBlockers: fn });
  return fn;
}

it('shows only the fields the row is actually blocked on', () => {
  renderModal(item({ cannot_publish_reasons: ['missing_dimensions'] }));

  expect(screen.getByLabelText(/length/i)).toBeInTheDocument();
  expect(screen.getByLabelText(/weight/i)).toBeInTheDocument();
  // Not blocked on price or print method → those fields stay out of the popup.
  expect(screen.queryByLabelText(/base cost/i)).not.toBeInTheDocument();
  expect(screen.queryByLabelText(/print method/i)).not.toBeInTheDocument();
});

it('shows every group when the row is blocked on all three', () => {
  renderModal(item({ cannot_publish_reasons: ['missing_dimensions', 'not_printable', 'missing_price'] }));

  expect(screen.getByLabelText(/length/i)).toBeInTheDocument();
  expect(screen.getByLabelText(/base cost/i)).toBeInTheDocument();
  expect(screen.getByLabelText(/print method/i)).toBeInTheDocument();
});

it('blocks submit and flags the field when a value is not positive', async () => {
  const fn = mockResolve({ published: true, cannot_publish_reasons: null });
  renderModal(item({ cannot_publish_reasons: ['missing_dimensions'] }));

  await userEvent.type(screen.getByLabelText(/length/i), '0');
  await userEvent.click(screen.getByRole('button', { name: /save and publish/i }));

  // Assert the offending field by name: a bare findByRole('alert') is ambiguous
  // here, since the untouched width/height/weight are flagged "Required." too.
  expect(await screen.findByText(/between 1 and 2000 mm/i)).toBeInTheDocument();
  expect(screen.getByLabelText(/length/i)).toHaveAttribute('aria-invalid', 'true');
  expect(fn).not.toHaveBeenCalled(); // never left the browser
});

it('sends the typed values and reports a publish', async () => {
  const fn = mockResolve({ published: true, cannot_publish_reasons: null });
  const onResolved = vi.fn();
  renderModal(item({ cannot_publish_reasons: ['missing_dimensions'] }), onResolved);

  await userEvent.type(screen.getByLabelText(/length/i), '100');
  await userEvent.type(screen.getByLabelText(/width/i), '80');
  await userEvent.type(screen.getByLabelText(/height/i), '60');
  await userEvent.type(screen.getByLabelText(/weight/i), '250');
  await userEvent.click(screen.getByRole('button', { name: /save and publish/i }));

  await waitFor(() =>
    expect(fn).toHaveBeenCalledWith(7, {
      dimensions: { l: 100, w: 80, h: 60 },
      weight: 250,
    }),
  );
  await waitFor(() => expect(onResolved).toHaveBeenCalledWith(true));
});

it('stays open and names what is left when the row is still blocked', async () => {
  mockResolve({ published: false, cannot_publish_reasons: ['stock_unreadable'] });
  renderModal(item({ cannot_publish_reasons: ['missing_dimensions'] }));

  await userEvent.type(screen.getByLabelText(/length/i), '100');
  await userEvent.type(screen.getByLabelText(/width/i), '80');
  await userEvent.type(screen.getByLabelText(/height/i), '60');
  await userEvent.type(screen.getByLabelText(/weight/i), '250');
  await userEvent.click(screen.getByRole('button', { name: /save and publish/i }));

  expect(await screen.findByText(/saved, but still blocked/i)).toBeInTheDocument();
  expect(screen.getByText(/stock level unreadable/i)).toBeInTheDocument();
});

it('maps a 422 onto the field it names', async () => {
  const err = new AxiosError('422');
  err.response = {
    data: { errors: { weight: ['The weight must not be greater than 100000.'] } },
    status: 422,
    statusText: 'Unprocessable Content',
    headers: new AxiosHeaders(),
    config: { headers: new AxiosHeaders() },
  };
  useCatalogueAdminStore.setState({ resolveBlockers: vi.fn().mockRejectedValue(err) });
  renderModal(item({ cannot_publish_reasons: ['missing_dimensions'] }));

  await userEvent.type(screen.getByLabelText(/length/i), '100');
  await userEvent.type(screen.getByLabelText(/width/i), '80');
  await userEvent.type(screen.getByLabelText(/height/i), '60');
  await userEvent.type(screen.getByLabelText(/weight/i), '250');
  await userEvent.click(screen.getByRole('button', { name: /save and publish/i }));

  expect(await screen.findByText(/must not be greater than 100000/i)).toBeInTheDocument();
  // Modal stays open - the typed work is still there.
  expect(screen.getByLabelText(/length/i)).toHaveValue('100');
});
