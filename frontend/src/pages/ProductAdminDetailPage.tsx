import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import api, { apiError, ensureCsrf } from '../lib/api';
import { AsyncBoundary } from '../components/ui/States';
import { Badge, Button, Card, Input, Select, useToast } from '../ui';
import { Motion, fadeInUp } from '../motion';
import { CATEGORIES } from '../lib/categories';
import { useAuthStore } from '../stores/authStore';
import type { AdminProduct, AdminVariant } from '../types';
import { classLabel, ItemThumb, LicenseTierBadge, PublishBadge } from './adminProductBadges';

export default function ProductAdminDetailPage() {
  const { id } = useParams();
  const [product, setProduct] = useState<AdminProduct | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const { data } = await api.get<{ data: AdminProduct }>(`/admin/products/${id}`);
      setProduct(data.data);
    } catch (err) {
      setError(apiError(err));
    } finally {
      setLoading(false);
    }
  }, [id]);

  useEffect(() => {
    void load();
  }, [load]);

  return (
    <Motion variants={fadeInUp} initial="hidden" animate="visible" className="flex flex-col gap-6">
      <Link to="/product-admin" className="text-sm text-fg-muted hover:text-fg">
        &larr; Back to products
      </Link>

      <AsyncBoundary
        loading={loading}
        error={error}
        isEmpty={!product}
        emptyTitle="Product not found."
        onRetry={load}
      >
        {product && <DetailBody product={product} onChanged={load} />}
      </AsyncBoundary>
    </Motion>
  );
}

function DetailBody({ product, onChanged }: { product: AdminProduct; onChanged: () => void }) {
  const { toast } = useToast();
  const navigate = useNavigate();
  const isSuperadmin = useAuthStore((s) => s.user?.role === 'superadmin');
  const archived = product.archived;
  const isCore = product.class === 'CORE';

  const togglePublish = async () => {
    try {
      await ensureCsrf();
      await api.patch(`/admin/products/${product.id}`, {
        publish_state: product.publish_state === 'PUBLISHED' ? 'PENDING' : 'PUBLISHED',
      });
      toast({ title: 'Saved', tone: 'success' });
      onChanged();
    } catch (err) {
      toast({ title: 'Not saved', description: apiError(err), tone: 'danger' });
    }
  };

  const archive = async () => {
    try {
      await ensureCsrf();
      await api.delete(`/admin/products/${product.id}`);
      toast({ title: 'Product archived', description: product.name, tone: 'success' });
      navigate('/product-admin');
    } catch (err) {
      toast({ title: 'Not archived', description: apiError(err), tone: 'danger' });
    }
  };

  const restore = async () => {
    try {
      await ensureCsrf();
      await api.post(`/admin/products/${product.id}/restore`);
      toast({ title: 'Product restored', description: product.name, tone: 'success' });
      onChanged();
    } catch (err) {
      toast({ title: 'Not restored', description: apiError(err), tone: 'danger' });
    }
  };

  return (
    <div className="flex flex-col gap-6">
      {/* Header */}
      <Card padding="lg">
        <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
          <div className="flex min-w-0 items-start gap-3">
            <ItemThumb name={product.name} imageUrl={product.image_url} />
            <div className="min-w-0">
              <h1 className="font-display text-2xl text-fg">{product.name}</h1>
              <div className="mt-2 flex flex-wrap items-center gap-2">
                <Badge tone="neutral" size="sm">
                  {classLabel(product.class)}
                </Badge>
                <PublishBadge state={product.publish_state} />
                {isSuperadmin && <LicenseTierBadge tier={product.license_tier} />}
                <span className="text-sm text-fg-subtle">{product.sold_count} sold</span>
                {archived && (
                  <Badge tone="danger" size="sm">
                    Archived
                  </Badge>
                )}
              </div>
            </div>
          </div>
          <div className="flex flex-wrap gap-2">
            {!archived && (
              <>
                <Button variant="outline" size="sm" onClick={() => void togglePublish()}>
                  {product.publish_state === 'PUBLISHED' ? 'Set inactive' : 'Publish'}
                </Button>
                <Button variant="danger" size="sm" onClick={() => void archive()}>
                  Archive
                </Button>
              </>
            )}
            {archived && (
              <Button variant="outline" size="sm" onClick={() => void restore()}>
                Restore
              </Button>
            )}
          </div>
        </div>

        {archived && (
          <p className="mt-4 rounded-md border border-danger-bg bg-danger-bg/40 px-3 py-2 text-sm text-danger">
            This product is archived. Restore it to edit or publish.
          </p>
        )}
      </Card>

      {!archived && <EditForm product={product} onChanged={onChanged} />}

      {!archived && <ImageSection product={product} onChanged={onChanged} />}

      {/* Variants */}
      <Card padding="lg">
        <h2 className="mb-4 font-display text-xl text-fg">Variants &amp; stock</h2>
        {isCore ? (
          <VariantsSection product={product} onChanged={onChanged} disabled={archived} />
        ) : (
          <p className="text-sm text-fg-subtle">
            Variants for {classLabel(product.class)} come from the source / catalogue gate.
          </p>
        )}
      </Card>
    </div>
  );
}

function EditForm({ product, onChanged }: { product: AdminProduct; onChanged: () => void }) {
  const { toast } = useToast();
  const [name, setName] = useState(product.name);
  const [description, setDescription] = useState(product.description ?? '');
  const [baseCost, setBaseCost] = useState(String(product.base_cost));
  const [category, setCategory] = useState(product.category ?? '');
  const [printMethod, setPrintMethod] = useState<string>(product.print_method ?? 'UV');
  const [stockMode, setStockMode] = useState<string>(product.stock_mode ?? 'STOCKED');
  const [l, setL] = useState(product.dimensions?.l != null ? String(product.dimensions.l) : '');
  const [w, setW] = useState(product.dimensions?.w != null ? String(product.dimensions.w) : '');
  const [h, setH] = useState(product.dimensions?.h != null ? String(product.dimensions.h) : '');
  const [weight, setWeight] = useState(product.weight != null ? String(product.weight) : '');
  const [saving, setSaving] = useState(false);

  const save = async (e: React.FormEvent) => {
    e.preventDefault();
    if (saving) return;
    const cost = Number(baseCost);
    if (!Number.isFinite(cost) || cost <= 0) {
      toast({ title: 'Base cost must be a positive number', tone: 'danger' });
      return;
    }
    setSaving(true);
    try {
      await ensureCsrf();
      await api.patch(`/admin/products/${product.id}`, {
        name,
        description,
        base_cost: cost,
        category: category || null,
        print_method: printMethod,
        stock_mode: stockMode,
        dimensions: { l: Number(l), w: Number(w), h: Number(h) },
        weight: Number(weight),
      });
      toast({ title: 'Saved', description: `${name} updated.`, tone: 'success' });
      onChanged();
    } catch (err) {
      toast({ title: 'Not saved', description: apiError(err), tone: 'danger' });
    } finally {
      setSaving(false);
    }
  };

  return (
    <Card padding="lg">
      <h2 className="mb-4 font-display text-xl text-fg">Edit details</h2>
      <form onSubmit={save} className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <Input label="Name" value={name} onChange={(e) => setName(e.target.value)} required disabled={saving} />
        <div className="sm:col-span-2">
          <Input
            label="Description"
            value={description}
            onChange={(e) => setDescription(e.target.value)}
            disabled={saving}
          />
        </div>
        <Input
          label="Base cost (SGD)"
          type="number"
          step="0.01"
          min="0.01"
          value={baseCost}
          onChange={(e) => setBaseCost(e.target.value)}
          required
          disabled={saving}
        />
        <Input
          label="Weight (g)"
          type="number"
          step="0.001"
          min="0"
          value={weight}
          onChange={(e) => setWeight(e.target.value)}
          disabled={saving}
        />
        <div className="grid grid-cols-3 gap-2">
          <Input label="L (mm)" type="number" min="0" value={l} onChange={(e) => setL(e.target.value)} disabled={saving} />
          <Input label="W (mm)" type="number" min="0" value={w} onChange={(e) => setW(e.target.value)} disabled={saving} />
          <Input label="H (mm)" type="number" min="0" value={h} onChange={(e) => setH(e.target.value)} disabled={saving} />
        </div>
        <Select label="Category" value={category} onChange={(e) => setCategory(e.target.value)} disabled={saving}>
          <option value="">Uncategorised</option>
          {CATEGORIES.map((c) => (
            <option key={c.key} value={c.key}>
              {c.label}
            </option>
          ))}
        </Select>
        <Select label="Print method" value={printMethod} onChange={(e) => setPrintMethod(e.target.value)} disabled={saving}>
          <option value="UV">UV</option>
          <option value="FDM">FDM</option>
          <option value="RESIN">RESIN</option>
        </Select>
        <Select label="Stock mode" value={stockMode} onChange={(e) => setStockMode(e.target.value)} disabled={saving}>
          <option value="STOCKED">Stocked</option>
          <option value="MAKE_TO_ORDER">Make to order</option>
        </Select>
        <div className="flex items-end">
          <Button type="submit" loading={saving}>
            Save changes
          </Button>
        </div>
      </form>
    </Card>
  );
}

function ImageSection({ product, onChanged }: { product: AdminProduct; onChanged: () => void }) {
  const { toast } = useToast();
  const fileInput = useRef<HTMLInputElement | null>(null);
  const [busy, setBusy] = useState<'upload' | 'remove' | null>(null);

  const upload = async (file: File | undefined) => {
    if (!file) return;
    setBusy('upload');
    try {
      await ensureCsrf();
      const form = new FormData();
      form.append('image', file);
      await api.post(`/admin/products/${product.id}/image`, form, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      toast({ title: 'Image uploaded', description: file.name, tone: 'success' });
      onChanged();
    } catch (err) {
      toast({ title: 'Upload failed', description: apiError(err), tone: 'danger' });
    } finally {
      setBusy(null);
      if (fileInput.current) fileInput.current.value = '';
    }
  };

  const remove = async () => {
    setBusy('remove');
    try {
      await ensureCsrf();
      await api.delete(`/admin/products/${product.id}/image`);
      toast({ title: 'Image removed', tone: 'success' });
      onChanged();
    } catch (err) {
      toast({ title: 'Could not remove image', description: apiError(err), tone: 'danger' });
    } finally {
      setBusy(null);
    }
  };

  return (
    <Card padding="lg">
      <h2 className="mb-4 font-display text-xl text-fg">Image</h2>
      <div className="flex flex-wrap items-center gap-4">
        <ItemThumb name={product.name} imageUrl={product.image_url} />
        <input
          ref={fileInput}
          type="file"
          accept="image/*"
          className="hidden"
          onChange={(e) => void upload(e.target.files?.[0])}
        />
        <Button
          size="sm"
          variant="outline"
          loading={busy === 'upload'}
          disabled={busy !== null}
          onClick={() => fileInput.current?.click()}
        >
          Upload image
        </Button>
        {product.image_url && (
          <Button
            size="sm"
            variant="ghost"
            loading={busy === 'remove'}
            disabled={busy !== null}
            onClick={() => void remove()}
          >
            Remove image
          </Button>
        )}
      </div>
    </Card>
  );
}

function VariantsSection({
  product,
  onChanged,
  disabled,
}: {
  product: AdminProduct;
  onChanged: () => void;
  disabled: boolean;
}) {
  const { toast } = useToast();
  const [variantName, setVariantName] = useState('');
  const [variantStock, setVariantStock] = useState('');
  const [variantDelta, setVariantDelta] = useState('0');
  const [adding, setAdding] = useState(false);
  const variants = product.variants ?? [];

  const addVariant = async (e: React.FormEvent) => {
    e.preventDefault();
    if (adding) return;
    setAdding(true);
    try {
      await ensureCsrf();
      await api.post(`/admin/products/${product.id}/variants`, {
        attributes: { option: variantName },
        stock_on_hand: Number(variantStock),
        price_delta: Number(variantDelta),
      });
      setVariantName('');
      setVariantStock('');
      setVariantDelta('0');
      toast({ title: 'Variant added', description: `${product.name} · ${variantName}`, tone: 'success' });
      onChanged();
    } catch (err) {
      toast({ title: 'Not added', description: apiError(err), tone: 'danger' });
    } finally {
      setAdding(false);
    }
  };

  const updateStock = async (variant: AdminVariant, stock: number) => {
    try {
      await ensureCsrf();
      await api.patch(`/admin/variants/${variant.id}`, { stock_on_hand: stock });
      toast({ title: 'Stock saved', tone: 'success' });
      onChanged();
    } catch (err) {
      toast({ title: 'Not saved', description: apiError(err), tone: 'danger' });
    }
  };

  return (
    <div className="flex flex-col gap-4">
      {variants.length === 0 && (
        <Badge tone="warning" size="sm">
          No variants — not orderable
        </Badge>
      )}
      {variants.length > 0 && (
        <ul className="flex flex-col gap-2">
          {variants.map((v) => (
            <VariantRow key={v.id} variant={v} disabled={disabled} onSaveStock={(stock) => void updateStock(v, stock)} />
          ))}
        </ul>
      )}

      {!disabled && (
        <form onSubmit={addVariant} className="flex flex-wrap items-end gap-2">
          <div className="w-40">
            <Input label="New variant (e.g. Silver)" value={variantName} onChange={(e) => setVariantName(e.target.value)} required disabled={adding} />
          </div>
          <div className="w-32">
            <Input label="Stock on hand" type="number" min="0" value={variantStock} onChange={(e) => setVariantStock(e.target.value)} required disabled={adding} />
          </div>
          <div className="w-32">
            <Input label="Price delta" type="number" step="0.01" value={variantDelta} onChange={(e) => setVariantDelta(e.target.value)} disabled={adding} />
          </div>
          <Button type="submit" size="sm" loading={adding}>
            Add variant
          </Button>
        </form>
      )}
    </div>
  );
}

function VariantRow({
  variant,
  onSaveStock,
  disabled,
}: {
  variant: AdminVariant;
  onSaveStock: (stock: number) => void;
  disabled: boolean;
}) {
  const [stock, setStock] = useState(String(variant.stock_on_hand));
  const label = Object.values(variant.attributes ?? {}).join(' / ') || variant.sku || `#${variant.id}`;

  return (
    <li className="flex flex-wrap items-end gap-2 rounded-md border border-border p-2">
      <span className="min-w-24 text-sm font-medium text-fg">{label}</span>
      <span className="text-xs text-fg-subtle">delta {variant.price_delta}</span>
      <div className="w-28">
        <Input label="Stock" type="number" min="0" value={stock} onChange={(e) => setStock(e.target.value)} disabled={disabled} />
      </div>
      <Button size="sm" variant="outline" disabled={disabled} onClick={() => onSaveStock(Number(stock))}>
        Save stock
      </Button>
    </li>
  );
}
