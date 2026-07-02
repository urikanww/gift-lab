import { useCallback, useEffect, useMemo, useState } from 'react';
import { useNavigate, useParams, useSearchParams } from 'react-router-dom';
import api, { apiError } from '../lib/api';
import { AsyncBoundary } from '../components/ui/States';
import DesignerCanvas, { type CapturedArtwork } from '../components/DesignerCanvas';
import Model3dPersonalizer, { type Model3dCustomization } from '../components/Model3dPersonalizer';
import { useCartStore } from '../stores/cartStore';
import { uploadArtwork } from '../lib/uploadArtwork';
import type { PriceEstimate, Product, Variant } from '../types';
import { Button, Select, Badge, Card, useToast } from '../ui';
import { Motion, fadeInUp } from '../motion';

const QTY_OPTIONS = [1, 25, 50, 100, 250, 500];

export default function ProductDesignerPage() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const initialName = searchParams.get('name')?.slice(0, 24) ?? '';
  const addLine = useCartStore((s) => s.addLine);
  const { toast } = useToast();

  const [product, setProduct] = useState<Product | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [variantId, setVariantId] = useState<number | null>(null);
  const [artwork, setArtwork] = useState<CapturedArtwork | null>(null);
  // MODEL_3D items add a filament-colour choice; logo/text placement uses the
  // shared canvas because the item is FDM-printed then UV-decorated on its
  // flat face — the placement mockup is a producible production step.
  const [model3dOptions, setModel3dOptions] = useState<Model3dCustomization | null>(null);
  const is3d = product?.class === 'MODEL_3D';
  const [qty, setQty] = useState(50);
  const [estimate, setEstimate] = useState<{ unit: number; lineTotal: number; currency: string } | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const { data } = await api.get<{ data: Product }>(`/catalogue/${id}`);
      setProduct(data.data);
      setVariantId(data.data.variants?.[0]?.id ?? null);
    } catch (err) {
      setError(apiError(err));
    } finally {
      setLoading(false);
    }
  }, [id]);

  useEffect(() => {
    void load();
  }, [load]);

  // Live quote: re-estimate whenever qty, variant or captured artwork changes.
  // Event-driven single POST per change — never polled.
  useEffect(() => {
    if (!product) return;
    let active = true;
    api
      .post<PriceEstimate>('/price-estimate', {
        line_items: [
          { product_id: product.id, variant_id: variantId, qty, has_customization: !!artwork },
        ],
      })
      .then(({ data }) => {
        if (!active) return;
        // Line total, NOT data.total — the order total bakes in delivery and
        // setup fee, so "unit × qty" would visibly fail to reconcile here.
        setEstimate({
          unit: data.lines[0]?.unit_price ?? 0,
          lineTotal: data.lines[0]?.line_total ?? 0,
          currency: data.currency,
        });
      })
      .catch(() => {
        if (active) setEstimate(null);
      });
    return () => {
      active = false;
    };
  }, [product, variantId, qty, artwork]);

  const selectedVariant: Variant | null = useMemo(
    () => product?.variants?.find((v) => v.id === variantId) ?? null,
    [product, variantId],
  );

  const [uploading, setUploading] = useState(false);
  const [uploadError, setUploadError] = useState<string | null>(null);

  const handleCapture = (art: CapturedArtwork) => {
    setArtwork(art);
    toast({ title: 'Design saved', description: 'Your layout is ready to add to the order.', tone: 'success' });
  };

  const addToCart = async () => {
    if (!product) return;
    setUploadError(null);
    // 3D lines carry both the filament colour and any canvas artwork; UV/CORE
    // lines carry artwork only.
    const customization: Record<string, unknown> = {
      ...(is3d ? (model3dOptions ?? { filament_color: 'Black' }) : {}),
      ...(artwork?.customization ?? {}),
    };
    // Persist the captured artwork server-side; store the returned ref (not the
    // large data URL) on the cart line. On failure, surface an error and ABORT —
    // previously the failure was swallowed and the line was added with no
    // artwork_ref, silently losing the buyer's design.
    if (artwork?.dataUrl) {
      setUploading(true);
      try {
        customization.artwork_ref = await uploadArtwork(artwork.dataUrl);
      } catch (err) {
        const message = apiError(err) || 'We couldn’t upload your artwork. Please try again.';
        setUploadError(message);
        toast({ title: 'Upload failed', description: message, tone: 'danger' });
        return;
      } finally {
        setUploading(false);
      }
    }
    addLine(product, selectedVariant, customization, qty);
    toast({ title: 'Added to cart', description: product.name, tone: 'success' });
    navigate('/cart');
  };

  return (
    <AsyncBoundary
      loading={loading}
      error={error}
      isEmpty={!product}
      emptyTitle="Product not found."
      onRetry={load}
    >
      {product && (
        <Motion
          variants={fadeInUp}
          initial="hidden"
          animate="visible"
          className="mx-auto flex w-full max-w-5xl flex-col gap-6"
        >
          {/* Header */}
          <header className="flex flex-col gap-3">
            <div className="flex flex-wrap items-center gap-2">
              <Badge tone="brand" size="sm">
                Design studio
              </Badge>
              {product.print_method && (
                <Badge tone="neutral" size="sm">
                  {product.print_method}
                </Badge>
              )}
            </div>
            <h1 className="font-display text-3xl leading-tight sm:text-4xl">{product.name}</h1>
            {product.description && <p className="max-w-2xl text-fg-muted">{product.description}</p>}
            {product.creator_credit && (
              <p className="text-sm text-fg-subtle">Design by {product.creator_credit}</p>
            )}
          </header>

          {/* Variant picker */}
          {product.variants && product.variants.length > 0 && (
            <Card padding="sm" className="max-w-xs">
              <Select
                label="Variant"
                value={variantId ?? ''}
                onChange={(e) => setVariantId(Number(e.target.value))}
              >
                {product.variants.map((v) => (
                  <option key={v.id} value={v.id}>
                    {Object.values(v.attributes).join(' / ')} {v.in_stock ? '' : '(made to order)'}
                  </option>
                ))}
              </Select>
            </Card>
          )}

          {/* Configurator — 3D items pick a filament colour, then everyone
              places logo/text on the canvas over the product photo (3D items
              are UV-decorated after printing) */}
          {is3d && <Model3dPersonalizer onChange={setModel3dOptions} />}
          <DesignerCanvas backgroundUrl={product.image_url} onCapture={handleCapture} initialNameText={initialName} />

          {/* Sticky action bar */}
          <div className="sticky bottom-4 z-raised">
            <div className="flex flex-col gap-3 rounded-lg border border-border bg-surface/95 p-4 shadow-lg backdrop-blur sm:flex-row sm:items-center sm:justify-between">
              <div className="flex items-center gap-2 text-sm">
                {artwork ? (
                  <Badge tone="success" dot size="md">
                    Design captured
                  </Badge>
                ) : (
                  <span className="text-fg-muted">
                    {is3d
                      ? 'Pick a colour, place your design, then choose “Use this design” — or add to cart plain.'
                      : 'Add a logo or text, then choose “Use this design”.'}
                  </span>
                )}
              </div>
              <div className="flex flex-col items-stretch gap-2 sm:flex-row sm:items-center sm:gap-3">
                <div className="w-28">
                  <Select label="Quantity" value={String(qty)} onChange={(e) => setQty(Number(e.target.value))}>
                    {QTY_OPTIONS.map((n) => (
                      <option key={n} value={n}>
                        {n} pcs
                      </option>
                    ))}
                  </Select>
                </div>
                {estimate && (
                  <p className="text-sm text-fg-muted" role="status" aria-live="polite">
                    <span className="font-semibold text-fg">
                      {estimate.currency} {estimate.unit.toFixed(2)}
                    </span>{' '}
                    / unit ·{' '}
                    <span className="font-semibold text-fg">
                      {estimate.currency} {estimate.lineTotal.toFixed(2)}
                    </span>{' '}
                    for {qty}
                  </p>
                )}
                {uploadError && (
                  <p className="text-sm text-danger" role="alert">
                    {uploadError}
                  </p>
                )}
                <Button onClick={addToCart} disabled={!product || uploading} loading={uploading} size="lg">
                  {uploading ? 'Uploading…' : 'Add to cart'}
                </Button>
              </div>
            </div>
          </div>
        </Motion>
      )}
    </AsyncBoundary>
  );
}
