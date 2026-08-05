import { afterEach, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { ThemeProvider } from '../ui';
import LoginPage from './LoginPage';
import { useAuthStore } from '../stores/authStore';
import type { User } from '../types';

// LoginPage now renders the Google section, which probes /auth/providers on
// mount. Stub it so these credential-flow tests don't fire a real request.
vi.mock('../lib/socialAuth', () => ({
  useGoogleEnabled: () => false,
  googleRedirectUrl: () => 'http://localhost:8000/auth/google/redirect',
}));

const initialStore = useAuthStore.getState();
afterEach(() => useAuthStore.setState(initialStore, true));

function stubLoginAs(role: User['role']) {
  useAuthStore.setState({
    error: null,
    login: async () => {
      useAuthStore.setState({
        user: { id: 1, company_id: role === 'buyer' ? 7 : null, name: 'U', email: 'u@x.test', role },
        status: 'ready',
        error: null,
      });
      return true;
    },
  } as any);
}

function renderLogin() {
  return render(
    <ThemeProvider>
      <MemoryRouter initialEntries={['/login']}>
        <Routes>
          <Route path="/login" element={<LoginPage />} />
          <Route path="/dashboard" element={<div>DASHBOARD PAGE</div>} />
          <Route path="/account" element={<div>BUYER DASHBOARD</div>} />
          <Route path="/quotes" element={<div>QUOTES PAGE</div>} />
        </Routes>
      </MemoryRouter>
    </ThemeProvider>,
  );
}

async function submitCredentials() {
  const user = userEvent.setup();
  await user.type(screen.getByLabelText(/email/i), 'someone@giftlab.local');
  await user.type(screen.getByLabelText(/password/i), 'secret');
  await user.click(screen.getByRole('button', { name: /sign in/i }));
}

it('lands staff on the dashboard after sign-in', async () => {
  stubLoginAs('staff_admin');
  renderLogin();
  await submitCredentials();
  await waitFor(() => expect(screen.getByText('DASHBOARD PAGE')).toBeInTheDocument());
});

it('lands superadmin on the dashboard after sign-in', async () => {
  stubLoginAs('superadmin');
  renderLogin();
  await submitCredentials();
  await waitFor(() => expect(screen.getByText('DASHBOARD PAGE')).toBeInTheDocument());
});

it('lands buyers on their dashboard after sign-in', async () => {
  stubLoginAs('buyer');
  renderLogin();
  await submitCredentials();
  await waitFor(() => expect(screen.getByText('BUYER DASHBOARD')).toBeInTheDocument());
});

it('redirects an already-authenticated staff_admin away from /login to the dashboard', () => {
  useAuthStore.setState({
    user: { id: 1, company_id: null, name: 'U', email: 'u@x.test', role: 'staff_admin' },
    status: 'ready',
    error: null,
  } as any);
  renderLogin();
  expect(screen.getByText('DASHBOARD PAGE')).toBeInTheDocument();
  expect(screen.queryByLabelText(/email/i)).not.toBeInTheDocument();
});

it('redirects an already-authenticated buyer away from /login to their account', () => {
  useAuthStore.setState({
    user: { id: 2, company_id: 7, name: 'B', email: 'b@x.test', role: 'buyer' },
    status: 'ready',
    error: null,
  } as any);
  renderLogin();
  expect(screen.getByText('BUYER DASHBOARD')).toBeInTheDocument();
});
