import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import api, { apiError, ensureCsrf } from '../lib/api';
import { AsyncBoundary } from '../components/ui/States';
import { Badge, Button, Card, Input, Select, Textarea, useToast } from '../ui';
import { Motion, fadeInUp } from '../motion';
import { CATEGORIES } from '../lib/categories';
import Model3dZoneEditor from '../components/Model3dZoneEditor';
import { useAuthStore } from '../stores/authStore';
import type { AdminProduct, AdminVariant, HistoryEntry } from '../types';
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
  const [modelBusy, setModelBusy] = useState(false);
  const [modelError, setModelError] = useState<string | null>(null);

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

  const uploadModel = async (file: File) => {
    setModelBusy(true);
    setModelError(null);
    try {
      await ensureCsrf();
      const form = new FormData();
      form.append('file', file);
      await api.post(`/admin/products/${product.id}/model-file`, form, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      toast({ title: 'Model updated', description: file.name, tone: 'success' });
      onChanged();
    } catch (err) {
      setModelError(apiError(err));
      toast({ title: 'Upload failed', description: apiError(err), tone: 'danger' });
    } finally {
      setModelBusy(false);
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
              <EditableTitle product={product} onChanged={onChanged} editable={!archived} />
              <p className="mt-1 text-sm text-fg">
                <span className="font-medium">
                  {product.currency} {Number(product.selling_price).toFixed(2)}
                </span>
                <span className="text-fg-subtle"> sell · cost {product.currency} {Number(product.base_cost).toFixed(2)}</span>
              </p>
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

      {product.class === 'MODEL_3D' && (
        <Card padding="md" className="flex flex-col gap-3">
          <h3 className="font-display text-lg">Model file</h3>
          <div className="flex flex-col gap-1 text-sm text-fg">
            <p>Mesh: {product.model_file_ref ? product.model_file_ref.split('/').pop() : 'none'}</p>
            <p>Decoration model (GLB): {product.has_glb ? 'present' : 'none'}</p>
          </div>
          <p className="text-sm text-fg-muted">
            Replacing the mesh clears the saved print zone; a .glb only updates the decoration
            preview.
          </p>
          <div className="flex items-center gap-3">
            <input
              type="file"
              accept=".stl,.3mf,.obj,.glb"
              disabled={modelBusy}
              onChange={(e) => {
                const file = e.target.files?.[0];
                e.target.value = '';
                if (file) void uploadModel(file);
              }}
            />
            {modelBusy && <span className="text-sm text-fg-subtle">Uploading&hellip;</span>}
          </div>
          {modelError && <p className="text-sm text-danger">{modelError}</p>}
        </Card>
      )}

      {product.class === 'MODEL_3D' && product.has_model && (
        <Card padding="md" className="flex flex-col gap-3">
          <h3 className="font-display text-lg">Print zone</h3>
          <p className="text-sm text-fg-muted">
            Mark the surface your logo prints on. Auto-detected where possible — click the
            model to reposition, and set the size in millimetres.
          </p>
          <Model3dZoneEditor
            productId={product.id}
            hasGlb={!!product.has_glb}
            initialZone={product.print_zone ?? null}
            onSaved={() => {
              toast({ title: 'Print zone saved', tone: 'success' });
              onChanged();
            }}
          />
        </Card>
      )}

      {/* Variants */}
      <Card padding="lg">
        <h2 className="mb-4 font-display text-xl text-fg">Variants &amp; stock</h2>
        <VariantsSection product={product} onChanged={onChanged} disabled={archived} />
      </Card>

      {/* History */}
      <Card padding="lg">
        <h2 className="mb-4 font-display text-xl text-fg">History</h2>
        <HistorySection productId={product.id} />
      </Card>
    </div>
  );
}

/**
 * Click-to-rename the product title in place. Enter or blur commits the new name
 * via the same PATCH the edit form uses (backend records the rename in history);
 * Escape cancels. Falls back to a plain heading when the product isn't editable
 * (e.g. archived).
 */
function EditableTitle({
  product,
  onChanged,
  editable,
}: {
  product: AdminProduct;
  onChanged: () => void;
  editable: boolean;
}) {
  const { toast } = useToast();
  const [editing, setEditing] = useState(false);
  const [name, setName] = useState(product.name);
  const [saving, setSaving] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);
  // Guards against Enter + the follow-up blur both firing a second PATCH.
  const submitted = useRef<string | null>(null);

  useEffect(() => {
    setName(product.name);
    submitted.current = null;
  }, [product.name]);

  useEffect(() => {
    if (editing) {
      inputRef.current?.focus();
      inputRef.current?.select();
    }
  }, [editing]);

  const commit = async () => {
    const trimmed = name.trim();
    if (!trimmed || trimmed === product.name || trimmed === submitted.current) {
      setEditing(false);
      setName(trimmed || product.name);
      return;
    }
    submitted.current = trimmed;
    setSaving(true);
    try {
      await ensureCsrf();
      await api.patch(`/admin/products/${product.id}`, { name: trimmed });
      toast({ title: 'Renamed', description: trimmed, tone: 'success' });
      setEditing(false);
      onChanged();
    } catch (err) {
      submitted.current = null;
      toast({ title: 'Not renamed', description: apiError(err), tone: 'danger' });
    } finally {
      setSaving(false);
    }
  };

  if (!editable) {
    return <h1 className="font-display text-2xl text-fg">{product.name}</h1>;
  }

  if (editing) {
    return (
      <input
        ref={inputRef}
        value={name}
        disabled={saving}
        aria-label="Product name"
        className="w-full max-w-md border-b border-primary bg-transparent font-display text-2xl text-fg focus:outline-none"
        onChange={(e) => setName(e.target.value)}
        onBlur={() => void commit()}
        onKeyDown={(e) => {
          if (e.key === 'Enter') {
            e.preventDefault();
            void commit();
          } else if (e.key === 'Escape') {
            setName(product.name);
            setEditing(false);
          }
        }}
      />
    );
  }

  return (
    <button
      type="button"
      onClick={() => setEditing(true)}
      title="Click to rename"
      className="group inline-flex items-center gap-2 rounded text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
    >
      <span className="font-display text-2xl text-fg">{product.name}</span>
      <span
        aria-hidden="true"
        className="text-base text-fg-subtle opacity-0 transition-opacity group-hover:opacity-100"
      >
        ✎
      </span>
    </button>
  );
}

function EditForm({ product, onChanged }: { product: AdminProduct; onChanged: () => void }) {
  const { toast } = useToast();
  const isSuperadmin = useAuthStore((s) => s.user?.role === 'superadmin');
  const [description, setDescription] = useState(product.description ?? '');
  const [baseCost, setBaseCost] = useState(String(product.base_cost));
  // Superadmin fixed sell price; blank = dynamic pricing. Only rendered/sent for
  // superadmins (the backend also strips it for anyone else).
  const [priceOverride, setPriceOverride] = useState(
    product.price_override != null ? String(product.price_override) : '',
  );
  const [category, setCategory] = useState(product.category ?? '');
  const [printMethod, setPrintMethod] = useState<string>(product.print_method ?? 'UV');
  const [stockMode, setStockMode] = useState<string>(product.stock_mode ?? 'STOCKED');
  const [allowBackorder, setAllowBackorder] = useState<boolean>(Boolean(product.allow_backorder));
  const [l, setL] = useState(product.dimensions?.l != null ? String(product.dimensions.l) : '');
  const [w, setW] = useState(product.dimensions?.w != null ? String(product.dimensions.w) : '');
  const [h, setH] = useState(product.dimensions?.h != null ? String(product.dimensions.h) : '');
  const [weight, setWeight] = useState(product.weight != null ? String(product.weight) : '');
  const [saving, setSaving] = useState(false);

  const save = async (e: React.FormEvent) => {
    e.preventDefault();
    if (saving) return;
    const cost = Number(baseCost);
    // base_cost 0 is valid: MODEL_3D items are priced dynamically (filament +
    // print), so they carry a zero base cost. Only reject negatives / non-numbers.
    if (!Number.isFinite(cost) || cost < 0) {
      toast({ title: 'Base cost must be zero or a positive number', tone: 'danger' });
      return;
    }
    if (isSuperadmin && priceOverride !== '') {
      const override = Number(priceOverride);
      if (!Number.isFinite(override) || override < 0) {
        toast({ title: 'Price override must be zero or a positive number', tone: 'danger' });
        return;
      }
    }
    // Only send weight / dimensions when the operator actually filled them -
    // sending 0 / {0,0,0} for a blank field would trip the backend's gt:0 rule.
    const payload: Record<string, unknown> = {
      description,
      base_cost: cost,
      category: category || null,
      print_method: printMethod,
      stock_mode: stockMode,
      allow_backorder: allowBackorder,
    };
    if (weight !== '' && Number(weight) > 0) payload.weight = Number(weight);
    if (l !== '' && w !== '' && h !== '') {
      payload.dimensions = { l: Number(l), w: Number(w), h: Number(h) };
    }
    // Superadmin-only: send the override (or null to clear it). Skipped entirely
    // for other staff so a stray value can't be sent.
    if (isSuperadmin) {
      payload.price_override = priceOverride === '' ? null : Number(priceOverride);
    }
    setSaving(true);
    try {
      await ensureCsrf();
      await api.patch(`/admin/products/${product.id}`, payload);
      toast({ title: 'Saved', description: `${product.name} updated.`, tone: 'success' });
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
      <form onSubmit={save} className="flex flex-col gap-4">
        <Textarea
          label="Description"
          value={description}
          onChange={(e) => setDescription(e.target.value)}
          disabled={saving}
        />

        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <Input
            label="Base cost (SGD)"
            type="number"
            step="0.01"
            // 0 is valid for dynamically-priced MODEL_3D items; min 0.01 would
            // fail native form validation and silently block submit for them.
            min="0"
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
          {/* On-demand: only meaningful for STOCKED items. When on, the item can
              be ordered at 0 stock - the shortfall drives on-hand negative and a
              supplier reorder is drafted (the buy-list). */}
          <Select
            label="On-demand (backorder)"
            value={allowBackorder ? 'yes' : 'no'}
            onChange={(e) => setAllowBackorder(e.target.value === 'yes')}
            disabled={saving || stockMode !== 'STOCKED'}
          >
            <option value="no">Off - block at 0 stock</option>
            <option value="yes">On - sell at 0, backorder</option>
          </Select>
        </div>

        {isSuperadmin && (
          <div className="rounded-md border border-border p-3">
            <Input
              label="Price override (SGD)"
              type="number"
              step="0.01"
              min="0"
              placeholder="Dynamic pricing"
              value={priceOverride}
              onChange={(e) => setPriceOverride(e.target.value)}
              disabled={saving}
            />
            <p className="mt-1 text-xs text-fg-subtle">
              Fixed sell price that supersedes the dynamic quote engine (delivery
              is still charged separately). Leave blank for dynamic pricing.
            </p>
            {priceOverride !== '' &&
              Number.isFinite(Number(priceOverride)) &&
              Number(product.base_cost) > 0 &&
              Number(priceOverride) < Number(product.base_cost) && (
                <p className="mt-1 text-xs text-danger">
                  Below base cost ({product.currency} {Number(product.base_cost).toFixed(2)}) - this sells at a loss.
                </p>
              )}
          </div>
        )}

        <div>
          <p className="mb-2 text-sm font-medium text-fg">Dimensions (mm)</p>
          {/* step="any": MODEL_3D dimensions come from STL geometry and are
              fractional (e.g. 126.6mm); a default integer step blocks submit. */}
          <div className="grid grid-cols-3 gap-2">
            <Input label="L (mm)" type="number" step="any" min="0" value={l} onChange={(e) => setL(e.target.value)} disabled={saving} />
            <Input label="W (mm)" type="number" step="any" min="0" value={w} onChange={(e) => setW(e.target.value)} disabled={saving} />
            <Input label="H (mm)" type="number" step="any" min="0" value={h} onChange={(e) => setH(e.target.value)} disabled={saving} />
          </div>
        </div>

        <div>
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
          No variants - not orderable
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

interface HistoryMeta {
  current_page: number;
  last_page: number;
  total: number;
}

const HISTORY_EVENT_LABELS: Record<string, string> = {
  'product.updated': 'Edited',
  'variant.created': 'Variant added',
  'variant.updated': 'Variant updated',
  'product.image_updated': 'Image updated',
  'product.image_removed': 'Image removed',
  'product.archived': 'Archived',
  'product.restored': 'Restored',
  'product.created': 'Created',
};

function historyEventLabel(event: string): string {
  const known = HISTORY_EVENT_LABELS[event];
  if (known) return known;
  const pretty = event.replace(/[._-]+/g, ' ').trim();
  return pretty.charAt(0).toUpperCase() + pretty.slice(1);
}

function HistoryDiff({ entry }: { entry: HistoryEntry }) {
  const oldValues = entry.old_values ?? {};
  const newValues = entry.new_values ?? {};
  const keys = Object.keys(newValues);

  const rows = keys
    .map((key) => ({ key, from: oldValues[key] ?? null, to: newValues[key] ?? null }))
    .filter((row) => row.from !== null || row.to !== null);

  if (rows.length === 0) return null;

  return (
    <ul className="mt-1 flex flex-col gap-0.5">
      {rows.map((row) => (
        <li key={row.key} className="text-xs text-fg-subtle">
          <span className="font-medium text-fg-muted">{row.key}</span>: {String(row.from ?? '-')} &rarr;{' '}
          {String(row.to ?? '-')}
        </li>
      ))}
    </ul>
  );
}

function HistorySection({ productId }: { productId: number }) {
  const [entries, setEntries] = useState<HistoryEntry[]>([]);
  const [meta, setMeta] = useState<HistoryMeta | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setError(null);
    api
      .get<{ data: HistoryEntry[]; meta: HistoryMeta }>(`/admin/products/${productId}/history`)
      .then(({ data }) => {
        if (cancelled) return;
        setEntries(data.data);
        setMeta(data.meta);
      })
      .catch((err) => {
        if (cancelled) return;
        setError(apiError(err));
      })
      .finally(() => {
        if (cancelled) return;
        setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [productId]);

  const loadMore = async () => {
    if (!meta || meta.current_page >= meta.last_page || loadingMore) return;
    setLoadingMore(true);
    try {
      const { data } = await api.get<{ data: HistoryEntry[]; meta: HistoryMeta }>(
        `/admin/products/${productId}/history`,
        { params: { page: meta.current_page + 1 } },
      );
      setEntries((prev) => [...prev, ...data.data]);
      setMeta(data.meta);
    } catch (err) {
      setError(apiError(err));
    } finally {
      setLoadingMore(false);
    }
  };

  if (loading) {
    return <p className="text-sm text-fg-subtle">Loading history…</p>;
  }

  if (error) {
    return <p className="text-sm text-danger">{error}</p>;
  }

  if (entries.length === 0) {
    return <p className="text-sm text-fg-subtle">No changes recorded yet.</p>;
  }

  return (
    <div className="flex flex-col gap-3">
      <ul className="flex flex-col gap-3">
        {entries.map((entry) => (
          <li key={entry.id} className="rounded-md border border-border p-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <span className="text-sm font-medium text-fg">{historyEventLabel(entry.event)}</span>
              <span className="text-xs text-fg-subtle">{new Date(entry.created_at).toLocaleString()}</span>
            </div>
            <p className="mt-0.5 text-xs text-fg-subtle">
              {entry.entity} · {entry.user ?? 'system'}
            </p>
            <HistoryDiff entry={entry} />
          </li>
        ))}
      </ul>
      {meta && meta.last_page > 1 && meta.current_page < meta.last_page && (
        <div>
          <Button variant="outline" size="sm" loading={loadingMore} onClick={() => void loadMore()}>
            Load more
          </Button>
        </div>
      )}
    </div>
  );
}
