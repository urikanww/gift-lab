import { useCallback, useEffect, useRef, useState } from 'react';
import api, { apiError } from '../lib/api';
import { Button, Card, Input, Select } from '../ui';

interface ProductOption {
  id: number;
  name: string;
}

interface Estimate {
  currency: string;
  lines: { unit_price: number | string }[];
  subtotal: number | string;
  delivery: number | string;
  total: number | string;
}

const money = (v: number | string, currency = 'SGD') =>
  `${currency} ${Number(v).toFixed(2)}`;

/**
 * Live "what does this knob do?" panel for the pricing editor. Prices a sample
 * line against the same public estimate endpoint the storefront uses, so a
 * staffer can change a config above, re-run, and watch the number move — no
 * need to understand the maths.
 */
export default function TestQuoteCard() {
  const [products, setProducts] = useState<ProductOption[]>([]);
  const [productId, setProductId] = useState<number | null>(null);
  const [qty, setQty] = useState('10');
  const [customized, setCustomized] = useState(false);
  const [logoSize, setLogoSize] = useState('M');
  const [hasText, setHasText] = useState(false);

  const [estimate, setEstimate] = useState<Estimate | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Load the published products a quote can actually be built from.
  useEffect(() => {
    let alive = true;
    void (async () => {
      try {
        const { data } = await api.get<{ data: ProductOption[] }>(
          '/admin/products?publish_state=PUBLISHED&per_page=200',
        );
        if (!alive) return;
        setProducts(data.data);
        setProductId((prev) => prev ?? data.data[0]?.id ?? null);
      } catch {
        /* the panel just stays empty; the editor itself still works */
      }
    })();
    return () => {
      alive = false;
    };
  }, []);

  const run = useCallback(async () => {
    if (productId == null) return;
    const n = Number(qty);
    if (!Number.isFinite(n) || n < 1) return;
    setLoading(true);
    setError(null);
    try {
      const { data } = await api.post<Estimate>('/price-estimate', {
        line_items: [
          {
            product_id: productId,
            qty: n,
            has_customization: customized,
            logo_size: customized ? logoSize : null,
            has_text: customized ? hasText : false,
          },
        ],
      });
      setEstimate(data);
    } catch (err) {
      setError(apiError(err));
      setEstimate(null);
    } finally {
      setLoading(false);
    }
  }, [productId, qty, customized, logoSize, hasText]);

  // Re-price shortly after any input settles, so the panel tracks edits live.
  const first = useRef(true);
  useEffect(() => {
    if (productId == null) return;
    const t = setTimeout(() => void run(), first.current ? 0 : 350);
    first.current = false;
    return () => clearTimeout(t);
  }, [run, productId]);

  return (
    <Card padding="lg" aria-label="Test a quote">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="font-display text-xl text-fg">Test a quote</h2>
        <Button size="sm" variant="outline" onClick={() => void run()} loading={loading}>
          Re-estimate
        </Button>
      </div>
      <p className="mt-1 text-sm text-fg-muted">
        Price a sample order with the current settings. Change a number above, then re-estimate to see the effect.
      </p>

      {products.length === 0 ? (
        <p className="mt-4 text-sm text-fg-subtle">Publish a product first to test pricing.</p>
      ) : (
        <div className="mt-4 grid gap-4 md:grid-cols-2">
          <div className="flex flex-col gap-3">
            <Select
              label="Product"
              value={productId ?? ''}
              onChange={(e) => setProductId(Number(e.target.value))}
            >
              {products.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </Select>
            <Input
              label="Quantity"
              type="number"
              min="1"
              value={qty}
              onChange={(e) => setQty(e.target.value)}
            />
            <label className="flex items-center gap-2 text-sm text-fg">
              <input
                type="checkbox"
                className="h-4 w-4"
                checked={customized}
                onChange={(e) => setCustomized(e.target.checked)}
              />
              Add customization
            </label>
            {customized && (
              <div className="flex flex-col gap-3 border-l-2 border-border pl-3">
                <Select label="Logo size" value={logoSize} onChange={(e) => setLogoSize(e.target.value)}>
                  <option value="S">Small</option>
                  <option value="M">Medium</option>
                  <option value="L">Large</option>
                </Select>
                <label className="flex items-center gap-2 text-sm text-fg">
                  <input
                    type="checkbox"
                    className="h-4 w-4"
                    checked={hasText}
                    onChange={(e) => setHasText(e.target.checked)}
                  />
                  Personalised text / names
                </label>
              </div>
            )}
          </div>

          <div className="rounded-lg border border-border bg-surface-2 p-4">
            {error ? (
              <p className="text-sm text-danger" role="alert">
                {error}
              </p>
            ) : estimate ? (
              <dl className="flex flex-col gap-2 text-sm">
                <div className="flex items-center justify-between">
                  <dt className="text-fg-subtle">Price per item</dt>
                  <dd className="font-medium text-fg">
                    {estimate.lines[0] ? money(estimate.lines[0].unit_price, estimate.currency) : '—'}
                  </dd>
                </div>
                <div className="flex items-center justify-between">
                  <dt className="text-fg-subtle">Subtotal</dt>
                  <dd className="font-medium text-fg">{money(estimate.subtotal, estimate.currency)}</dd>
                </div>
                <div className="flex items-center justify-between">
                  <dt className="text-fg-subtle">Delivery</dt>
                  <dd className="font-medium text-fg">{money(estimate.delivery, estimate.currency)}</dd>
                </div>
                <div className="mt-1 flex items-center justify-between border-t border-border pt-2">
                  <dt className="font-medium text-fg">Total</dt>
                  <dd className="font-display text-lg text-fg">{money(estimate.total, estimate.currency)}</dd>
                </div>
              </dl>
            ) : (
              <p className="text-sm text-fg-subtle">Pick a product to see a sample price.</p>
            )}
          </div>
        </div>
      )}
    </Card>
  );
}
