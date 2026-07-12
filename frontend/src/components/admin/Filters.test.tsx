import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { FilterChips } from './Filters';

describe('FilterChips', () => {
  const chips = [
    { key: 'source', label: 'Source: Local' },
    { key: 'blocker', label: 'Blocker: Missing dimensions' },
  ];

  it('renders a chip per active filter', () => {
    render(<FilterChips chips={chips} onRemove={() => {}} onClear={() => {}} />);
    expect(screen.getByText('Source: Local')).toBeInTheDocument();
    expect(screen.getByText('Blocker: Missing dimensions')).toBeInTheDocument();
  });

  it('calls onRemove with the chip key', async () => {
    const onRemove = vi.fn();
    render(<FilterChips chips={chips} onRemove={onRemove} onClear={() => {}} />);
    await userEvent.click(screen.getByLabelText('Remove filter: Source: Local'));
    expect(onRemove).toHaveBeenCalledWith('source');
  });

  it('renders nothing when there are no chips', () => {
    const { container } = render(<FilterChips chips={[]} onRemove={() => {}} onClear={() => {}} />);
    expect(container).toBeEmptyDOMElement();
  });
});
