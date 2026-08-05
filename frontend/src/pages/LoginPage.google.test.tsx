import { afterEach, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { ThemeProvider } from '../ui';
import LoginPage from './LoginPage';
import { useAuthStore } from '../stores/authStore';

// Isolate the error-mapping behaviour from the providers probe.
vi.mock('../lib/socialAuth', () => ({
  useGoogleEnabled: () => false,
  googleRedirectUrl: () => 'http://localhost:8000/auth/google/redirect',
}));

const initialStore = useAuthStore.getState();
afterEach(() => useAuthStore.setState(initialStore, true));

function renderLoginAt(entry: string) {
  return render(
    <ThemeProvider>
      <MemoryRouter initialEntries={[entry]}>
        <Routes>
          <Route path="/login" element={<LoginPage />} />
        </Routes>
      </MemoryRouter>
    </ThemeProvider>,
  );
}

it('maps ?error=google_email_exists to actionable copy, keeping the form usable', () => {
  renderLoginAt('/login?error=google_email_exists');
  expect(screen.getByRole('alert')).toHaveTextContent(/already has a password account/i);
  // Password form still available so they can sign in the other way.
  expect(screen.getByLabelText(/email/i)).toBeInTheDocument();
});

it('maps ?error=google_unverified', () => {
  renderLoginAt('/login?error=google_unverified');
  expect(screen.getByRole('alert')).toHaveTextContent(/isn.t verified/i);
});

it('shows no error banner for an unknown / absent error code', () => {
  renderLoginAt('/login');
  expect(screen.queryByRole('alert')).not.toBeInTheDocument();
});

it('offers a forgot-password link', () => {
  renderLoginAt('/login');
  expect(screen.getByRole('link', { name: /forgot password/i })).toHaveAttribute(
    'href',
    '/forgot-password',
  );
});

it('confirms a completed password reset via ?reset=success', () => {
  renderLoginAt('/login?reset=success');
  expect(screen.getByRole('status')).toHaveTextContent(/password has been reset/i);
});
