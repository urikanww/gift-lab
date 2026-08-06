import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import { MemoryRouter } from 'react-router-dom';

vi.mock('../lib/api', () => ({
  default: { post: vi.fn().mockResolvedValue({ data: { data: { created: 6, skipped: 0 } } }) },
  apiError: (e: unknown) => (e instanceof Error ? e.message : String(e)),
  ensureCsrf: async () => {},
}));

import api from '../lib/api';
import { EditForm, VariantsSection } from './ProductAdminDetailPage';
import { ToastProvider } from '../ui/Toast';
import type { AdminProduct } from '../types';

const product = {
  id: 1,
  name: 'Mug',
  class: 'SCRAPED_UV',
  base_cost: '5.00',
  print_method: 'UV',
  stock_mode: 'MAKE_TO_ORDER',
  is_printable: false,
  dimensions: { l: 10, w: 10, h: 10 },
  weight: '0.2',
  variants: [],
} as unknown as AdminProduct;

function wrap(node: React.ReactNode) {
  return render(
    <MemoryRouter>
      <ToastProvider>{node}</ToastProvider>
    </MemoryRouter>,
  );
}

describe('EditForm printable control', () => {
  it('renders a Printable control seeded from the product (off => "no")', () => {
    wrap(<EditForm product={product} onChanged={() => {}} />);

    const control = screen.getByLabelText('Printable') as HTMLSelectElement;
    expect(control.value).toBe('no');
  });
});

describe('VariantsSection matrix bulk-add', () => {
  it('previews the cross-product and posts it to the bulk endpoint', async () => {
    wrap(<VariantsSection product={product} onChanged={() => {}} disabled={false} />);

    const values = screen.getAllByLabelText(/values/i);
    fireEvent.change(values[0], { target: { value: 'S, M, L' } });
    fireEvent.change(values[1], { target: { value: 'Black, White' } });

    expect(await screen.findByText(/will create 6 variants/i)).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: /create 6 variants/i }));

    const post = (api as unknown as { post: ReturnType<typeof vi.fn> }).post;
    await waitFor(() =>
      expect(post).toHaveBeenCalledWith(
        '/admin/products/1/variants/bulk',
        expect.objectContaining({
          variants: expect.arrayContaining([expect.objectContaining({ option: 'S / Black' })]),
        }),
      ),
    );
  });
});
