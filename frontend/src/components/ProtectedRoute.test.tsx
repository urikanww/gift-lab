import { afterEach, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import ProtectedRoute from './ProtectedRoute';
import { useAuthStore } from '../stores/authStore';
import type { User } from '../types';

const initialStore = useAuthStore.getState();
afterEach(() => useAuthStore.setState(initialStore, true));

function setUser(user: Partial<User> & Pick<User, 'role'>) {
  useAuthStore.setState({
    user: { id: 1, company_id: null, name: 'U', email: 'u@x.test', ...user },
    status: 'ready',
    error: null,
  } as any);
}

function renderGuarded(guard: React.ReactElement) {
  return render(
    <MemoryRouter initialEntries={['/guarded']}>
      <Routes>
        <Route path="/guarded" element={guard} />
        <Route path="/dashboard" element={<div>DASHBOARD PAGE</div>} />
        <Route path="/login" element={<div>LOGIN PAGE</div>} />
        <Route path="/" element={<div>HOME PAGE</div>} />
      </Routes>
    </MemoryRouter>,
  );
}

it('redirects an unauthenticated visitor to /login', () => {
  useAuthStore.setState({ user: null, status: 'ready', error: null });
  renderGuarded(
    <ProtectedRoute permission="procurement.view">
      <div>PROCUREMENT PAGE</div>
    </ProtectedRoute>,
  );
  expect(screen.getByText('LOGIN PAGE')).toBeInTheDocument();
});

it('redirects a staff_admin lacking the required permission to /dashboard, not a 403 wall', () => {
  setUser({ role: 'staff_admin', permissions: ['production.view'] });
  renderGuarded(
    <ProtectedRoute permission="procurement.view">
      <div>PROCUREMENT PAGE</div>
    </ProtectedRoute>,
  );
  expect(screen.getByText('DASHBOARD PAGE')).toBeInTheDocument();
  expect(screen.queryByText('PROCUREMENT PAGE')).not.toBeInTheDocument();
});

it('lets a staff_admin holding the required permission through', () => {
  setUser({ role: 'staff_admin', permissions: ['procurement.view'] });
  renderGuarded(
    <ProtectedRoute permission="procurement.view">
      <div>PROCUREMENT PAGE</div>
    </ProtectedRoute>,
  );
  expect(screen.getByText('PROCUREMENT PAGE')).toBeInTheDocument();
});

it('lets a grandfathered staff_admin (no permissions array) through', () => {
  setUser({ role: 'staff_admin' });
  renderGuarded(
    <ProtectedRoute permission="procurement.view">
      <div>PROCUREMENT PAGE</div>
    </ProtectedRoute>,
  );
  expect(screen.getByText('PROCUREMENT PAGE')).toBeInTheDocument();
});

it('lets superadmin through any permission gate', () => {
  setUser({ role: 'superadmin' });
  renderGuarded(
    <ProtectedRoute permission="procurement.view">
      <div>PROCUREMENT PAGE</div>
    </ProtectedRoute>,
  );
  expect(screen.getByText('PROCUREMENT PAGE')).toBeInTheDocument();
});

it('redirects a buyer with a permission-guarded operational route to /dashboard', () => {
  setUser({ role: 'buyer', company_id: 7 });
  renderGuarded(
    <ProtectedRoute permission="procurement.view">
      <div>PROCUREMENT PAGE</div>
    </ProtectedRoute>,
  );
  expect(screen.getByText('DASHBOARD PAGE')).toBeInTheDocument();
});
