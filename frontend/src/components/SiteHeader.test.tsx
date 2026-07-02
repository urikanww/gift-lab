import { afterEach, expect, it } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { ThemeProvider } from '../ui';
import { useAuthStore } from '../stores/authStore';
import type { User } from '../types';
import SiteHeader from './SiteHeader';

const testUser: User = {
  id: 1,
  company_id: null,
  name: 'Ada Buyer',
  email: 'ada@example.com',
  role: 'buyer',
};

function renderHeader() {
  return render(
    <ThemeProvider><MemoryRouter><SiteHeader /></MemoryRouter></ThemeProvider>,
  );
}

afterEach(() => {
  useAuthStore.setState({ user: null, status: 'idle', error: null });
});

it('renders brand, primary nav, and a theme toggle', () => {
  renderHeader();
  expect(screen.getByRole('link', { name: /giftlab/i })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: /products/i })).toBeInTheDocument();
  expect(screen.getByRole('button', { name: /theme/i })).toBeInTheDocument();
});

it('shows Log out in the drawer for a logged-in user and closes on Escape', async () => {
  const user = userEvent.setup();
  useAuthStore.setState({ user: testUser, status: 'ready', error: null });
  renderHeader();

  await user.click(screen.getByRole('button', { name: /open menu/i }));
  const drawer = screen.getByRole('navigation', { name: /mobile/i });
  expect(within(drawer).getByRole('button', { name: /log out/i })).toBeInTheDocument();

  await user.keyboard('{Escape}');
  await waitFor(() =>
    expect(screen.queryByRole('navigation', { name: /mobile/i })).not.toBeInTheDocument(),
  );
});

it('shows ops navigation links for staff roles', () => {
  useAuthStore.setState({
    user: { ...testUser, role: 'staff_admin', company_id: null },
    status: 'ready',
    error: null,
  });
  renderHeader();

  expect(screen.getByRole('link', { name: /catalogue gate/i })).toHaveAttribute('href', '/catalogue-admin');
  expect(screen.getByRole('link', { name: /production/i })).toHaveAttribute('href', '/production-queue');
  expect(screen.getByRole('link', { name: /procurement/i })).toHaveAttribute('href', '/procurement');
});

it('hides ops navigation from buyers and anonymous visitors', () => {
  renderHeader();
  expect(screen.queryByRole('link', { name: /catalogue gate/i })).not.toBeInTheDocument();
});
