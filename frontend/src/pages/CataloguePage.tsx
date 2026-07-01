import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api, { apiError } from '../lib/api';
import { AsyncBoundary } from '../components/ui/States';
import { safeHref } from '../lib/safeHref';
import type { Paginated, Product } from '../types';

/**
 * Scraped image URLs are external and untrusted: route through safeHref (drops
 * javascript:/data: etc.), fall back to the initial placeholder on load error,
 * and suppress the referrer on the outbound request.
 */
function CardImage({ product }: { product: Product }) {
  const [failed, setFailed] = useState(false);
  const href = safeHref(product.image_url);

  if (!href || failed) {
    return <div className="card__img card__img--placeholder">{product.name.charAt(0)}</div>;
  }

  return (
    <img
      src={href}
      alt={product.name}
      className="card__img"
      loading="lazy"
      referrerPolicy="no-referrer"
      onError={() => setFailed(true)}
    />
  );
}

export default function CataloguePage() {
  const [products, setProducts] = useState<Product[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);

  const load = async (target = 1) => {
    setLoading(true);
    setError(null);
    try {
      const { data } = await api.get<Paginated<Product>>('/catalogue', { params: { page: target } });
      setProducts(data.data);
      setPage(data.meta?.current_page ?? target);
      setLastPage(data.meta?.last_page ?? 1);
    } catch (err) {
      setError(apiError(err));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    void load(1);
  }, []);

  return (
    <section>
      <h1>Catalogue</h1>
      <p className="muted">Browse and customise — no account needed until you request a quote.</p>

      <AsyncBoundary
        loading={loading}
        error={error}
        isEmpty={products.length === 0}
        emptyTitle="No products published yet."
        onRetry={() => load(page)}
      >
        <div className="grid">
          {products.map((p) => (
            <Link key={p.id} to={`/catalogue/${p.id}`} className="card">
              <CardImage product={p} />
              <div className="card__body">
                <h3>{p.name}</h3>
                <p className="muted">from {p.currency} {p.from_price.toFixed(2)}</p>
              </div>
            </Link>
          ))}
        </div>

        {lastPage > 1 && (
          <nav className="pager" aria-label="Pagination">
            <button
              type="button"
              className="btn"
              disabled={loading || page <= 1}
              onClick={() => void load(page - 1)}
            >
              Previous
            </button>
            <span className="pager__status">
              Page {page} of {lastPage}
            </span>
            <button
              type="button"
              className="btn"
              disabled={loading || page >= lastPage}
              onClick={() => void load(page + 1)}
            >
              Next
            </button>
          </nav>
        )}
      </AsyncBoundary>
    </section>
  );
}
