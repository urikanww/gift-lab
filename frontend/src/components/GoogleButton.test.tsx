import { afterEach, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { GoogleAuthSection } from './GoogleButton';
import { useGoogleEnabled } from '../lib/socialAuth';

// The section gates on the backend providers flag; drive it directly.
vi.mock('../lib/socialAuth', () => ({
  useGoogleEnabled: vi.fn(),
  googleRedirectUrl: () => 'http://localhost:8000/auth/google/redirect',
}));

const mockedEnabled = vi.mocked(useGoogleEnabled);
afterEach(() => vi.clearAllMocks());

it('renders a full-page anchor to the backend redirect when Google is enabled', () => {
  mockedEnabled.mockReturnValue(true);
  render(<GoogleAuthSection label="Sign in with Google" />);

  const link = screen.getByTestId('google-signin');
  expect(link).toHaveAttribute('href', 'http://localhost:8000/auth/google/redirect');
  expect(link).toHaveTextContent(/sign in with google/i);
});

it('renders nothing (no orphan divider) when Google is disabled', () => {
  mockedEnabled.mockReturnValue(false);
  const { container } = render(<GoogleAuthSection label="Sign in with Google" />);

  expect(screen.queryByTestId('google-signin')).not.toBeInTheDocument();
  expect(container).toBeEmptyDOMElement();
});
