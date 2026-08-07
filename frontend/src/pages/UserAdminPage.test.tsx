import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';

const get = vi.fn();
vi.mock('../lib/api', () => ({
  default: { get: (...a: unknown[]) => get(...a) },
  apiError: (e: unknown) => String(e),
}));

import { ThemeProvider } from '../ui';
import UserAdminPage from './UserAdminPage';

const USER = {
  id: 7,
  name: 'Dana Buyer',
  email: 'dana@acme.example',
  role: 'buyer',
  company: { id: 3, name: 'Acme Pte Ltd' },
  active: true,
  created_at: '2026-01-02T00:00:00Z',
};

function mockList() {
  get.mockImplementation((url: string) => {
    if (url === '/admin/companies') return Promise.resolve({ data: { data: [] } });
    return Promise.resolve({
      data: { data: [USER], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } },
    });
  });
}

function lastUsersParams() {
  const calls = get.mock.calls.filter((c) => c[0] === '/admin/users');
  return calls[calls.length - 1]?.[1]?.params ?? {};
}

beforeEach(() => {
  get.mockReset();
  mockList();
});
afterEach(cleanup);

function renderPage() {
  return render(
    <ThemeProvider>
      <MemoryRouter>
        <UserAdminPage />
      </MemoryRouter>
    </ThemeProvider>,
  );
}

it('sends role=staff_admin when the Staff admin tab is clicked', async () => {
  renderPage();
  // Name renders in both the desktop table and the mobile card list; jsdom
  // (css:false) hides neither, so match all and assert at least one.
  await waitFor(() => expect(screen.getAllByText('Dana Buyer')[0]).toBeTruthy());
  await userEvent.click(screen.getByRole('button', { name: 'Staff admin' }));
  await waitFor(() => expect(lastUsersParams().role).toBe('staff_admin'));
});

it('flips the sort param when the Name column header is clicked', async () => {
  renderPage();
  await waitFor(() => expect(screen.getAllByText('Dana Buyer')[0]).toBeTruthy());
  await userEvent.click(screen.getByRole('button', { name: /^Name/ }));
  await waitFor(() => expect(lastUsersParams().sort).toBe('name_desc'));
});

it('renders numbered pages and loads the page that is clicked', async () => {
  get.mockReset();
  get.mockImplementation((url: string) => {
    if (url === '/admin/companies') return Promise.resolve({ data: { data: [] } });
    return Promise.resolve({
      data: { data: [USER], meta: { current_page: 1, last_page: 3, per_page: 15, total: 45 } },
    });
  });
  renderPage();
  await waitFor(() => expect(screen.getAllByText('Dana Buyer')[0]).toBeTruthy());
  await userEvent.click(screen.getByRole('button', { name: '3' }));
  await waitFor(() => expect(lastUsersParams().page).toBe(3));
});
