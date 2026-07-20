import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import CustomizationPreview from './CustomizationPreview';

// The component exchanges a private storage ref for a signed URL over the API;
// stub that boundary so each case can choose what the exchange returns.
const fetchArtworkPreview = vi.fn();
vi.mock('../lib/uploadArtwork', () => ({
  fetchArtworkPreview: (ref: string) => fetchArtworkPreview(ref),
}));

beforeEach(() => {
  fetchArtworkPreview.mockReset();
});

describe('CustomizationPreview when the preview URL cannot be loaded', () => {
  it('says the design could not be loaded instead of rendering nothing', async () => {
    // Regression: the preview endpoint shared the upload rate limiter, so this
    // exchange 429'd on ordinary page loads. The component collapsed that to
    // null and rendered NOTHING - a customized line looked like a plain one.
    fetchArtworkPreview.mockResolvedValue({ ok: false });

    const { container } = render(
      <CustomizationPreview
        customization={{ artwork_ref: 'artwork/abc.png' } as any}
        productName="Ceramic Mug"
      />,
    );

    expect(await screen.findByText(/couldn’t be loaded/i)).toBeInTheDocument();
    // The failure is admitted to, but stays supplementary - no error banner.
    expect(container.querySelector('[role="alert"]')).toBeNull();
  });

  it('renders nothing at all for a line with no artwork', () => {
    const { container } = render(
      <CustomizationPreview customization={null} productName="Ceramic Mug" />,
    );

    expect(container).toBeEmptyDOMElement();
    // No artwork means no ref to exchange - it must not even ask.
    expect(fetchArtworkPreview).not.toHaveBeenCalled();
  });

  it('shows the design once the exchange succeeds', async () => {
    fetchArtworkPreview.mockResolvedValue({ ok: true, url: 'https://cdn.test/signed.png' });

    render(
      <CustomizationPreview
        customization={{ artwork_ref: 'artwork/abc.png' } as any}
        productName="Ceramic Mug"
      />,
    );

    expect(
      await screen.findByRole('button', { name: /preview your design for ceramic mug/i }),
    ).toBeInTheDocument();
    expect(screen.queryByText(/couldn’t be loaded/i)).not.toBeInTheDocument();
  });
});
