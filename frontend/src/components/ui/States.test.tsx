import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { AsyncBoundary } from './States';

describe('AsyncBoundary', () => {
  const child = <p>Loaded content</p>;

  it('shows a loading state', () => {
    render(
      <AsyncBoundary loading error={null} isEmpty={false} emptyTitle="Empty">
        {child}
      </AsyncBoundary>,
    );
    expect(screen.getByRole('status')).toBeInTheDocument();
    expect(screen.queryByText('Loaded content')).not.toBeInTheDocument();
  });

  it('shows an error state with a working retry', async () => {
    const onRetry = vi.fn();
    render(
      <AsyncBoundary loading={false} error="Boom" isEmpty={false} emptyTitle="Empty" onRetry={onRetry}>
        {child}
      </AsyncBoundary>,
    );
    expect(screen.getByRole('alert')).toHaveTextContent('Boom');
    await userEvent.click(screen.getByRole('button', { name: /retry/i }));
    expect(onRetry).toHaveBeenCalledOnce();
  });

  it('shows an empty state', () => {
    render(
      <AsyncBoundary loading={false} error={null} isEmpty emptyTitle="Nothing here">
        {child}
      </AsyncBoundary>,
    );
    expect(screen.getByText('Nothing here')).toBeInTheDocument();
  });

  it('renders children when ready', () => {
    render(
      <AsyncBoundary loading={false} error={null} isEmpty={false} emptyTitle="Empty">
        {child}
      </AsyncBoundary>,
    );
    expect(screen.getByText('Loaded content')).toBeInTheDocument();
  });
});
