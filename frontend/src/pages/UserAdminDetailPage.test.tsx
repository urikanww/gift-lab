import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';

const get = vi.fn();
const patch = vi.fn().mockResolvedValue({ data: {} });
vi.mock('../lib/api', () => ({
  default: { get: (...a: unknown[]) => get(...a), patch: (...a: unknown[]) => patch(...a), post: vi.fn(), delete: vi.fn() },
  apiError: (e: unknown) => String(e),
  ensureCsrf: vi.fn().mockResolvedValue(undefined),
}));

import { ThemeProvider, ToastProvider } from '../ui';
import UserAdminDetailPage from './UserAdminDetailPage';
import { useAuthStore } from '../stores/authStore';

const CATALOG = {
  quotes: { label: 'Quotes', actions: { view: 'View orders', edit: 'Create and edit orders' } },
  production: { label: 'Production', actions: { view: 'View the queue', manage: 'Advance jobs' } },
};

const staffUser = {
  id: 5,
  name: 'Dana Staff',
  email: 'dana@x.test',
  role: 'staff_admin',
  company: null,
  active: true,
  created_at: '2026-07-01T00:00:00Z',
  permissions: ['quotes.view'],
  permissions_editable: true,
};

beforeEach(() => {
  get.mockReset();
  patch.mockClear();
  get.mockImplementation((url: string) => {
    if (url === '/admin/users/5') return Promise.resolve({ data: { data: staffUser } });
    if (url === '/admin/companies') return Promise.resolve({ data: { data: [] } });
    if (url === '/admin/permissions/catalog') return Promise.resolve({ data: { data: CATALOG } });
    return Promise.resolve({ data: { data: {} } });
  });
  // A different superadmin is viewing (not self).
  useAuthStore.setState({ user: { id: 1, role: 'superadmin' } } as never);
});

afterEach(() => cleanup());

function renderPage() {
  return render(
    <ThemeProvider>
      <ToastProvider>
        <MemoryRouter initialEntries={['/user-admin/5']}>
          <Routes>
            <Route path="/user-admin/:id" element={<UserAdminDetailPage />} />
          </Routes>
        </MemoryRouter>
      </ToastProvider>
    </ThemeProvider>,
  );
}

it('renders the access table checked from the user’s current grants', async () => {
  renderPage();

  expect(await screen.findByRole('heading', { name: 'Access' })).toBeInTheDocument();
  expect(screen.getByText('Quotes')).toBeInTheDocument();
  expect(screen.getByText('Production')).toBeInTheDocument();

  // Current grant: quotes.view on, everything else off.
  expect(screen.getByLabelText('View orders')).toBeChecked();
  expect(screen.getByLabelText('Create and edit orders')).not.toBeChecked();
  expect(screen.getByLabelText('Advance jobs')).not.toBeChecked();
});

it('saves the toggled allowlist in catalogue order', async () => {
  renderPage();
  const user = userEvent.setup();

  await screen.findByRole('heading', { name: 'Access' });

  // Grant production.view and quotes.edit on top of the existing quotes.view.
  await user.click(screen.getByLabelText('View the queue'));
  await user.click(screen.getByLabelText('Create and edit orders'));
  await user.click(screen.getByRole('button', { name: 'Save access' }));

  await waitFor(() => expect(patch).toHaveBeenCalled());
  expect(patch).toHaveBeenCalledWith('/admin/users/5', {
    // Catalogue order: quotes.view, quotes.edit, then production.view.
    permissions: ['quotes.view', 'quotes.edit', 'production.view'],
  });
});

it('Clear all unchecks every box', async () => {
  renderPage();
  const user = userEvent.setup();

  await screen.findByRole('heading', { name: 'Access' });
  await user.click(screen.getByRole('button', { name: 'Clear all' }));

  expect(screen.getByLabelText('View orders')).not.toBeChecked();

  await user.click(screen.getByRole('button', { name: 'Save access' }));
  await waitFor(() => expect(patch).toHaveBeenCalledWith('/admin/users/5', { permissions: [] }));
});
