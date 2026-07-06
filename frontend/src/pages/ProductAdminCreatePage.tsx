import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import api, { apiError, ensureCsrf } from '../lib/api';
import { Button, Card, Input, Select, useToast } from '../ui';
import { Motion, fadeInUp } from '../motion';
import { CATEGORIES } from '../lib/categories';

/**
 * Standalone "add a product" page (route /product-admin/new). Creates a CORE
 * blank; on success it hands off to the detail page so the staffer can add
 * variants/stock and an image before publishing.
 */
export default function ProductAdminCreatePage() {
  const { toast } = useToast();
  const navigate = useNavigate();
  const [name, setName] = useState('');
  const [description, setDescription] = useState('');
  const [baseCost, setBaseCost] = useState('');
  const [weight, setWeight] = useState('');
  const [l, setL] = useState('');
  const [w, setW] = useState('');
  const [h, setH] = useState('');
  const [printMethod, setPrintMethod] = useState('UV');
  const [stockMode, setStockMode] = useState('STOCKED');
  const [category, setCategory] = useState('');
  const [publishNow, setPublishNow] = useState(false);
  const [submitting, setSubmitting] = useState(false);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (submitting) return;
    setSubmitting(true);
    try {
      await ensureCsrf();
      const { data } = await api.post<{ data: { id: number } }>('/admin/products', {
        name,
        description: description || undefined,
        base_cost: Number(baseCost),
        weight: Number(weight),
        dimensions: { l: Number(l), w: Number(w), h: Number(h) },
        print_method: printMethod,
        stock_mode: stockMode,
        category: category || undefined,
        publish_state: publishNow ? 'PUBLISHED' : 'PENDING',
      });
      toast({
        title: 'Product created',
        description: 'Add a variant with stock so it can be ordered.',
        tone: 'success',
      });
      navigate(`/product-admin/${data.data.id}`);
    } catch (err) {
      toast({ title: 'Not created', description: apiError(err), tone: 'danger' });
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Motion variants={fadeInUp} initial="hidden" animate="visible" className="flex flex-col gap-6">
      <header className="flex flex-col gap-1">
        <Link to="/product-admin" className="text-sm text-fg-muted hover:text-fg">
          &larr; Back to products
        </Link>
        <h1 className="font-display text-3xl text-fg">Add a product</h1>
        <p className="text-sm text-fg-muted">
          Create an in-house CORE blank. After saving you can add variants, stock, and an image.
        </p>
      </header>

      <Card padding="lg" aria-labelledby="create-product-heading">
        <h2 id="create-product-heading" className="mb-4 font-display text-xl text-fg">
          Details
        </h2>
        <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <Input label="Name" value={name} onChange={(e) => setName(e.target.value)} required disabled={submitting} />
          <div className="sm:col-span-2">
            <Input
              label="Description"
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              disabled={submitting}
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
            disabled={submitting}
          />
          <Input
            label="Weight (g)"
            type="number"
            step="0.001"
            min="0.001"
            value={weight}
            onChange={(e) => setWeight(e.target.value)}
            required
            disabled={submitting}
          />
          <div className="grid grid-cols-3 gap-2">
            <Input label="L (mm)" type="number" min="1" value={l} onChange={(e) => setL(e.target.value)} required disabled={submitting} />
            <Input label="W (mm)" type="number" min="1" value={w} onChange={(e) => setW(e.target.value)} required disabled={submitting} />
            <Input label="H (mm)" type="number" min="1" value={h} onChange={(e) => setH(e.target.value)} required disabled={submitting} />
          </div>
          <Select label="Category" value={category} onChange={(e) => setCategory(e.target.value)} disabled={submitting}>
            <option value="">Uncategorised</option>
            {CATEGORIES.map((c) => (
              <option key={c.key} value={c.key}>
                {c.label}
              </option>
            ))}
          </Select>
          <Select label="Print method" value={printMethod} onChange={(e) => setPrintMethod(e.target.value)} disabled={submitting}>
            <option value="UV">UV</option>
            <option value="FDM">FDM</option>
            <option value="RESIN">RESIN</option>
          </Select>
          <Select label="Stock mode" value={stockMode} onChange={(e) => setStockMode(e.target.value)} disabled={submitting}>
            <option value="STOCKED">Stocked</option>
            <option value="MAKE_TO_ORDER">Make to order</option>
          </Select>
          <label className="flex items-center gap-2 text-sm text-fg">
            <input
              type="checkbox"
              checked={publishNow}
              onChange={(e) => setPublishNow(e.target.checked)}
              className="h-4 w-4"
            />
            Publish immediately
          </label>
          <div className="flex items-end">
            <Button type="submit" loading={submitting}>
              Create product
            </Button>
          </div>
        </form>
      </Card>
    </Motion>
  );
}
