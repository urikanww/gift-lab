import { afterEach, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { ThemeProvider } from '../ui';
import RegisterPage from './RegisterPage';
import { useAuthStore } from '../stores/authStore';

const initial = useAuthStore.getState();
afterEach(() => {
  useAuthStore.setState(initial, true);
  vi.restoreAllMocks();
});

function renderPage() {
  render(
    <ThemeProvider>
      <MemoryRouter>
        <RegisterPage />
      </MemoryRouter>
    </ThemeProvider>,
  );
}

// F1: a failed registration used to show one lumped banner of Laravel-default
// messages. The 422 field bag now maps onto each input inline.
it('shows per-field validation messages inline', async () => {
  const register = vi.fn(async () => ({
    ok: false,
    fieldErrors: {
      company_name: 'Please enter your company name',
      email: 'That email is already registered',
    },
  }));
  useAuthStore.setState({ user: null, error: null, register } as any);
  renderPage();

  fireEvent.click(screen.getByRole('button', { name: /create account/i }));

  expect(await screen.findByText('Please enter your company name')).toBeInTheDocument();
  expect(screen.getByText('That email is already registered')).toBeInTheDocument();
  expect(register).toHaveBeenCalledTimes(1);
});
