import { expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { ThemeProvider } from '../ui';
import SiteHeader from './SiteHeader';

function renderHeader() {
  return render(
    <ThemeProvider><MemoryRouter><SiteHeader /></MemoryRouter></ThemeProvider>,
  );
}

it('renders brand, primary nav, and a theme toggle', () => {
  renderHeader();
  expect(screen.getByRole('link', { name: /giftlab/i })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: /products/i })).toBeInTheDocument();
  expect(screen.getByRole('button', { name: /theme/i })).toBeInTheDocument();
});
