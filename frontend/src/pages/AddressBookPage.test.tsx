import { expect, it, afterEach, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { ThemeProvider } from '../ui';
import AddressBookPage from './AddressBookPage';
import { useSavedAddressStore } from '../stores/savedAddressStore';

afterEach(() => {
  useSavedAddressStore.setState({ addresses: [], loading: false, error: null });
});

it('hides Add when three addresses exist', () => {
  useSavedAddressStore.setState({
    addresses: [
      { id: 1, label: 'A', recipient_name: 'x', phone: '1', line1: 'l1', postal_code: 'p', country: 'SG' },
      { id: 2, label: 'B', recipient_name: 'x', phone: '1', line1: 'l1', postal_code: 'p', country: 'SG' },
      { id: 3, label: 'C', recipient_name: 'x', phone: '1', line1: 'l1', postal_code: 'p', country: 'SG' },
    ] as any,
    fetch: vi.fn(),
  } as any);

  render(
    <ThemeProvider>
      <MemoryRouter>
        <AddressBookPage />
      </MemoryRouter>
    </ThemeProvider>,
  );

  expect(screen.queryByRole('button', { name: /add address/i })).not.toBeInTheDocument();
  expect(screen.getByText(/maximum of 3/i)).toBeInTheDocument();
});
