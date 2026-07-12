import { describe, expect, it } from 'vitest';
import { primarySourceLink, type SourceLink } from './sourceLinks';

const local: SourceLink = { label: 'LocalCo', url: 'https://localco.sg/mug', kind: 'local', price: 12, currency: 'SGD', last_checked: null };
const market: SourceLink = { label: 'Shopee', url: 'https://shopee.sg/product/1/2', kind: 'marketplace', price: 9.9, currency: 'SGD', last_checked: null };

describe('primarySourceLink', () => {
  it('prefers the first local link', () => {
    expect(primarySourceLink([market, local])?.url).toBe('https://localco.sg/mug');
  });
  it('falls back to the first link', () => {
    expect(primarySourceLink([market])?.url).toBe('https://shopee.sg/product/1/2');
  });
  it('returns null for empty', () => {
    expect(primarySourceLink([])).toBeNull();
  });
});
