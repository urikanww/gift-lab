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
    schedule: { weekday: 'any', blackout_dates: ['2026-01-01'] },
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

it('loads pickup + window + schedule and saves edits (incl. parsed blackout dates)', async () => {
  renderPage();

  const address = await screen.findByLabelText('Address');
  expect(address).toHaveValue('71 Ayer Rajah Crescent');
  // Start/End are now separate fixed-list selects.
  expect(screen.getByLabelText('Start time')).toHaveValue('09:00');
  expect(screen.getByLabelText('End time')).toHaveValue('18:00');
  expect(screen.getByLabelText('Preferred collection day')).toHaveValue('any');

  await userEvent.clear(address);
  await userEvent.type(address, '5 New Depot Road');
  // Add a holiday; the textarea's text is parsed into a date list on save.
  const blackout = screen.getByLabelText(/non-collection dates/i);
  await userEvent.clear(blackout);
  await userEvent.type(blackout, '2026-02-17\n2026-04-03');
  await userEvent.click(screen.getByRole('button', { name: /save courier settings/i }));

  await waitFor(() => expect(patch).toHaveBeenCalledWith(
    '/admin/courier-config',
    expect.objectContaining({
      pickup: expect.objectContaining({ address1: '5 New Depot Road' }),
      schedule: expect.objectContaining({ blackout_dates: ['2026-02-17', '2026-04-03'] }),
    }),
  ));
});

it('constrains the end time to the selected start time', async () => {
  renderPage();
  await screen.findByLabelText('Address');

  // start 12:00 only has one valid end: 15:00.
  await userEvent.selectOptions(screen.getByLabelText('Start time'), '12:00');
  const end = screen.getByLabelText('End time') as HTMLSelectElement;
  expect([...end.options].map((o) => o.value)).toEqual(['15:00']);
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
