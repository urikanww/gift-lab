import { afterEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import ReorderRail from './ReorderRail';
import * as quotes from '../../lib/quotes';
import type { Quote } from '../../types';

const quote = (id: number): Quote =>
  ({
    id,
    company_id: 1,
    reference: `REF${id}`,
    state: 'ACCEPTED',
    currency: 'SGD',
    subtotal: '100.00',
    delivery: '0.00',
    total: '250.00',
    price_snapshot_at: null,
    notes: null,
    needed_by: null,
    created_at: '2026-07-01T00:00:00Z',
  }) as Quote;

const renderRail = () =>
  render(
    <MemoryRouter>
      <ReorderRail />
    </MemoryRouter>,
  );

afterEach(() => vi.restoreAllMocks());

describe('ReorderRail', () => {
  it('links each quote to its detail page', async () => {
    vi.spyOn(quotes, 'fetchRecentQuotes').mockResolvedValue([quote(7)]);
    renderRail();

    await waitFor(() =>
      expect(screen.getByRole('link', { name: /quote #7/i })).toHaveAttribute('href', '/orders/REF7'),
    );
  });

  it('renders nothing when the buyer has no quotes', async () => {
    vi.spyOn(quotes, 'fetchRecentQuotes').mockResolvedValue([]);
    const { container } = renderRail();

    await waitFor(() => expect(container).toBeEmptyDOMElement());
  });

  it('renders nothing when the fetch fails - never an error state', async () => {
    vi.spyOn(quotes, 'fetchRecentQuotes').mockRejectedValue(new Error('boom'));
    const { container } = renderRail();

    await waitFor(() => expect(container).toBeEmptyDOMElement());
  });

  it('asks for at most 3 quotes', async () => {
    const spy = vi.spyOn(quotes, 'fetchRecentQuotes').mockResolvedValue([]);
    renderRail();

    await waitFor(() => expect(spy).toHaveBeenCalledWith(3));
  });
});
