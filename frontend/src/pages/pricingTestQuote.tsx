import { useCallback, useEffect, useRef, useState } from 'react';
import api, { apiError } from '../lib/api';
import { Button, Card, Input, Select } from '../ui';

interface ProductOption {
  id: number;
  name: string;
}

interface BreakdownLine {
  name: string;
  qty: number;
  landed_cost: number;
  margin: number;
  print_per_unit: number;
  bulk_discount: number;
  unit_price: number;
  units_total: number;
  customization_flat: number;
  size_surcharge_total: number;
  text_fee_total: number;
  uv_decor_total: number;
  line_total: number;
}

interface Breakdown {
  currency: string;
  lines: BreakdownLine[];
  setup_fee: number;
  subtotal: number;
  delivery_weight_g: number;
  delivery: number;
  total: number;
}

const money = (v: number | string, currency = 'SGD') =>
  `${currency} ${Number(v).toFixed(2)}`;

/** One breakdown row: label + amount, with an optional sign and emphasis. */
function Row({
  label,
  value,
  currency,
  sign,
  strong,
  muted,
}: {
  label: string;
  value: number;
  currency: string;
  sign?: '+' | '−';
  strong?: boolean;
  muted?: boolean;
}) {
  return (
    <div className={`flex items-center justify-between ${strong ? 'border-t border-border pt-2 font-medium text-fg' : muted ? 'text-fg-subtle' : 'text-fg-muted'}`}>
      <dt>
        {sign && <span className="mr-1 text-fg-subtle">{sign}</span>}
        {label}
      </dt>
      <dd className={strong ? 'font-display text-fg' : 'text-fg'}>
        {sign === '−' ? '−' : ''}
        {money(Math.abs(value), currency)}
      </dd>
    </div>
  );
}

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

  const [estimate, setEstimate] = useState<Breakdown | null>(null);
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
      const { data } = await api.post<Breakdown>('/admin/price-breakdown', {
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
            ) : estimate && estimate.lines[0] ? (
              (() => {
                const c = estimate.currency;
                const l = estimate.lines[0];
                return (
                  <dl className="flex flex-col gap-4 text-sm">
                    {/* Per-item build-up */}
                    <div className="flex flex-col gap-1.5">
                      <p className="text-2xs font-semibold uppercase tracking-wide text-fg-subtle">Per item</p>
                      <Row label="Base cost" value={l.landed_cost} currency={c} muted />
                      <Row label="Margin" value={l.margin} currency={c} sign="+" />
                      {l.print_per_unit > 0 && <Row label="Print cost" value={l.print_per_unit} currency={c} sign="+" />}
                      {l.bulk_discount > 0 && <Row label="Bulk discount" value={l.bulk_discount} currency={c} sign="−" />}
                      <Row label="Price per item" value={l.unit_price} currency={c} strong />
                    </div>

                    {/* Line build-up */}
                    <div className="flex flex-col gap-1.5">
                      <p className="text-2xs font-semibold uppercase tracking-wide text-fg-subtle">
                        Line ({l.qty} × item)
                      </p>
                      <Row label={`Items (${l.qty} × ${money(l.unit_price, c)})`} value={l.units_total} currency={c} muted />
                      {l.customization_flat > 0 && <Row label="Customization fee" value={l.customization_flat} currency={c} sign="+" />}
                      {l.size_surcharge_total > 0 && <Row label="Logo surcharge" value={l.size_surcharge_total} currency={c} sign="+" />}
                      {l.text_fee_total > 0 && <Row label="Personalisation" value={l.text_fee_total} currency={c} sign="+" />}
                      {l.uv_decor_total > 0 && <Row label="UV decoration" value={l.uv_decor_total} currency={c} sign="+" />}
                      <Row label="Line total" value={l.line_total} currency={c} strong />
                    </div>

                    {/* Quote total */}
                    <div className="flex flex-col gap-1.5">
                      <p className="text-2xs font-semibold uppercase tracking-wide text-fg-subtle">Quote</p>
                      {estimate.setup_fee > 0 && <Row label="Setup fee" value={estimate.setup_fee} currency={c} sign="+" />}
                      <Row label="Subtotal" value={estimate.subtotal} currency={c} muted />
                      <Row
                        label={`Delivery (${estimate.delivery_weight_g} g)`}
                        value={estimate.delivery}
                        currency={c}
                        sign="+"
                      />
                      <Row label="Total" value={estimate.total} currency={c} strong />
                    </div>
                  </dl>
                );
              })()
            ) : (
              <p className="text-sm text-fg-subtle">Pick a product to see a sample price.</p>
            )}
          </div>
        </div>
      )}
    </Card>
  );
}
