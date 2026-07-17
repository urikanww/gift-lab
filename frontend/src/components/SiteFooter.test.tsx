import { expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import SiteFooter from './SiteFooter';

it('renders trust badges and link columns', () => {
  render(<MemoryRouter><SiteFooter /></MemoryRouter>);
  expect(screen.getByText(/secure checkout/i)).toBeInTheDocument();
  expect(screen.getByRole('contentinfo')).toBeInTheDocument();
});

it('links to track order', () => {
  render(<MemoryRouter><SiteFooter /></MemoryRouter>);
  expect(screen.getByRole('link', { name: /track order/i })).toHaveAttribute('href', '/track');
});
