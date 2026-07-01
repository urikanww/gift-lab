import { useCallback, useEffect, useMemo, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import api, { apiError } from '../lib/api';
import { AsyncBoundary } from '../components/ui/States';
import DesignerCanvas, { type CapturedArtwork } from '../components/DesignerCanvas';
import { useCartStore } from '../stores/cartStore';
import { uploadArtwork } from '../lib/uploadArtwork';
import type { Product, Variant } from '../types';

export default function ProductDesignerPage() {
  const { id } = useParams();
  const navigate = useNavigate();
  const addLine = useCartStore((s) => s.addLine);

  const [product, setProduct] = useState<Product | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [variantId, setVariantId] = useState<number | null>(null);
  const [artwork, setArtwork] = useState<CapturedArtwork | null>(null);

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

  const selectedVariant: Variant | null = useMemo(
    () => product?.variants?.find((v) => v.id === variantId) ?? null,
    [product, variantId],
  );

  const [uploading, setUploading] = useState(false);
  const [uploadError, setUploadError] = useState<string | null>(null);

  const addToCart = async () => {
    if (!product) return;
    setUploadError(null);
    const customization = { ...(artwork?.customization ?? {}) };
    // Persist the captured artwork server-side; store the returned ref (not the
    // large data URL) on the cart line. On failure, surface an error and ABORT —
    // previously the failure was swallowed and the line was added with no
    // artwork_ref, silently losing the buyer's design.
    if (artwork?.dataUrl) {
      setUploading(true);
      try {
        customization.artwork_ref = await uploadArtwork(artwork.dataUrl);
      } catch (err) {
        setUploadError(apiError(err) || 'We couldn’t upload your artwork. Please try again.');
        return;
      } finally {
        setUploading(false);
      }
    }
    addLine(product, selectedVariant, customization);
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
        <section className="designer-page">
          <div>
            <h1>{product.name}</h1>
            <p className="muted">{product.description}</p>
            {product.creator_credit && <p className="credit">Design by {product.creator_credit}</p>}

            {product.variants && product.variants.length > 0 && (
              <label className="field">
                Variant
                <select
                  value={variantId ?? ''}
                  onChange={(e) => setVariantId(Number(e.target.value))}
                >
                  {product.variants.map((v) => (
                    <option key={v.id} value={v.id}>
                      {Object.values(v.attributes).join(' / ')} {v.in_stock ? '' : '(made to order)'}
                    </option>
                  ))}
                </select>
              </label>
            )}
          </div>

          <DesignerCanvas onCapture={setArtwork} />

          <div className="designer-page__footer">
            {artwork ? (
              <span className="ok">Design captured ✓</span>
            ) : (
              <span className="muted">Add a logo or text, then “Use this design”.</span>
            )}
            <button type="button" className="btn btn--primary" onClick={addToCart} disabled={!product || uploading}>
              {uploading ? 'Uploading…' : 'Add to cart'}
            </button>
            {uploadError && (
              <p className="error" role="alert">
                {uploadError}
              </p>
            )}
          </div>
        </section>
      )}
    </AsyncBoundary>
  );
}
