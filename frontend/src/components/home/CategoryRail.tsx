import { Link } from 'react-router-dom';
import { CATEGORIES } from '../../lib/categories';

/**
 * Category navigation band. Deliberately sized as furniture, not a headline
 * section - the header dropdown carries the same 8 links, so this must not
 * read as a second, competing "Shop by category" feature.
 */
export default function CategoryRail() {
  return (
    <nav aria-label="Shop by category">
      <ul className="grid grid-cols-4 gap-2 sm:grid-cols-8">
        {CATEGORIES.map((c) => (
          <li key={c.key}>
            <Link
              to={`/products?category=${c.key}`}
              className="flex min-h-[44px] flex-col items-center gap-1 rounded-lg border border-border bg-surface px-2 py-3 text-center transition-colors duration-fast hover:border-primary/50 hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
              <span className="text-2xl" aria-hidden="true">
                {c.icon}
              </span>
              <span className="text-xs font-medium text-fg">{c.label}</span>
            </Link>
          </li>
        ))}
      </ul>
    </nav>
  );
}
