import { beforeEach, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

const get = vi.fn();
const patch = vi.fn();
vi.mock('../lib/api', () => ({
  default: { get: (...a: unknown[]) => get(...a), patch: (...a: unknown[]) => patch(...a) },
  apiError: () => 'Could not save that.',
  ensureCsrf: async () => {},
}));

import { ThemeProvider, ToastProvider } from '../ui';
import NotificationSettingsPage from './NotificationSettingsPage';

const settings = {
  data: {
    data: [
      {
        key: 'committed',
        label: 'Order confirmed',
        description: 'Sent when the order is committed to production.',
        enabled: true,
        default: true,
      },
      {
        key: 'line_changed',
        label: 'Item changed or dropped',
        description: 'Off by default — staff usually make this call personally.',
        enabled: false,
        default: false,
      },
    ],
    cadence: { quote_days: [3, 7, 12], proof_days: [2, 5, 9] },
  },
};

beforeEach(() => {
  get.mockReset().mockResolvedValue(settings);
  patch.mockReset().mockResolvedValue({ data: {} });
});

const renderPage = () =>
  render(
    <ThemeProvider>
      <ToastProvider>
        <NotificationSettingsPage />
      </ToastProvider>
    </ThemeProvider>,
  );

it('shows what each milestone currently does', async () => {
  renderPage();

  expect(await screen.findByLabelText('Order confirmed')).toBeChecked();
  expect(screen.getByLabelText('Item changed or dropped')).not.toBeChecked();
  expect(screen.getByDisplayValue('3, 7, 12')).toBeInTheDocument();
});

it('switches a milestone off', async () => {
  renderPage();
  const toggle = await screen.findByLabelText('Order confirmed');

  await userEvent.setup().click(toggle);

  await waitFor(() =>
    expect(patch).toHaveBeenCalledWith('/admin/notification-settings', {
      key: 'committed',
      enabled: false,
    }),
  );
});

// A switch that stays flipped after a failed write lies about what clients will
// actually receive.
it('rolls the switch back when saving fails', async () => {
  patch.mockRejectedValue(new Error('boom'));
  renderPage();
  const toggle = await screen.findByLabelText('Order confirmed');

  await userEvent.setup().click(toggle);

  await waitFor(() => expect(toggle).toBeChecked());
});

it('saves a reminder schedule', async () => {
  renderPage();
  const user = userEvent.setup();
  const field = await screen.findByLabelText('Unanswered quote');

  await user.clear(field);
  await user.type(field, '2, 6');
  await user.click(screen.getByRole('button', { name: /Save schedule/i }));

  await waitFor(() =>
    expect(patch).toHaveBeenCalledWith('/admin/notification-settings/cadence', {
      quote_days: [2, 6],
      proof_days: [2, 5, 9],
    }),
  );
});

// The ladder is meant to end. An unbounded list is a way to mail someone
// forever, so the UI refuses before the server has to.
it('refuses an unbounded ladder without calling the API', async () => {
  renderPage();
  const user = userEvent.setup();
  const field = await screen.findByLabelText('Unanswered quote');

  await user.clear(field);
  await user.type(field, '1, 2, 3, 4, 5, 6');
  await user.click(screen.getByRole('button', { name: /Save schedule/i }));

  expect(await screen.findByText(/ladder is meant to end/i)).toBeInTheDocument();
  expect(patch).not.toHaveBeenCalled();
});

it('rejects an empty schedule', async () => {
  renderPage();
  const user = userEvent.setup();
  const field = await screen.findByLabelText('Unanswered quote');

  await user.clear(field);
  await user.click(screen.getByRole('button', { name: /Save schedule/i }));

  expect(await screen.findByText(/at least one day/i)).toBeInTheDocument();
  expect(patch).not.toHaveBeenCalled();
});
