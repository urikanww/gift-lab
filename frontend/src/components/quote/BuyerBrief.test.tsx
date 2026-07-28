import { beforeEach, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import BuyerBrief from './BuyerBrief';
import type { Customization } from '../../types';

// The brief exchanges each private reference ref for a signed URL over the API;
// stub that boundary so the test controls what comes back.
const fetchArtworkPreview = vi.fn();
vi.mock('../../lib/uploadArtwork', () => ({
  fetchArtworkPreview: (ref: string) => fetchArtworkPreview(ref),
}));

beforeEach(() => {
  fetchArtworkPreview.mockReset();
  fetchArtworkPreview.mockResolvedValue({ ok: true, url: 'https://cdn.test/signed.png' });
});

it('shows the buyer brief (references + notes) for a buyer_uploaded line', async () => {
  const customization: Customization = {
    mode: 'buyer_uploaded',
    reference_refs: ['artwork/a.png', 'artwork/b.png'],
    placement_notes: 'Logo centred, gold foil.',
  };
  render(<BuyerBrief customization={customization} />);

  expect(screen.getByText(/please design/i)).toBeInTheDocument();
  expect(screen.getByText(/logo centred, gold foil/i)).toBeInTheDocument();
  // One resolved thumbnail per reference ref.
  await waitFor(() =>
    expect(screen.getAllByRole('button', { name: /open buyer reference/i })).toHaveLength(2),
  );
  expect(fetchArtworkPreview).toHaveBeenCalledWith('artwork/a.png');
  expect(fetchArtworkPreview).toHaveBeenCalledWith('artwork/b.png');
});

it('renders nothing for a self-designed line', () => {
  const { container } = render(
    <BuyerBrief customization={{ mode: 'designer', artwork_ref: 'artwork/x.png' }} />,
  );
  expect(container).toBeEmptyDOMElement();
  expect(fetchArtworkPreview).not.toHaveBeenCalled();
});

it('renders nothing for a plain line', () => {
  const { container } = render(<BuyerBrief customization={null} />);
  expect(container).toBeEmptyDOMElement();
});
