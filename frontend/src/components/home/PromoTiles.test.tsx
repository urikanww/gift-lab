import { expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import PromoTiles from './PromoTiles';

it('links to the kit builder and the catalogue', () => {
  render(
    <MemoryRouter>
      <PromoTiles />
    </MemoryRouter>,
  );

  expect(screen.getByRole('link', { name: /build a kit/i })).toHaveAttribute('href', '/kits');
  expect(screen.getByRole('link', { name: /bulk pricing/i })).toHaveAttribute('href', '/products');
});
