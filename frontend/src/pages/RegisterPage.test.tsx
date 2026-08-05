import { afterEach, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { ThemeProvider } from '../ui';
import RegisterPage from './RegisterPage';
import { useAuthStore } from '../stores/authStore';

// RegisterPage now renders the Google section, which probes /auth/providers on
// mount. Stub it so these form tests don't fire a real request.
vi.mock('../lib/socialAuth', () => ({
  useGoogleEnabled: () => false,
  googleRedirectUrl: () => 'http://localhost:8000/auth/google/redirect',
}));

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

  // Consent gates submit; tick it so the form can be submitted at all.
  fireEvent.click(screen.getByRole('checkbox', { name: /privacy policy/i }));
  fireEvent.click(screen.getByRole('button', { name: /create account/i }));

  expect(await screen.findByText('Please enter your company name')).toBeInTheDocument();
  expect(screen.getByText('That email is already registered')).toBeInTheDocument();
  expect(register).toHaveBeenCalledTimes(1);
});

// PDPA: registration requires explicit consent, gated client-side too.
it('disables submit until the privacy-policy consent is ticked', () => {
  useAuthStore.setState({ user: null, error: null } as any);
  renderPage();

  const submit = screen.getByRole('button', { name: /create account/i });
  expect(submit).toBeDisabled();

  fireEvent.click(screen.getByRole('checkbox', { name: /privacy policy/i }));
  expect(submit).toBeEnabled();
});
