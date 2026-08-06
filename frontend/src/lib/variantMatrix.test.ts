import { describe, it, expect } from 'vitest';
import { generateVariantLabels } from './variantMatrix';

describe('generateVariantLabels', () => {
  it('cross-products two axes in order', () => {
    expect(generateVariantLabels([{ values: 'S, M' }, { values: 'Black, White' }])).toEqual([
      'S / Black',
      'S / White',
      'M / Black',
      'M / White',
    ]);
  });

  it('returns bare values for a single axis', () => {
    expect(generateVariantLabels([{ values: 'Red, Blue' }])).toEqual(['Red', 'Blue']);
  });

  it('trims, drops blanks, and de-dupes values case-insensitively per axis', () => {
    expect(generateVariantLabels([{ values: ' S , s ,  , M ' }])).toEqual(['S', 'M']);
  });

  it('ignores axes with no usable values', () => {
    expect(generateVariantLabels([{ values: 'S, M' }, { values: '  ' }])).toEqual(['S', 'M']);
  });

  it('returns an empty list when nothing is entered', () => {
    expect(generateVariantLabels([{ values: '' }])).toEqual([]);
  });
});
