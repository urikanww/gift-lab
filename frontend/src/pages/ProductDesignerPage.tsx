import { useCallback, useEffect, useMemo, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import api, { apiError } from '../lib/api';
import { AsyncBoundary } from '../components/ui/States';
import DesignerCanvas, { type CapturedArtwork } from '../components/DesignerCanvas';
import Model3dPersonalizer, {
  DEFAULT_FILAMENT_COLOR,
  type Model3dCustomization,
} from '../components/Model3dPersonalizer';
import { renderModelFace, type ModelFaceSnapshot } from '../lib/modelFaceSnapshot';
import { useCartStore } from '../stores/cartStore';
import { useAuthStore } from '../stores/authStore';
import { fetchBrandKit, type BrandKit } from '../lib/brandKit';
import { uploadArtwork } from '../lib/uploadArtwork';
import type { PriceEstimate, Product, Variant } from '../types';
import { Button, Select, Badge, Card, Input, useToast } from '../ui';
import { Motion, fadeInUp } from '../motion';

const QTY_OPTIONS = [1, 25, 50, 100, 250, 500];

interface LeadEstimate {
  earliest: string;
  latest: string;
  rush_available: boolean;
  rush_earliest: string | null;
  rush_fee: number | null;
}

const fmtDate = (d: string) => new Date(d).toLocaleDateString(undefined, { day: 'numeric', month: 'short' });

export default function ProductDesignerPage() {
  const { id } = useParams();
  const navigate = useNavigate();
  const addLine = useCartStore((s) => s.addLine);
  // "Need it by" is an order-level deadline held on the cart, so the buyer's
  // chosen date survives the designer → cart → checkout hop and reaches the quote.
  const needBy = useCartStore((s) => s.neededBy);
  const setNeedBy = useCartStore((s) => s.setNeededBy);
  const { toast } = useToast();

  const [product, setProduct] = useState<Product | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [variantId, setVariantId] = useState<number | null>(null);
  const [artwork, setArtwork] = useState<CapturedArtwork | null>(null);
  // Live logo/text state lifted from the canvas so the price bar reflects the
  // selected size band and any text fee before the design is captured.
  const [logo, setLogo] = useState<{ hasLogo: boolean; size: string; hasText: boolean }>({
    hasLogo: false,
    size: 'M',
    hasText: false,
  });
  // MODEL_3D items add a filament-colour choice; logo placement uses the
  // shared canvas because the item is FDM-printed then UV-decorated on its
  // flat face - the placement mockup is a producible production step.
  const [model3dOptions, setModel3dOptions] = useState<Model3dCustomization | null>(null);
  const is3d = product?.class === 'MODEL_3D';
  // Clean orthographic render of the model's decoration face, in the chosen
  // filament colour - the 3D design surface (audit G1/G2/G3). A MODEL_3D item
  // NEVER falls back to the scraped marketing photo (audit C16): while the
  // render loads (or if it fails) the buyer designs on the neutral stage,
  // with the status spelled out beside the canvas.
  const [faceSnapshot, setFaceSnapshot] = useState<ModelFaceSnapshot | null>(null);
  const [faceState, setFaceState] = useState<'idle' | 'loading' | 'ready' | 'error'>('idle');
  const filamentColor = model3dOptions?.filament_color ?? DEFAULT_FILAMENT_COLOR;
  const [qty, setQty] = useState(50);
  const [estimate, setEstimate] = useState<{ unit: number; lineTotal: number; currency: string } | null>(null);
  // Deadline-aware delivery: queue-aware window + a "need it by" feasibility check.
  const [lead, setLead] = useState<LeadEstimate | null>(null);
  // Brand kit: only a signed-in buyer (has a company) has one to apply.
  const companyId = useAuthStore((s) => s.user?.company_id ?? null);
  const [brandKit, setBrandKit] = useState<BrandKit | null>(null);

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

  // Load the buyer's brand kit once (if signed in with a company).
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

  // Render (and re-render on colour change) the model-face backdrop for 3D
  // items that stream a model file. The snapshot also carries real-mm face
  // dimensions from the STL geometry, captured into the layout record.
  useEffect(() => {
    if (!product || product.class !== 'MODEL_3D' || !product.has_model || !id) {
      setFaceSnapshot(null);
      setFaceState(product?.class === 'MODEL_3D' ? 'error' : 'idle');
      return;
    }
    let active = true;
    setFaceState('loading');
    renderModelFace(id, filamentColor)
      .then((snapshot) => {
        if (!active) return;
        setFaceSnapshot(snapshot);
        setFaceState('ready');
      })
      .catch(() => {
        if (!active) return;
        setFaceSnapshot(null);
        setFaceState('error');
      });
    return () => {
      active = false;
    };
  }, [product, id, filamentColor]);

  // Delivery window: fetch once the product is known. Queue-depth aware, so a
  // busy floor honestly pushes the date out. Failure is non-fatal (hide it).
  useEffect(() => {
    if (!product) return;
    let active = true;
    api
      .post<LeadEstimate>('/lead-time-estimate', { line_items: [{ product_id: product.id }] })
      .then(({ data }) => {
        if (active) setLead(data);
      })
      .catch(() => {
        if (active) setLead(null);
      });
    return () => {
      active = false;
    };
  }, [product]);

  // Live quote: re-estimate whenever qty, variant, logo band, or captured
  // artwork changes. Event-driven single POST per change - never polled.
  const hasCustomization = logo.hasLogo || logo.hasText || !!artwork;
  const logoSize = logo.hasLogo ? logo.size : null;
  const hasText = logo.hasText;
  useEffect(() => {
    if (!product) return;
    let active = true;
    api
      .post<PriceEstimate>('/price-estimate', {
        line_items: [
          {
            product_id: product.id,
            variant_id: variantId,
            qty,
            has_customization: hasCustomization,
            logo_size: logoSize,
            has_text: hasText,
          },
        ],
      })
      .then(({ data }) => {
        if (!active) return;
        // Line total, NOT data.total - the order total bakes in delivery and
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
  }, [product, variantId, qty, hasCustomization, logoSize, hasText]);

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
      ...(is3d ? (model3dOptions ?? { filament_color: DEFAULT_FILAMENT_COLOR }) : {}),
      ...(artwork?.customization ?? {}),
    };
    // Persist the captured artwork server-side; store the returned ref (not the
    // large data URL) on the cart line. On failure, surface an error and ABORT -
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

          {/* Deadline-aware delivery window + feasibility check */}
          {lead && (
            <Card padding="md" className="flex flex-col gap-3 sm:max-w-lg">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                  <p className="text-sm font-medium text-fg">Estimated delivery</p>
                  <p className="text-sm text-fg-muted">
                    Arrives {fmtDate(lead.earliest)} – {fmtDate(lead.latest)}
                  </p>
                </div>
                {needBy &&
                  (needBy < lead.latest ? (
                    <Badge tone="warning" dot>
                      At risk
                    </Badge>
                  ) : (
                    <Badge tone="success" dot>
                      On track
                    </Badge>
                  ))}
              </div>
              <Input
                type="date"
                label="Need it by (optional)"
                value={needBy}
                min={lead.earliest}
                onChange={(e) => setNeedBy(e.target.value)}
              />
              {needBy && needBy < lead.latest && (
                <p className="text-sm text-warning">
                  Tight for {fmtDate(needBy)}.
                  {lead.rush_available && lead.rush_earliest
                    ? ` Rush can arrive ${fmtDate(lead.rush_earliest)}${
                        lead.rush_fee ? ` (+SGD ${lead.rush_fee.toFixed(2)})` : ''
                      } - ask us to add it.`
                    : ''}
                </p>
              )}
            </Card>
          )}

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

          {/* Configurator - 3D items pick a filament colour, then everyone
              places logo/text on the canvas over the product photo (3D items
              are UV-decorated after printing) */}
          {is3d && <Model3dPersonalizer onChange={setModel3dOptions} />}
          {is3d && faceState === 'loading' && (
            <p className="text-sm text-fg-muted" role="status" aria-live="polite">
              Rendering the product face in your chosen colour…
            </p>
          )}
          {is3d && faceState === 'error' && (
            <p className="text-sm text-warning" role="status">
              3D face preview unavailable - design on the neutral stage; placement is confirmed on
              the formal proof before production.
            </p>
          )}
          <DesignerCanvas
            /* MODEL_3D: face render or neutral stage - never the scraped
               marketing photo as a design surface (audit G1/C16). */
            backgroundUrl={is3d ? (faceSnapshot?.dataUrl ?? null) : product.image_url}
            onCapture={handleCapture}
            onLogoChange={setLogo}
            brandLogo={brandKit?.logo ?? null}
            brandColors={brandKit?.colors ?? []}
            canvasMm={
              faceSnapshot
                ? { width: faceSnapshot.canvasWidthMm, height: faceSnapshot.canvasHeightMm }
                : null
            }
          />

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
                      ? 'Pick a colour, place your logo, then choose “Use this design” - or add to cart plain.'
                      : 'Add a logo, then choose “Use this design”.'}
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
                {logo.hasLogo && (
                  <Badge tone="neutral" size="sm">
                    Logo {logo.size}
                  </Badge>
                )}
                {estimate && (
                  <p className="text-sm text-fg-muted" role="status" aria-live="polite">
                    <span className="font-semibold text-fg">
                      {estimate.currency} {(estimate.lineTotal / qty).toFixed(2)}
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
