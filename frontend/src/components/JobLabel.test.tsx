import { afterEach, expect, it, vi } from 'vitest';
import { cleanup, render, screen, waitFor } from '@testing-library/react';

vi.mock('qrcode', () => ({
  default: { toCanvas: vi.fn() },
}));

import QRCode from 'qrcode';
import JobLabel from './JobLabel';
import { ToastProvider } from '../ui';

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
});

it('renders the QR and opens the print dialog on a successful generation', async () => {
  vi.mocked(QRCode.toCanvas).mockResolvedValue(undefined);
  const printSpy = vi.spyOn(window, 'print').mockImplementation(() => {});

  render(
    <ToastProvider>
      <JobLabel jobId={7} onClose={() => {}} />
    </ToastProvider>,
  );

  await waitFor(() => expect(printSpy).toHaveBeenCalledTimes(1));
  expect(screen.queryByRole('alert')).not.toBeInTheDocument();
});

// Bug: `QRCode.toCanvas(...).then(...)` had no `.catch` - a rejected generation
// (e.g. a canvas/render failure) left an unhandled promise rejection, never
// called window.print(), and the operator saw a blank label modal with no
// indication anything went wrong.
it('surfaces a visible error and skips printing when QR generation fails', async () => {
  vi.mocked(QRCode.toCanvas).mockRejectedValue(new Error('canvas boom'));
  const printSpy = vi.spyOn(window, 'print').mockImplementation(() => {});

  render(
    <ToastProvider>
      <JobLabel jobId={7} onClose={() => {}} />
    </ToastProvider>,
  );

  // Both an in-modal message and a toast surface the failure - match by text
  // and expect at least the in-modal one (findAllByText tolerates the
  // duplicate from the toast).
  await waitFor(() =>
    expect(screen.getAllByText(/could not generate the label qr code/i).length).toBeGreaterThan(0),
  );
  expect(printSpy).not.toHaveBeenCalled();
  // The modal still offers a way out instead of hanging silently.
  expect(screen.getByRole('button', { name: /close/i })).toBeInTheDocument();
});
