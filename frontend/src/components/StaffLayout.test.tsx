import { afterEach, describe, expect, it } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import { ThemeProvider, ToastProvider } from '../ui';
import StaffLayout from './StaffLayout';
import { useAuthStore } from '../stores/authStore';

const initialAuth = useAuthStore.getState();

afterEach(() => {
  cleanup();
  useAuthStore.setState(initialAuth, true);
});

function renderNav() {
  // Superadmin sees every nav item (hasPermission short-circuits true).
  useAuthStore.setState({
    user: { id: 1, company_id: null, name: 'Root', email: 'root@x.test', role: 'superadmin' },
  } as never);
  return render(
    <ThemeProvider>
      <ToastProvider>
        <MemoryRouter initialEntries={['/dashboard']}>
          <Routes>
            <Route element={<StaffLayout />}>
              <Route path="/dashboard" element={<div>home</div>} />
            </Route>
          </Routes>
        </MemoryRouter>
      </ToastProvider>
    </ThemeProvider>,
  );
}

describe('staff nav', () => {
  it('shows a "Buy list" item pointing at /procurement', () => {
    renderNav();
    const links = screen.getAllByRole('link', { name: 'Buy list' });
    expect(links.length).toBeGreaterThan(0);
    expect(links[0]).toHaveAttribute('href', '/procurement');
  });

  it('no longer shows the old restock "Buy-list" (/reorders) item', () => {
    renderNav();
    expect(screen.queryAllByRole('link', { name: 'Buy-list' })).toHaveLength(0);
    expect(screen.queryByText('Procurement')).toBeNull();
  });
});
