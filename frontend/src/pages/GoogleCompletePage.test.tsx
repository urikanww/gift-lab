import { afterEach, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { ThemeProvider } from '../ui';
import GoogleCompletePage from './GoogleCompletePage';
import { useAuthStore } from '../stores/authStore';
import api from '../lib/api';
import type { User } from '../types';

// The page reads the pending profile straight off the api instance.
vi.mock('../lib/api', () => ({
  default: { get: vi.fn() },
  API_ORIGIN: 'http://localhost:8000',
}));

const mockedGet = vi.mocked(api.get);
const initialStore = useAuthStore.getState();
afterEach(() => {
  useAuthStore.setState(initialStore, true);
  vi.clearAllMocks();
});

function renderPage(entry = '/register/google?pending=tok-1') {
  return render(
    <ThemeProvider>
      <MemoryRouter initialEntries={[entry]}>
        <Routes>
          <Route path="/register/google" element={<GoogleCompletePage />} />
          <Route path="/register" element={<div>REGISTER PAGE</div>} />
          <Route path="/account" element={<div>ACCOUNT PAGE</div>} />
        </Routes>
      </MemoryRouter>
    </ThemeProvider>,
  );
}

it('loads the pending profile, submits company + consent, then lands on the account', async () => {
  mockedGet.mockResolvedValueOnce({ data: { name: 'New Buyer', email: 'new@acme.example' } } as any);

  const completeGoogle = vi.fn(async () => {
    useAuthStore.setState({
      user: { id: 1, company_id: 7, name: 'New Buyer', email: 'new@acme.example', role: 'buyer' } as User,
    });
    return { ok: true as const, fieldErrors: {} };
  });
  useAuthStore.setState({ completeGoogle, error: null } as any);

  renderPage();

  // Read-only identity from the verified Google profile.
  await screen.findByText(/new@acme\.example/);

  const user = userEvent.setup();
  await user.type(screen.getByLabelText(/company name/i), 'Acme Pte Ltd');
  await user.click(screen.getByRole('checkbox')); // PDPA consent
  await user.click(screen.getByRole('button', { name: /create account/i }));

  await waitFor(() => expect(screen.getByText('ACCOUNT PAGE')).toBeInTheDocument());
  expect(completeGoogle).toHaveBeenCalledWith(
    expect.objectContaining({ token: 'tok-1', company_name: 'Acme Pte Ltd', consent: true }),
  );
});

it('shows a start-again state when the token is missing', async () => {
  renderPage('/register/google'); // no ?pending
  await screen.findByText(/expired or was already used/i);
  expect(screen.getByRole('link', { name: /back to sign up/i })).toBeInTheDocument();
  expect(mockedGet).not.toHaveBeenCalled();
});

it('shows a start-again state when the pending token has expired (410)', async () => {
  mockedGet.mockRejectedValueOnce(new Error('410'));
  renderPage();
  await screen.findByText(/expired or was already used/i);
});
