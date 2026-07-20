import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import ProductThumb from './ProductThumb';

const product = { name: 'Ceramic Mug', image_url: 'https://cdn.test/mug.png' };

describe('ProductThumb', () => {
  it('is a plain, non-interactive image by default (the cart must not change)', () => {
    render(<ProductThumb product={product} />);

    expect(screen.queryByRole('button')).not.toBeInTheDocument();
    expect(document.querySelector('img')).toBeInTheDocument();
  });

  it('exposes a labelled button naming the product when zoomable', async () => {
    render(<ProductThumb product={product} zoomable />);

    const btn = screen.getByRole('button', { name: /view a larger photo of ceramic mug/i });
    // A real button, so it is keyboard reachable - not a click handler on a div.
    expect(btn.tagName).toBe('BUTTON');

    await userEvent.click(btn);
    expect(screen.getByRole('dialog', { name: /product image viewer/i })).toBeInTheDocument();
  });

  it('stays a non-interactive letter fallback when the product has no image', () => {
    render(<ProductThumb product={{ name: 'Ceramic Mug', image_url: null }} zoomable />);

    // Nothing to zoom into, so no button is offered even when opted in.
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
    expect(screen.getByText('C')).toBeInTheDocument();
  });
});
