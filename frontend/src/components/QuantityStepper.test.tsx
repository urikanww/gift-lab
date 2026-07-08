import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import QuantityStepper from './QuantityStepper';

describe('QuantityStepper', () => {
  it('clamps below-min values up to min on blur', () => {
    const onChange = vi.fn();
    render(<QuantityStepper value={25} min={25} onChange={onChange} />);
    const input = screen.getByLabelText(/quantity/i) as HTMLInputElement;
    fireEvent.change(input, { target: { value: '5' } });
    fireEvent.blur(input);
    expect(onChange).toHaveBeenLastCalledWith(25);
  });

  it('decrement never goes below min', () => {
    const onChange = vi.fn();
    render(<QuantityStepper value={25} min={25} onChange={onChange} />);
    fireEvent.click(screen.getByLabelText(/decrease/i));
    expect(onChange).toHaveBeenLastCalledWith(25);
  });
});
