import { expect, it } from 'vitest';
import { cacheKeyFor } from './modelFaceSnapshot';

it('cache key changes when the model version changes', () => {
  expect(cacheKeyFor('slug', 'White', 'v1', 1000, 760))
    .not.toBe(cacheKeyFor('slug', 'White', 'v2', 1000, 760));
});

it('cache key is stable for identical inputs', () => {
  expect(cacheKeyFor('slug', 'White', 'v1', 1000, 760))
    .toBe(cacheKeyFor('slug', 'White', 'v1', 1000, 760));
});
