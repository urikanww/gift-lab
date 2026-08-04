import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import PrivacyPolicyPage from './PrivacyPolicyPage';

describe('PrivacyPolicyPage', () => {
  it('renders the policy heading and a DPO contact', () => {
    render(
      <MemoryRouter>
        <PrivacyPolicyPage />
      </MemoryRouter>,
    );
    expect(screen.getByRole('heading', { name: /privacy policy/i })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: /data protection officer/i })).toBeInTheDocument();
  });
});
