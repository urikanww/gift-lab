import { useEffect, useState } from 'react';
import api, { apiError } from '../lib/api';
import { Badge, LinkButton, Modal, Spinner } from '../ui';
import { categoryLabel } from '../lib/categories';
import StlModelViewer from './StlModelViewer';
import { classLabel, IpRiskBadge, LicenseTierBadge, PublishBadge } from '../pages/adminProductBadges';
import type { AdminProduct } from '../types';

/**
 * Read-only quick look at a product without leaving the list. Fetches the full
 * admin record; for a MODEL_3D item with a stored mesh it embeds a live 3D
 * preview (staff-gated stream), otherwise the product image.
 */
interface Props {
  productId: number | null;
  isSuperadmin: boolean;
  onClose: () => void;
  /** Where the full editor should return to (router state.from). */
  backTo?: string;
}

function formatDims(dims: AdminProduct['dimensions']): string | null {
  if (!dims) return null;
  const { l, w, h, unit } = dims;
  if (l == null || w == null || h == null) return null;
  return `${l} × ${w} × ${h} ${unit ?? 'mm'}`;
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-baseline justify-between gap-4">
      <dt className="text-fg-subtle">{label}</dt>
      <dd className="text-right font-medium text-fg">{value}</dd>
    </div>
  );
}

export default function ProductQuickView({ productId, isSuperadmin, onClose, backTo }: Props) {
  const [product, setProduct] = useState<AdminProduct | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (productId === null) {
      setProduct(null);
      return;
    }
    let active = true;
    setLoading(true);
    setError(null);
    setProduct(null);
    api
      .get<{ data: AdminProduct }>(`/admin/products/${productId}`)
      .then(({ data }) => {
        if (active) setProduct(data.data);
      })
      .catch((err) => {
        if (active) setError(apiError(err));
      })
      .finally(() => {
        if (active) setLoading(false);
      });
    return () => {
      active = false;
    };
  }, [productId]);

  const is3d = product?.class === 'MODEL_3D' && !!product.has_model;
  const dims = product ? formatDims(product.dimensions) : null;

  return (
    <Modal
      open={productId !== null}
      onClose={onClose}
      title={product?.name ?? 'Quick view'}
      size="lg"
      footer={
        product ? (
          <LinkButton
            to={`/product-admin/${product.id}`}
            onClick={onClose}
            state={backTo ? { from: backTo } : undefined}
          >
            Open full editor
          </LinkButton>
        ) : undefined
      }
    >
      {loading && (
        <div className="flex justify-center py-12">
          <Spinner size="lg" label="Loading product…" />
        </div>
      )}
      {error && <p className="py-6 text-sm text-danger">{error}</p>}

      {product && (
        <div className="flex flex-col gap-4 sm:flex-row sm:items-start">
          <div className="sm:w-1/2">
            {is3d ? (
              <StlModelViewer src={`/admin/products/${product.id}/model`} className="h-64 w-full" />
            ) : product.image_url ? (
              <img
                src={product.image_url}
                alt={product.name}
                className="h-64 w-full rounded-lg bg-surface-2 object-contain"
              />
            ) : (
              <div className="flex h-64 items-center justify-center rounded-lg bg-surface-2 text-sm text-fg-subtle">
                No image
              </div>
            )}
          </div>

          <div className="flex flex-1 flex-col gap-3">
            <div className="flex flex-wrap items-center gap-2">
              <Badge tone="neutral" size="sm">
                {classLabel(product.class)}
              </Badge>
              <PublishBadge state={product.publish_state} />
              <IpRiskBadge product={product} />
              {isSuperadmin && <LicenseTierBadge tier={product.license_tier} />}
            </div>

            <p className="text-lg font-semibold text-fg">
              {product.currency} {Number(product.selling_price).toFixed(2)}
              <span className="ml-2 text-sm font-normal text-fg-subtle">
                cost {product.currency} {Number(product.base_cost).toFixed(2)}
              </span>
            </p>

            <dl className="flex flex-col gap-1.5 text-sm">
              <Row label="Category" value={product.category ? categoryLabel(product.category) : '—'} />
              <Row label="Sold" value={String(product.sold_count)} />
              <Row label="In stock" value={String(product.stock_total)} />
              <Row label="Min order" value={String(product.min_order_qty ?? 1)} />
              {dims && <Row label="Dimensions" value={dims} />}
              {is3d && <Row label="Model parts" value={String(product.model_parts?.length ?? 0)} />}
            </dl>
          </div>
        </div>
      )}
    </Modal>
  );
}
