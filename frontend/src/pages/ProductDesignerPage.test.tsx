import { expect, it, afterEach, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import { ThemeProvider, ToastProvider } from '../ui';
import ProductDesignerPage from './ProductDesignerPage';
import api from '../lib/api';
import { useCartStore } from '../stores/cartStore';

// The designer mounts heavy 3D/fabric children (THREE, fabric canvas) that jsdom
// can't run. Stub them to inert placeholders - this test only exercises the
// buyer_uploaded entry-point gate, not the canvas.
vi.mock('../components/DesignerCanvas', () => ({
  __esModule: true,
  default: () => <div data-testid="designer-canvas" />,
}));
vi.mock('../components/Model3dDecalPreview', () => ({
  __esModule: true,
  default: () => <div />,
}));
vi.mock('../components/Model3dPersonalizer', () => ({
  __esModule: true,
  default: () => <div />,
  DEFAULT_FILAMENT_COLOR: '#ccc',
}));
vi.mock('../components/FinishedLookUploader', () => ({
  __esModule: true,
  default: () => <div data-testid="finished-look-uploader" />,
}));
vi.mock('../lib/modelFaceSnapshot', () => ({
  renderModelFace: vi.fn().mockResolvedValue(null),
}));
vi.mock('../lib/useLeadTimeEstimate', () => ({
  useLeadTimeEstimate: () => ({ state: 'idle' }),
}));

const CORE_PRODUCT = {
  id: 7,
  name: 'Classic Mug',
  class: 'CORE',
  has_model: false,
  print_zone: null,
  image_url: null,
  min_order_qty: 1,
  variants: [{ id: 1, attributes: { color: 'White' }, in_stock: true }],
};

afterEach(() => {
  vi.restoreAllMocks();
  useCartStore.setState({ lines: [], neededBy: '' });
});

function renderDesigner() {
  return render(
    <ThemeProvider>
      <ToastProvider>
        <MemoryRouter initialEntries={['/design/7']}>
          <Routes>
            <Route path="/design/:id" element={<ProductDesignerPage />} />
          </Routes>
        </MemoryRouter>
      </ToastProvider>
    </ThemeProvider>,
  );
}

it('does not offer the "Upload finished look" entry point (gated off until its pricing exists) - M8', async () => {
  vi.spyOn(api, 'get').mockResolvedValue({ data: { data: CORE_PRODUCT } } as any);
  vi.spyOn(api, 'post').mockResolvedValue({
    data: {
      currency: 'SGD',
      lines: [{ unit_price: 10, line_total: 10 }],
      subtotal: 10,
      delivery: 0,
      gst: 0,
      gst_rate: 9,
      total: 10,
      delivery_reliable: true,
    },
  } as any);

  renderDesigner();

  // Wait for the product to load and the designer surface to render.
  await waitFor(() => expect(screen.getByText('Classic Mug')).toBeInTheDocument());

  // The buyer-uploaded toggle must be unreachable: a finished-look line carries
  // no logo size band, so it can't be priced correctly yet.
  expect(screen.queryByText('Upload finished look')).not.toBeInTheDocument();
});
