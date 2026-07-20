import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import api, { apiError } from '../lib/api';
import { AsyncBoundary } from '../components/ui/States';
import DesignerCanvas, { type CapturedArtwork, type DesignerCanvasHandle } from '../components/DesignerCanvas';
import Model3dDecalPreview, { type DecalPreviewHandle } from '../components/Model3dDecalPreview';
import Model3dPersonalizer, {
  DEFAULT_FILAMENT_COLOR,
  type Model3dCustomization,
} from '../components/Model3dPersonalizer';
import { renderModelFace, type ModelFaceSnapshot } from '../lib/modelFaceSnapshot';
import { useCartStore } from '../stores/cartStore';
import { uploadArtwork } from '../lib/uploadArtwork';
import type { PriceEstimate, Product, Variant } from '../types';
import { Button, Select, Badge, Card, useToast, cn } from '../ui';
import NeedByField from '../components/checkout/NeedByField';
import { useLeadTimeEstimate } from '../lib/useLeadTimeEstimate';
import { Motion, fadeInUp } from '../motion';
import QuantityStepper from '../components/QuantityStepper';
import FinishedLookUploader, {
  type FinishedLookValue,
} from '../components/FinishedLookUploader';

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
  // Live logo state lifted from the canvas so the price bar reflects the
  // selected size band before the design is captured.
  const [logo, setLogo] = useState<{ hasLogo: boolean; size: string }>({
    hasLogo: false,
    size: 'M',
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
  // Admin-authored decoration zone (model-space mm). When present on a 3D item
  // it drives the live decal preview and constrains the designer's mm mapping
  // to the real print surface; absent, we keep the neutral face-snapshot flow.
  const zone = product?.print_zone ?? null;
  const decalRef = useRef<DecalPreviewHandle>(null);
  // Drag-on-model wiring: the canvas handle lets the 3D preview push placement
  // back into the fabric pad, and the live fabric canvas element feeds a THREE
  // CanvasTexture so the decal refreshes without a per-frame PNG re-export.
  const canvasHandle = useRef<DesignerCanvasHandle>(null);
  const [liveCanvas, setLiveCanvas] = useState<HTMLCanvasElement | null>(null);
  const [decalDirty, setDecalDirty] = useState(0);
  // True logo angle (deg) lifted from the fabric object so the 3D rotate control
  // is a controlled reflection of it - a rotation done on the 2D pad keeps the
  // control in sync instead of leaving it stale and jumping on the next click.
  const [logo3dAngle, setLogo3dAngle] = useState(0);
  const [qty, setQty] = useState(1);
  // Fallback flow: buyers who'd rather hand us a reference of the finished look
  // than lay it out themselves. The designer surface is swapped for an uploader.
  // HIDDEN for now: the upload-finished-look entry point is gated off until the
  // pricing path for buyer-uploaded (no size-band) lines exists. The component +
  // backend stay in place; flip this to re-enable the mode toggle. Mode is pinned
  // to 'designer' while disabled, so the buyer_uploaded branches are unreachable.
  const FINISHED_LOOK_ENABLED = false;
  const [mode, setMode] = useState<'designer' | 'buyer_uploaded'>('designer');
  const [finishedLook, setFinishedLook] = useState<FinishedLookValue | null>(null);
  const [estimate, setEstimate] = useState<{ unit: number; lineTotal: number; currency: string } | null>(null);
  // Deadline-aware delivery: queue-aware window + a "need it by" feasibility check.
  const lead = useLeadTimeEstimate(product ? [product.id] : []);
  // Brand kit: only a signed-in buyer (has a company) has one to apply.

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const { data } = await api.get<{ data: Product }>(`/catalogue/${id}`);
      setProduct(data.data);
      setQty(Math.max(1, data.data.min_order_qty ?? 1));
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
    // Product has no model-version field yet, so cache by product id
    // (pending a real version/updated_at token on Product).
    renderModelFace(id, filamentColor, String(product.id))
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

  // Grab the fabric render surface so the 3D decal can be driven by a live
  // CanvasTexture. DesignerCanvas creates its fabric canvas inside its own
  // effect, which may run AFTER this one, so the handle can be null right after
  // mount - retry on the next frame a few times until it resolves. The first
  // placement change also sets it defensively (see onPlacementChange below).
  useEffect(() => {
    setLiveCanvas(null);
    let tries = 0;
    let raf = 0;
    const grab = () => {
      const el = canvasHandle.current?.getCanvasElement() ?? null;
      if (el) {
        setLiveCanvas(el);
        return;
      }
      if (tries++ < 20) raf = requestAnimationFrame(grab);
    };
    grab();
    return () => cancelAnimationFrame(raf);
  }, [product?.id]);

  // Live quote: re-estimate whenever qty, variant, logo band, or captured
  // artwork changes. Event-driven single POST per change - never polled.
  const hasCustomization = logo.hasLogo || !!artwork;
  const logoSize = logo.hasLogo ? logo.size : null;
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
  }, [product, variantId, qty, hasCustomization, logoSize]);

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
    // Fallback path: the buyer handed us a reference of the finished look rather
    // than laying it out on the canvas. No artwork capture / print-file step -
    // production proofs it before printing.
    if (mode === 'buyer_uploaded') {
      if (!finishedLook || (finishedLook.reference_refs.length === 0 && !finishedLook.logo_ref)) {
        toast({ title: 'Add a reference', description: 'Upload at least one image of the finished look.', tone: 'warning' });
        return;
      }
      addLine(product, selectedVariant, {
        ...(is3d ? { filament_color: filamentColor } : {}),
        mode: 'buyer_uploaded',
        reference_refs: finishedLook.reference_refs,
        artwork_ref: finishedLook.logo_ref ?? undefined,
        placement_notes: finishedLook.placement_notes || null,
      }, qty);
      toast({ title: 'Added to cart', description: product.name, tone: 'success' });
      navigate('/cart');
      return;
    }
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
    // Additive: for a zoned 3D item, also flatten the decal into a UV
    // production print file and store its ref. This never touches artwork_ref
    // (the buyer's proof) and a failure here must not block add-to-cart - the
    // artwork_ref still ships and the print file can be regenerated later.
    if (is3d && zone && artwork?.dataUrl) {
      try {
        const printFile = decalRef.current?.generatePrintFile();
        if (printFile) {
          customization.print_file_ref = await uploadArtwork(printFile);
        }
      } catch (err) {
        console.error('Print-file generation/upload failed; proceeding with artwork_ref only.', err);
      }
    }
    addLine(product, selectedVariant, customization, qty);
    toast({ title: 'Added to cart', description: product.name, tone: 'success' });
    navigate('/cart');
  };

  const minQty = Math.max(1, product?.min_order_qty ?? 1);
  const hasReference =
    !!finishedLook && (finishedLook.reference_refs.length > 0 || !!finishedLook.logo_ref);

  // Quantity + live price + primary CTA, rendered once and reused in the
  // desktop rail and the mobile sticky bar so the two never diverge.
  const purchaseControls = (
    <>
      <div className="flex items-center gap-2 text-sm">
        {mode === 'buyer_uploaded' ? (
          hasReference ? (
            <Badge tone="success" dot size="md">
              Reference attached
            </Badge>
          ) : (
            <span className="text-fg-muted">Upload a reference of the finished look.</span>
          )
        ) : artwork ? (
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
      <div>
        <span className="mb-1 block text-2xs font-medium text-fg-subtle">Quantity</span>
        <QuantityStepper value={qty} min={minQty} onChange={setQty} />
        {minQty > 1 && (
          <p className="mt-1 text-2xs text-fg-subtle">Min order {minQty}</p>
        )}
      </div>
      {mode === 'designer' && logo.hasLogo && (
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
    </>
  );

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
          className="mx-auto flex w-full flex-col gap-4"
        >
          {/* Compact header - delivery moved into the purchase dock below so
              the customization surface can run full width. */}
          <header className="flex flex-col gap-2">
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
            {product.description && (
              <p className="line-clamp-2 max-w-3xl text-sm text-fg-muted">{product.description}</p>
            )}
            {product.creator_credit && (
              <p className="text-sm text-fg-subtle">Design by {product.creator_credit}</p>
            )}
          </header>

          {/* Design inputs that drive the preview (mode, variant, filament) sit
              above the full-width customization surface, so a colour change is
              visible on the canvas without scrolling past it. */}
          <div className="flex flex-col gap-4 sm:flex-row sm:flex-wrap sm:items-start">
            {/* Customization mode toggle (hidden until buyer-uploaded pricing exists) */}
            {FINISHED_LOOK_ENABLED && (
              <div
                className="flex overflow-hidden rounded-md border border-border text-sm"
                role="radiogroup"
                aria-label="Customization mode"
              >
                <button
                  type="button"
                  role="radio"
                  aria-checked={mode === 'designer'}
                  onClick={() => setMode('designer')}
                  className={cn(
                    'flex-1 px-3 py-2',
                    mode === 'designer' ? 'bg-primary text-primary-fg' : 'text-fg-muted hover:text-fg',
                  )}
                >
                  Design here
                </button>
                <button
                  type="button"
                  role="radio"
                  aria-checked={mode === 'buyer_uploaded'}
                  onClick={() => setMode('buyer_uploaded')}
                  className={cn(
                    'flex-1 px-3 py-2',
                    mode === 'buyer_uploaded'
                      ? 'bg-primary text-primary-fg'
                      : 'text-fg-muted hover:text-fg',
                  )}
                >
                  Upload finished look
                </button>
              </div>
            )}

            {/* Variant picker */}
            {product.variants && product.variants.length > 0 && (
              <Card padding="md" className="flex flex-col gap-3 sm:max-w-xs">
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

            {/* 3D items pick a filament colour (applies in both modes) */}
            {is3d && <Model3dPersonalizer onChange={setModel3dOptions} />}
          </div>

          {/* Full-width customization surface - the hero of the page. */}
          <div className="flex min-w-0 flex-col gap-3">
              {mode === 'designer' ? (
                <>
                  {is3d && faceState === 'loading' && (
                    <p className="text-sm text-fg-muted" role="status" aria-live="polite">
                      Rendering the product face in your chosen colour…
                    </p>
                  )}
                  {is3d && faceState === 'error' && (
                    <p className="text-sm text-warning" role="status">
                      3D face preview unavailable - design on the neutral stage; placement is
                      confirmed on the formal proof before production.
                    </p>
                  )}
                  {/* Live 3D decal preview: an ADDITIONAL view showing the
                      captured artwork projected on the real mesh over the print
                      zone. Only when an admin zone exists; otherwise the neutral
                      face-snapshot flow stands alone unchanged. */}
                  {is3d && zone && (
                    <Model3dDecalPreview
                      ref={decalRef}
                      productKey={id!}
                      filamentColor={filamentColor}
                      zone={zone}
                      artworkDataUrl={artwork?.dataUrl ?? null}
                      interactive={is3d && !!zone}
                      liveCanvas={is3d && zone ? liveCanvas : null}
                      dirtyTick={decalDirty}
                      angle={logo3dAngle}
                      onDragPlacement={(fu, fv) => {
                        canvasHandle.current?.setLogoFraction(fu, fv);
                        setDecalDirty((n) => n + 1);
                      }}
                      onRotate={(deg) => {
                        canvasHandle.current?.setLogoAngle(deg);
                        // setLogoAngle doesn't emit onPlacementChange, so lift the
                        // angle here to keep the controlled rotate control in sync
                        // (deg is already wrapped into [0,360) by the control).
                        setLogo3dAngle(deg);
                        setDecalDirty((n) => n + 1);
                      }}
                    />
                  )}
                  <DesignerCanvas
                    ref={canvasHandle}
                    /* Big base so the preview fills the full-width row (capped to
                       the available column width); 1000x760 matches the model
                       face-render aspect exactly. */
                    width={1000}
                    height={760}
                    /* MODEL_3D: face render or neutral stage - never the scraped
                       marketing photo as a design surface (audit G1/C16). */
                    backgroundUrl={is3d ? (faceSnapshot?.dataUrl ?? null) : product.image_url}
                    onPlacementChange={(p) => {
                      if (!liveCanvas) setLiveCanvas(canvasHandle.current?.getCanvasElement() ?? null);
                      setLogo3dAngle(p.angle);
                      setDecalDirty((n) => n + 1);
                    }}
                    onCapture={handleCapture}
                    onLogoChange={setLogo}
                    canvasMm={
                      // The real zone footprint wins so mm-mapping matches the
                      // print surface; fall back to the face-snapshot footprint.
                      zone
                        ? { width: zone.width_mm, height: zone.height_mm }
                        : faceSnapshot
                          ? { width: faceSnapshot.canvasWidthMm, height: faceSnapshot.canvasHeightMm }
                          : null
                    }
                  />
                </>
              ) : (
                <Card padding="md" className="flex flex-col gap-3">
                  <div>
                    <p className="text-sm font-medium text-fg">Upload the finished look</p>
                    <p className="text-sm text-fg-muted">
                      Send us reference image(s), your logo, and where it goes - we lay it out and
                      proof it before printing.
                    </p>
                  </div>
                  <FinishedLookUploader onChange={setFinishedLook} />
                </Card>
              )}
            </div>

          {/* Purchase footer: delivery window + quantity + live price + CTA,
              in-flow below the full-width customization surface so it never
              floats over the design controls. */}
          <div>
            <div className="flex flex-col gap-4 rounded-lg border border-border bg-surface p-4 shadow-sm lg:flex-row lg:items-end lg:justify-between">
              {/* Deadline-aware delivery window + feasibility check (shared with
                  checkout so both read/write the same cart deadline). */}
              <div className="lg:max-w-xs">
                <NeedByField lead={lead} value={needBy} onChange={setNeedBy} />
              </div>

              {/* Quantity + live price + primary CTA */}
              <div className="flex flex-col gap-3 lg:w-80 lg:shrink-0">
                {purchaseControls}
              </div>
            </div>
          </div>
        </Motion>
      )}
    </AsyncBoundary>
  );
}
