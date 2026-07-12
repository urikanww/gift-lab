import { useCallback, useEffect, useState } from 'react';
import { Badge, Button, useOptionalToast } from '../../ui';
import { apiError } from '../../lib/api';
import { ShopeeBagIcon } from '../ShopeeLink';
import { listFeatured, unfeature, type FeaturedItem } from '../../lib/recommendations';

/**
 * Staff management of the public gift-ideas feed. Lists everything currently
 * featured (curated via the blank recommender's "Feature") and lets staff remove
 * rows. Self-loading; render it wherever a management surface is wanted.
 */
export default function FeaturedGiftIdeasPanel() {
  const { toast } = useOptionalToast();
  const [items, setItems] = useState<FeaturedItem[]>([]);
  const [loaded, setLoaded] = useState(false);
  const [busy, setBusy] = useState<number | null>(null);

  const refresh = useCallback(async () => {
    try {
      setItems(await listFeatured());
    } catch {
      /* non-critical: leave the list empty */
    } finally {
      setLoaded(true);
    }
  }, []);

  useEffect(() => {
    void refresh();
  }, [refresh]);

  const remove = async (item: FeaturedItem) => {
    setBusy(item.id);
    try {
      await unfeature(item.id);
      setItems((prev) => prev.filter((f) => f.id !== item.id));
      toast({ title: 'Removed from gift-ideas', description: item.name, tone: 'success' });
    } catch (err) {
      toast({ title: 'Remove failed', description: apiError(err), tone: 'danger' });
    } finally {
      setBusy(null);
    }
  };

  if (!loaded) return null; // avoid flashing an empty section before the first load

  return (
    <section className="flex flex-col gap-3">
      <div>
        <h2 className="font-display text-xl text-fg">Featured on gift-ideas ({items.length})</h2>
        <p className="mt-1 text-sm text-fg-muted">
          Affiliate products shown on the public gift-ideas page. Add them from Find blanks → Feature.
        </p>
        <p className="mt-2 text-xs text-fg-subtle">
          Clicking a product opens your affiliate link (preview). Don’t purchase through it — buy in a
          separate/incognito browser so it isn’t self-referral.
        </p>
      </div>

      {items.length === 0 ? (
        <p className="text-sm text-fg-subtle">Nothing featured yet.</p>
      ) : (
        <div className="flex flex-col gap-2">
          {items.map((f) => (
            <div key={f.id} className="flex items-center gap-3 rounded-md border border-border p-2">
              {/* Preview via the affiliate link (view only). Don't purchase
                  through it — self-referral; see the note above. */}
              <a
                href={f.offer_link}
                target="_blank"
                rel="sponsored nofollow noopener noreferrer"
                className="group flex min-w-0 flex-1 items-center gap-3"
                title="Preview on Shopee (affiliate link — don't buy through it)"
              >
                {f.image_url && <img src={f.image_url} alt="" className="h-12 w-12 shrink-0 rounded object-cover" referrerPolicy="no-referrer" />}
                <div className="min-w-0">
                  <p className="flex items-center gap-1 truncate text-sm font-medium text-fg group-hover:underline">
                    {f.name}
                    <ShopeeBagIcon className="h-3.5 w-3.5 shrink-0 text-[#EE4D2D]" />
                  </p>
                  <p className="text-xs text-fg-subtle">
                    {f.currency} {f.price ?? '—'}
                    {f.shop_name ? ` · ${f.shop_name}` : ''}
                    {f.ip_flagged ? ' · hidden (IP-flagged)' : ''}
                  </p>
                </div>
              </a>
              {f.ip_flagged && <Badge tone="warning" size="sm">IP</Badge>}
              <Button size="sm" variant="danger" loading={busy === f.id} onClick={() => void remove(f)}>Remove</Button>
            </div>
          ))}
        </div>
      )}
    </section>
  );
}
