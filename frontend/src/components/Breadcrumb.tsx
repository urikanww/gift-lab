import { Fragment } from 'react';
import { Link } from 'react-router-dom';

export interface Crumb {
  label: string;
  /** Omit on the final crumb - the current page is not a link. */
  to?: string;
}

const linkClass =
  'hover:text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-bg';

/**
 * Trail of ancestor links ending in the current page.
 *
 * The last crumb is always rendered as plain text with `aria-current="page"`,
 * whether or not it carries a `to` - a link to the page you are already on is
 * noise for everyone and a dead end for screen-reader users.
 */
export default function Breadcrumb({ items }: { items: Crumb[] }) {
  if (items.length === 0) return null;

  return (
    <nav aria-label="Breadcrumb" className="text-sm text-fg-muted">
      <ol className="flex flex-wrap items-center gap-1.5">
        {items.map((item, i) => {
          const isLast = i === items.length - 1;
          return (
            <Fragment key={`${item.label}-${i}`}>
              {i > 0 && <li aria-hidden="true">/</li>}
              <li className={isLast ? 'text-fg' : undefined} aria-current={isLast ? 'page' : undefined}>
                {isLast || !item.to ? item.label : <Link to={item.to} className={linkClass}>{item.label}</Link>}
              </li>
            </Fragment>
          );
        })}
      </ol>
    </nav>
  );
}
