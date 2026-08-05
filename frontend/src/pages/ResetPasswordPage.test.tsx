import { afterEach, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { ThemeProvider } from '../ui';
import ResetPasswordPage from './ResetPasswordPage';
import { useAuthStore } from '../stores/authStore';

const initialStore = useAuthStore.getState();
afterEach(() => useAuthStore.setState(initialStore, true));

function renderAt(entry: string) {
  return render(
    <ThemeProvider>
      <MemoryRouter initialEntries={[entry]}>
        <Routes>
          <Route path="/reset-password" element={<ResetPasswordPage />} />
          <Route path="/login" element={<div>LOGIN PAGE</div>} />
          <Route path="/forgot-password" element={<div>FORGOT PAGE</div>} />
        </Routes>
      </MemoryRouter>
    </ThemeProvider>,
  );
}

it('resets with the URL token + re-entered email, then lands on login', async () => {
  const resetPassword = vi.fn(async () => ({ ok: true, fieldErrors: {}, message: 'ok' }));
  useAuthStore.setState({ resetPassword } as any);

  renderAt('/reset-password?token=tok-123');
  const user = userEvent.setup();
  await user.type(screen.getByLabelText(/email/i), 'reset@acme.example');
  await user.type(screen.getByLabelText(/^New password/i), 'brand-new-password-9');
  await user.type(screen.getByLabelText(/confirm new password/i), 'brand-new-password-9');
  await user.click(screen.getByRole('button', { name: /reset password/i }));

  await waitFor(() => expect(screen.getByText('LOGIN PAGE')).toBeInTheDocument());
  expect(resetPassword).toHaveBeenCalledWith(
    expect.objectContaining({ token: 'tok-123', email: 'reset@acme.example' }),
  );
});

it('redirects to forgot-password when the URL has no token', () => {
  renderAt('/reset-password');
  expect(screen.getByText('FORGOT PAGE')).toBeInTheDocument();
});

it('shows field errors from an invalid token response', async () => {
  const resetPassword = vi.fn(async () => ({
    ok: false,
    fieldErrors: { email: 'This password reset token is invalid.' },
    message: '',
  }));
  useAuthStore.setState({ resetPassword } as any);

  renderAt('/reset-password?token=bad');
  const user = userEvent.setup();
  await user.type(screen.getByLabelText(/email/i), 'x@acme.example');
  await user.type(screen.getByLabelText(/^New password/i), 'brand-new-password-9');
  await user.type(screen.getByLabelText(/confirm new password/i), 'brand-new-password-9');
  await user.click(screen.getByRole('button', { name: /reset password/i }));

  await waitFor(() => expect(screen.getByText(/token is invalid/i)).toBeInTheDocument());
});
