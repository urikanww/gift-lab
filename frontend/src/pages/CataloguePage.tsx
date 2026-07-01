import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api, { apiError } from '../lib/api';
import { AsyncBoundary } from '../components/ui/States';
import type { Product } from '../types';

export default function CataloguePage() {
  const [products, setProducts] = useState<Product[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = async () => {
    setLoading(true);
    setError(null);
    try {
      const { data } = await api.get<{ data: Product[] }>('/catalogue');
      setProducts(data.data);
    } catch (err) {
      setError(apiError(err));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    void load();
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
        onRetry={load}
      >
        <div className="grid">
          {products.map((p) => (
            <Link key={p.id} to={`/catalogue/${p.id}`} className="card">
              {p.image_url ? (
                <img src={p.image_url} alt={p.name} className="card__img" />
              ) : (
                <div className="card__img card__img--placeholder">{p.name.charAt(0)}</div>
              )}
              <div className="card__body">
                <h3>{p.name}</h3>
                <p className="muted">from {p.currency} {p.base_cost}</p>
              </div>
            </Link>
          ))}
        </div>
      </AsyncBoundary>
    </section>
  );
}
