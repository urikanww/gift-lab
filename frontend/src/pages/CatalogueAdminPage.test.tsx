import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { ThemeProvider, ToastProvider } from '../ui';
import type { AdminCatalogueItem } from '../types';

const { get, post, del, patch } = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  del: vi.fn(),
  patch: vi.fn(),
}));
vi.mock('../lib/api', () => ({
  default: { get, post, delete: del, patch },
  apiError: (e: unknown) => String(e),
  ensureCsrf: vi.fn(),
}));

import CatalogueAdminPage from './CatalogueAdminPage';

function item(overrides: Partial<AdminCatalogueItem> = {}): AdminCatalogueItem {
  return {
    id: 1,
    name: 'Ready Mug',
    class: 'SCRAPED_UV',
    publish_state: 'READY_TO_APPROVE',
    cannot_publish_reasons: null,
    weight: null,
    dimensions: null,
    print_method: null,
    is_printable: false,
    base_cost: '4.00',
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
    ...overrides,
  };
}

const blocked = item({
  id: 2,
  name: 'Blocked Bottle',
  publish_state: 'CANNOT_PUBLISH',
  cannot_publish_reasons: ['stock_unreadable'],
});

function mockList(items: AdminCatalogueItem[]) {
  get.mockResolvedValue({
    data: {
      data: items,
      counts: { total: items.length, pending: 0, ready: 1, blocked: 1 },
      meta: { current_page: 1, last_page: 1, total: items.length },
    },
  });
}

function renderPage() {
  render(
    <ThemeProvider>
      <ToastProvider>
        <MemoryRouter>
          <CatalogueAdminPage />
        </MemoryRouter>
      </ToastProvider>
    </ThemeProvider>,
  );
}

beforeEach(() => {
  get.mockReset();
  post.mockReset();
  del.mockReset();
  patch.mockReset();
});

describe('CatalogueAdminPage — Published/Unpublish dropped (Task 5)', () => {
  it('offers no Published option in the state filter', async () => {
    mockList([item()]);
    renderPage();
    await waitFor(() => expect(screen.getByText('Ready Mug')).toBeInTheDocument());

    await userEvent.click(screen.getByRole('button', { name: /filters/i }));
    const stateSelect = screen.getByLabelText(/^state$/i);
    expect(
      within(stateSelect).queryByRole('option', { name: /^published$/i }),
    ).not.toBeInTheDocument();
    // sanity: the remaining states still render
    expect(within(stateSelect).getByRole('option', { name: /ready to approve/i })).toBeInTheDocument();
    expect(within(stateSelect).getByRole('option', { name: /cannot publish/i })).toBeInTheDocument();
  });

  it('renders no Unpublish control', async () => {
    mockList([item(), blocked]);
    renderPage();
    await waitFor(() => expect(screen.getByText('Ready Mug')).toBeInTheDocument());

    expect(screen.queryByRole('button', { name: /unpublish/i })).not.toBeInTheDocument();
  });
});
