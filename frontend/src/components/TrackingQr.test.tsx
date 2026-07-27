import { afterEach, expect, it, vi } from 'vitest';
import { cleanup, render, screen, waitFor } from '@testing-library/react';

vi.mock('qrcode', () => ({
  default: { toCanvas: vi.fn() },
}));

import QRCode from 'qrcode';
import TrackingQr from './TrackingQr';

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
});

it('renders the QR canvas on a successful generation', async () => {
  vi.mocked(QRCode.toCanvas).mockResolvedValue(undefined);

  render(<TrackingQr link="/t/abc123" />);

  await waitFor(() => expect(QRCode.toCanvas).toHaveBeenCalledTimes(1));
  expect(screen.getByLabelText(/order tracking qr code/i)).toBeInTheDocument();
});

// Bug: `QRCode.toCanvas(...)` had no `.catch` - a rejected generation left an
// unhandled promise rejection with no fallback for the buyer.
it('handles a rejected QR generation without an unhandled rejection', async () => {
  vi.mocked(QRCode.toCanvas).mockRejectedValue(new Error('canvas boom'));

  render(<TrackingQr link="/t/abc123" />);

  await waitFor(() => expect(QRCode.toCanvas).toHaveBeenCalledTimes(1));
  // Give the rejected promise's .catch a tick to settle before the test ends.
  await new Promise((r) => setTimeout(r, 0));
});
