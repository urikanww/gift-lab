// Public marketplace taxonomy - how buyers browse. Decoupled from the internal
// print-class (CORE/SCRAPED_UV/MODEL_3D), which never appears in public UI.
// Keys mirror backend App\Services\Catalogue\CategoryClassifier::CATEGORIES.

export interface Category {
  key: string;
  label: string;
  icon: string;
  blurb: string;
}

export const CATEGORIES: Category[] = [
  { key: 'drinkware', label: 'Drinkware', icon: '☕', blurb: 'Mugs, tumblers & bottles' },
  { key: 'bags', label: 'Bags & Totes', icon: '👜', blurb: 'Totes, pouches & carry-alls' },
  { key: 'stationery', label: 'Stationery & Office', icon: '✏️', blurb: 'Notebooks, pens & desk gear' },
  { key: 'apparel', label: 'Apparel', icon: '👕', blurb: 'Tees, caps & wearables' },
  { key: 'tech', label: 'Tech & Gadgets', icon: '📱', blurb: 'Grips, stands & accessories' },
  { key: 'home', label: 'Home & Living', icon: '🏠', blurb: 'Coasters, frames & decor' },
  { key: 'accessories', label: 'Keychains & Pins', icon: '🔑', blurb: 'Keychains, pins & charms' },
  { key: 'toys', label: 'Toys & Figurines', icon: '🧸', blurb: '3D-printed figures & fun' },
];

export function categoryLabel(key: string | null | undefined): string {
  return CATEGORIES.find((c) => c.key === key)?.label ?? 'Gifts';
}
