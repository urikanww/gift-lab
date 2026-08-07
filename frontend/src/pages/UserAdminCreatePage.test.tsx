import { afterEach, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';

vi.mock('../lib/api', () => ({
  default: { get: vi.fn().mockResolvedValue({ data: { data: [] } }), post: vi.fn() },
  apiError: (e: unknown) => String(e),
  ensureCsrf: vi.fn().mockResolvedValue(undefined),
}));

import { ThemeProvider, ToastProvider } from '../ui';
import UserAdminCreatePage from './UserAdminCreatePage';

afterEach(cleanup);

function renderPage() {
  return render(
    <ThemeProvider>
      <ToastProvider>
        <MemoryRouter>
          <UserAdminCreatePage />
        </MemoryRouter>
      </ToastProvider>
    </ThemeProvider>,
  );
}

it('offers only staff roles and no buyer/company field', () => {
  renderPage();
  const roleSelect = screen.getByLabelText('Role') as HTMLSelectElement;
  const values = Array.from(roleSelect.options).map((o) => o.value);
  expect(values).toEqual(['staff_admin', 'superadmin']);
  expect(screen.queryByText('Buyer')).toBeNull();
  // Company selector only ever appeared for buyers - it must be gone.
  expect(screen.queryByLabelText('Company')).toBeNull();
});
