import { beforeEach, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

const post = vi.fn();
vi.mock('../../lib/api', () => ({
  default: { post: (...args: unknown[]) => post(...args) },
  apiError: () => 'Upload failed.',
  ensureCsrf: async () => {},
}));

import ProofFileInput from './ProofFileInput';

beforeEach(() => {
  post.mockReset();
});

const file = (name: string, type: string, sizeBytes = 1024) => {
  const f = new File(['x'], name, { type });
  Object.defineProperty(f, 'size', { value: sizeBytes });
  return f;
};

const renderInput = (onChange = vi.fn(), value = '') => {
  render(<ProofFileInput label="Proof artwork" value={value} onChange={onChange} />);
  return onChange;
};

it('uploads the picked file and reports the stored ref', async () => {
  post.mockResolvedValue({ data: { ref: 'proofs/abc.pdf' } });
  const onChange = renderInput();

  await userEvent.setup().upload(
    screen.getByLabelText('Proof artwork'),
    file('proof-v1.pdf', 'application/pdf'),
  );

  await waitFor(() => expect(onChange).toHaveBeenCalledWith('proofs/abc.pdf', 'proof-v1.pdf'));
  expect(post).toHaveBeenCalledWith('/uploads/proof', expect.any(FormData));
});

// Mirrors the server's cap. A courtesy, not the gate - but a 3 MB round-trip is
// a slow way to learn the file was too big.
it('rejects a file over 3 MB without uploading it', async () => {
  const onChange = renderInput();

  await userEvent.setup().upload(
    screen.getByLabelText('Proof artwork'),
    file('huge.pdf', 'application/pdf', 4 * 1024 * 1024),
  );

  expect(await screen.findByRole('alert')).toHaveTextContent('3 MB or smaller');
  expect(post).not.toHaveBeenCalled();
  expect(onChange).not.toHaveBeenCalled();
});

// applyAccept:false reproduces a user overriding the file dialog's filter,
// which browsers allow. The accept attribute is a hint, not a guarantee, so the
// component must still refuse the file.
it('rejects a file that is neither an image nor a PDF', async () => {
  const onChange = renderInput();

  await userEvent.setup({ applyAccept: false }).upload(
    screen.getByLabelText('Proof artwork'),
    file('payload.zip', 'application/zip'),
  );

  expect(await screen.findByRole('alert')).toHaveTextContent('PDF, PNG, JPG or WEBP');
  expect(post).not.toHaveBeenCalled();
  expect(onChange).not.toHaveBeenCalled();
});

it('surfaces a failed upload without reporting a ref', async () => {
  post.mockRejectedValue(new Error('boom'));
  const onChange = renderInput();

  await userEvent.setup().upload(
    screen.getByLabelText('Proof artwork'),
    file('proof.pdf', 'application/pdf'),
  );

  expect(await screen.findByRole('alert')).toHaveTextContent('Upload failed.');
  expect(onChange).not.toHaveBeenCalled();
});

it('clears an attached proof back to empty', async () => {
  const onChange = vi.fn();
  renderInput(onChange, 'proofs/abc.pdf');

  await userEvent.setup().click(screen.getByRole('button', { name: 'Remove' }));

  expect(onChange).toHaveBeenCalledWith('', null);
});
