import { afterEach, expect, it } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { ThemeProvider } from '../ui';
import LoginPage from './LoginPage';
import { useAuthStore } from '../stores/authStore';
import type { User } from '../types';

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
          <Route path="/catalogue-admin" element={<div>GATE PAGE</div>} />
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

it('lands staff on the catalogue gate after sign-in', async () => {
  stubLoginAs('staff_admin');
  renderLogin();
  await submitCredentials();
  await waitFor(() => expect(screen.getByText('GATE PAGE')).toBeInTheDocument());
});

it('lands superadmin on the catalogue gate after sign-in', async () => {
  stubLoginAs('superadmin');
  renderLogin();
  await submitCredentials();
  await waitFor(() => expect(screen.getByText('GATE PAGE')).toBeInTheDocument());
});

it('lands buyers on their quotes after sign-in', async () => {
  stubLoginAs('buyer');
  renderLogin();
  await submitCredentials();
  await waitFor(() => expect(screen.getByText('QUOTES PAGE')).toBeInTheDocument());
});
