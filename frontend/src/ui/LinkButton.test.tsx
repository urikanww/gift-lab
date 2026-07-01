import { expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { LinkButton } from './LinkButton';

it('renders a link with the correct href and children', () => {
  render(
    <MemoryRouter>
      <LinkButton to="/products">Browse products</LinkButton>
    </MemoryRouter>,
  );

  const link = screen.getByRole('link', { name: 'Browse products' });
  expect(link).toBeInTheDocument();
  expect(link).toHaveAttribute('href', '/products');
});
