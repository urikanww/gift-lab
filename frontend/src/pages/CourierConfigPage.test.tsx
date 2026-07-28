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
import CourierConfigPage from './CourierConfigPage';

const config = {
  data: {
    pickup: {
      name: 'Gift Lab', phone: '+6560000000', email: 'ops@giftlab.test',
      address1: '71 Ayer Rajah Crescent', city: 'Singapore', state: 'Singapore',
      postcode: '139951', country: 'SG',
    },
    timeslot: { start: '09:00', end: '18:00', timezone: 'Asia/Singapore' },
  },
};

beforeEach(() => {
  get.mockReset().mockResolvedValue(config);
  patch.mockReset().mockResolvedValue(config);
});

function renderPage() {
  render(
    <ThemeProvider>
      <ToastProvider>
        <CourierConfigPage />
      </ToastProvider>
    </ThemeProvider>,
  );
}

it('loads the current pickup address + window and saves edits', async () => {
  renderPage();

  const address = await screen.findByLabelText('Address');
  expect(address).toHaveValue('71 Ayer Rajah Crescent');
  // Collection window is a fixed-list select; the seeded 09:00-18:00 is selected.
  expect(screen.getByLabelText('Collection window')).toHaveValue('09:00-18:00');

  await userEvent.clear(address);
  await userEvent.type(address, '5 New Depot Road');
  await userEvent.click(screen.getByRole('button', { name: /save courier settings/i }));

  await waitFor(() => expect(patch).toHaveBeenCalledWith(
    '/admin/courier-config',
    expect.objectContaining({ pickup: expect.objectContaining({ address1: '5 New Depot Road' }) }),
  ));
});

it('surfaces server validation errors inline', async () => {
  patch.mockRejectedValue({
    response: { status: 422, data: { errors: { 'timeslot.end': ['The end must be after the start.'] } } },
  });
  renderPage();
  await screen.findByLabelText('Address');

  await userEvent.click(screen.getByRole('button', { name: /save courier settings/i }));

  expect(await screen.findByText(/end must be after the start/i)).toBeInTheDocument();
});
