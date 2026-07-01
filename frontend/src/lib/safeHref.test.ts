import { describe, expect, it } from 'vitest';
import { safeHref } from './safeHref';

describe('safeHref', () => {
  it('allows http(s) and relative URLs', () => {
    expect(safeHref('https://cdn.example.com/proof.pdf')).toBe('https://cdn.example.com/proof.pdf');
    expect(safeHref('http://example.com/a')).toBe('http://example.com/a');
    expect(safeHref('/proofs/v1.pdf')).toBe('/proofs/v1.pdf');
  });

  it('blocks dangerous schemes', () => {
    expect(safeHref('javascript:alert(1)')).toBeUndefined();
    expect(safeHref('data:text/html,<script>alert(1)</script>')).toBeUndefined();
    expect(safeHref('vbscript:msgbox(1)')).toBeUndefined();
  });

  it('handles empty input', () => {
    expect(safeHref(null)).toBeUndefined();
    expect(safeHref(undefined)).toBeUndefined();
    expect(safeHref('')).toBeUndefined();
  });
});
