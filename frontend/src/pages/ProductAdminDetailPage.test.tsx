import { render, screen } from '@testing-library/react';
import { describe, it, expect } from 'vitest';
import { MemoryRouter } from 'react-router-dom';
import { EditForm } from './ProductAdminDetailPage';
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
} as unknown as AdminProduct;

describe('EditForm printable control', () => {
  it('renders a Printable control seeded from the product (off => "no")', () => {
    render(
      <MemoryRouter>
        <ToastProvider>
          <EditForm product={product} onChanged={() => {}} />
        </ToastProvider>
      </MemoryRouter>,
    );

    const control = screen.getByLabelText('Printable') as HTMLSelectElement;
    expect(control.value).toBe('no');
  });
});
