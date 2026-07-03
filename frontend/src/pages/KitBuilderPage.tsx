import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api, { apiError } from '../lib/api';
import { fetchCatalogue } from '../lib/catalogue';
import { fetchBrandKit, type BrandKit } from '../lib/brandKit';
import { uploadArtwork } from '../lib/uploadArtwork';
import { useCartStore } from '../stores/cartStore';
import { useAuthStore } from '../stores/authStore';
import { CardImage } from '../components/product/ProductCard';
import { Badge, Button, Card, Select, Skeleton, useToast, cn } from '../ui';
import type { PriceEstimate, Product } from '../types';

const QTY_OPTIONS = [25, 50, 100, 250];

interface KitItem {
  product: Product;
  qty: number;
}

/**
 * Curated multi-product kit builder. A kit is just a curated multi-line cart —
 * the existing cart→quote flow already yields ONE quote, ONE delivery, with
 * per-track jobs that gate atomically. Optionally brands every item in one go.
 */
export default function KitBuilderPage() {
  const navigate = useNavigate();
  const { toast } = useToast();
  const addLine = useCartStore((s) => s.addLine);
  const companyId = useAuthStore((s) => s.user?.company_id ?? null);

  const [products, setProducts] = useState<Product[]>([]);
  const [loading, setLoading] = useState(true);
  const [tray, setTray] = useState<KitItem[]>([]);
  const [brandKit, setBrandKit] = useState<BrandKit | null>(null);
  const [applyBrand, setApplyBrand] = useState(false);
  const [estimate, setEstimate] = useState<PriceEstimate | null>(null);
  const [adding, setAdding] = useState(false);

  useEffect(() => {
    let active = true;
    fetchCatalogue({})
      .then((res) => {
        if (active) setProducts(res.data);
      })
      .catch(() => {
        if (active) setProducts([]);
      })
      .finally(() => {
        if (active) setLoading(false);
      });
    return () => {
      active = false;
    };
  }, []);

  useEffect(() => {
    if (!companyId) return;
    let active = true;
    fetchBrandKit()
      .then((kit) => {
        if (active) setBrandKit(kit);
      })
      .catch(() => {
        if (active) setBrandKit(null);
      });
    return () => {
      active = false;
    };
  }, [companyId]);

  const canBrand = applyBrand && !!brandKit?.logo;

  // Live combined estimate for the whole kit (one quote → one delivery).
  useEffect(() => {
    if (tray.length === 0) {
      setEstimate(null);
      return;
    }
    let active = true;
    api
      .post<PriceEstimate>('/price-estimate', {
        line_items: tray.map((it) => ({
          product_id: it.product.id,
          variant_id: it.product.variants?.[0]?.id ?? null,
          qty: it.qty,
          has_customization: canBrand,
          logo_size: canBrand ? 'M' : null,
        })),
      })
      .then(({ data }) => {
        if (active) setEstimate(data);
      })
      .catch(() => {
        if (active) setEstimate(null);
      });
    return () => {
      active = false;
    };
  }, [tray, canBrand]);

  const inTray = useMemo(() => new Set(tray.map((t) => t.product.id)), [tray]);

  const addToKit = (product: Product) =>
    setTray((t) => (t.some((i) => i.product.id === product.id) ? t : [...t, { product, qty: 50 }]));
  const removeFromKit = (id: number) => setTray((t) => t.filter((i) => i.product.id !== id));
  const setQty = (id: number, qty: number) =>
    setTray((t) => t.map((i) => (i.product.id === id ? { ...i, qty } : i)));

  const addKitToCart = async () => {
    if (tray.length === 0) return;
    setAdding(true);
    try {
      // Brand every line with one upload of the saved logo (reused ref).
      let ref: string | undefined;
      if (canBrand && brandKit?.logo) ref = await uploadArtwork(brandKit.logo);

      for (const item of tray) {
        const variant = item.product.variants?.[0] ?? null;
        const customization = ref ? { logo_size: 'M', artwork_ref: ref } : {};
        addLine(item.product, variant, customization, item.qty);
      }
      toast({ title: 'Kit added to cart', description: `${tray.length} products`, tone: 'success' });
      navigate('/cart');
    } catch (err) {
      toast({ title: 'Could not add kit', description: apiError(err), tone: 'danger' });
    } finally {
      setAdding(false);
    }
  };

  return (
    <div className="flex flex-col gap-6 lg:flex-row lg:items-start">
      {/* Catalogue picker */}
      <div className="flex min-w-0 flex-1 flex-col gap-4">
        <header className="flex flex-col gap-2">
          <h1 className="font-display text-3xl leading-tight text-fg sm:text-4xl">Build a kit</h1>
          <p className="text-fg-muted">
            Pick a few products, brand them together, and get one quote with a single delivery.
          </p>
        </header>

        {loading ? (
          <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
            {Array.from({ length: 6 }).map((_, i) => (
              <Skeleton key={i} height="12rem" />
            ))}
          </div>
        ) : (
          <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
            {products.map((p) => (
              <Card key={p.id} padding="sm" className="flex flex-col gap-2">
                <div className="aspect-[4/3] w-full overflow-hidden rounded-md border border-border bg-surface-2">
                  <CardImage product={p} />
                </div>
                <p className="line-clamp-1 text-sm font-medium text-fg">{p.name}</p>
                <p className="text-xs text-fg-muted">
                  from {p.currency} {p.from_price.toFixed(2)}
                </p>
                <Button
                  variant={inTray.has(p.id) ? 'secondary' : 'outline'}
                  size="sm"
                  className="min-h-[44px]"
                  disabled={inTray.has(p.id)}
                  onClick={() => addToKit(p)}
                >
                  {inTray.has(p.id) ? 'In kit' : 'Add to kit'}
                </Button>
              </Card>
            ))}
          </div>
        )}
      </div>

      {/* Kit tray */}
      <aside className="w-full shrink-0 lg:sticky lg:top-20 lg:w-80">
        <Card padding="lg" className="flex flex-col gap-4">
          <div className="flex items-center justify-between">
            <h2 className="font-display text-lg text-fg">Your kit</h2>
            <Badge tone="brand" size="sm">
              {tray.length} {tray.length === 1 ? 'item' : 'items'}
            </Badge>
          </div>

          {tray.length === 0 ? (
            <p className="text-sm text-fg-muted">Add products to start your kit.</p>
          ) : (
            <ul className="flex flex-col divide-y divide-border">
              {tray.map((it) => (
                <li key={it.product.id} className="flex items-center gap-2 py-2 first:pt-0">
                  <span className="min-w-0 flex-1 truncate text-sm text-fg">{it.product.name}</span>
                  <div className="w-24">
                    <Select
                      label=""
                      value={String(it.qty)}
                      onChange={(e) => setQty(it.product.id, Number(e.target.value))}
                    >
                      {QTY_OPTIONS.map((n) => (
                        <option key={n} value={n}>
                          {n} pcs
                        </option>
                      ))}
                    </Select>
                  </div>
                  <button
                    type="button"
                    aria-label={`Remove ${it.product.name}`}
                    onClick={() => removeFromKit(it.product.id)}
                    className="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-md text-lg text-fg-subtle hover:text-danger focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                  >
                    ×
                  </button>
                </li>
              ))}
            </ul>
          )}

          {brandKit?.logo && (
            <label className="flex items-center gap-2 text-sm text-fg">
              <input
                type="checkbox"
                checked={applyBrand}
                onChange={(e) => setApplyBrand(e.target.checked)}
                className="h-4 w-4 rounded border-border"
              />
              Apply my brand logo to every item
            </label>
          )}

          {estimate && (
            <div className={cn('flex items-baseline justify-between border-t border-border pt-3')}>
              <span className="text-sm text-fg-muted">Estimated total</span>
              <span className="font-display text-xl text-fg">
                {estimate.currency} {Number(estimate.total).toFixed(2)}
              </span>
            </div>
          )}

          <Button size="lg" onClick={() => void addKitToCart()} loading={adding} disabled={adding || tray.length === 0}>
            Add kit to cart
          </Button>
        </Card>
      </aside>
    </div>
  );
}
