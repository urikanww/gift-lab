import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api, { apiError } from '../lib/api';
import { Card } from '../ui';
import { AsyncBoundary } from '../components/ui/States';
import { ShopeeLink } from '../components/ShopeeLink';

interface GiftIdea {
  name: string;
  image_url: string | null;
  offer_link: string;
  price: number | null;
  currency: string;
  shop_name: string | null;
}

export default function GiftIdeasPage() {
  const [ideas, setIdeas] = useState<GiftIdea[] | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    void (async () => {
      try {
        const { data } = await api.get<{ data: GiftIdea[] }>('/gift-ideas');
        setIdeas(data.data);
      } catch (err) {
        setError(apiError(err));
      } finally {
        setLoading(false);
      }
    })();
  }, []);

  return (
    <section className="flex flex-col gap-6">
      <div>
        <h1 className="font-display text-3xl text-fg">Gift ideas</h1>
        <p className="mt-1 text-sm text-fg-muted">UV-printable gift inspiration. Love one? We can personalize it for you.</p>
        {/* Required affiliate disclosure. */}
        <p className="mt-2 text-xs text-fg-subtle">
          This page contains affiliate links — we may earn a commission if you buy through them, at no extra cost to you.
        </p>
      </div>

      <AsyncBoundary loading={loading} error={error} isEmpty={(ideas ?? []).length === 0} emptyTitle="No gift ideas yet.">
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          {(ideas ?? []).map((g, i) => (
            <Card key={`${g.offer_link}-${i}`} padding="md" className="flex flex-col gap-2">
              {g.image_url && <img src={g.image_url} alt="" className="aspect-square w-full rounded object-cover" referrerPolicy="no-referrer" />}
              <p className="line-clamp-2 text-sm font-medium text-fg">{g.name}</p>
              <p className="text-sm"><span className="font-semibold text-fg">{g.currency} {g.price ?? '—'}</span>{g.shop_name ? <span className="text-xs text-fg-subtle"> · {g.shop_name}</span> : null}</p>
              <div className="mt-auto flex flex-col gap-1.5 pt-2">
                <ShopeeLink href={g.offer_link} rel="sponsored nofollow noopener noreferrer" className="w-full">
                  Buy on Shopee
                </ShopeeLink>
                <Link to="/products" className="rounded-md bg-primary px-3 py-1.5 text-center text-xs font-semibold text-primary-fg">
                  Personalize with us →
                </Link>
              </div>
            </Card>
          ))}
        </div>
      </AsyncBoundary>
    </section>
  );
}
