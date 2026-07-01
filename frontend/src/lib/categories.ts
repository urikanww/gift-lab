import type { ProductClass } from '../types';

export interface Category {
  key: ProductClass;
  label: string;
  icon: string;
}

export const CATEGORIES: Category[] = [
  { key: 'CORE', label: 'Core gifts', icon: '📓' },
  { key: 'SCRAPED_UV', label: 'UV print', icon: '☕' },
  { key: 'MODEL_3D', label: '3D prints', icon: '🧩' },
];

export function categoryLabel(c: ProductClass): string {
  return CATEGORIES.find((x) => x.key === c)?.label ?? c;
}
