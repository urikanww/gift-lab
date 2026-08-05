import { afterEach, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { ThemeProvider } from '../ui';
import ForgotPasswordPage from './ForgotPasswordPage';
import { useAuthStore } from '../stores/authStore';

const initialStore = useAuthStore.getState();
afterEach(() => useAuthStore.setState(initialStore, true));

function renderPage() {
  return render(
    <ThemeProvider>
      <MemoryRouter initialEntries={['/forgot-password']}>
        <Routes>
          <Route path="/forgot-password" element={<ForgotPasswordPage />} />
          <Route path="/login" element={<div>LOGIN PAGE</div>} />
        </Routes>
      </MemoryRouter>
    </ThemeProvider>,
  );
}

it('submits the email and shows the generic confirmation', async () => {
  const forgotPassword = vi.fn(async () => ({
    ok: true,
    message: 'If that email is registered, we have sent a password reset link.',
  }));
  useAuthStore.setState({ forgotPassword } as any);

  renderPage();
  const user = userEvent.setup();
  await user.type(screen.getByLabelText(/email/i), 'someone@acme.example');
  await user.click(screen.getByRole('button', { name: /send reset link/i }));

  await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent(/we have sent a password reset link/i));
  expect(forgotPassword).toHaveBeenCalledWith('someone@acme.example');
  // The form is replaced by the confirmation.
  expect(screen.queryByRole('button', { name: /send reset link/i })).not.toBeInTheDocument();
});
