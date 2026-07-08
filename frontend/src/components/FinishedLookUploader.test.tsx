import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import FinishedLookUploader, { type FinishedLookValue } from './FinishedLookUploader';

vi.mock('../lib/uploadArtwork', () => ({
  uploadArtworkFile: vi.fn(async () => 'artwork/ref-1.png'),
}));

describe('FinishedLookUploader', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  const lastValue = (onChange: ReturnType<typeof vi.fn>): FinishedLookValue => {
    const calls = onChange.mock.calls;
    return calls[calls.length - 1][0] as FinishedLookValue;
  };

  it('emits reference_refs from an uploaded reference and the typed placement notes', async () => {
    const onChange = vi.fn();
    render(<FinishedLookUploader onChange={onChange} />);

    const file = new File(['x'], 'ref.png', { type: 'image/png' });
    const refInput = screen.getByLabelText('Reference image') as HTMLInputElement;
    fireEvent.change(refInput, { target: { files: [file] } });

    await waitFor(() => {
      expect(onChange).toHaveBeenCalled();
      expect(lastValue(onChange).reference_refs).toContain('artwork/ref-1.png');
    });

    const notes = screen.getByLabelText('Placement notes');
    fireEvent.change(notes, { target: { value: 'centre of the lid' } });

    await waitFor(() => {
      const last = lastValue(onChange);
      expect(last.placement_notes).toBe('centre of the lid');
      expect(last.reference_refs).toContain('artwork/ref-1.png');
    });
  });
});
