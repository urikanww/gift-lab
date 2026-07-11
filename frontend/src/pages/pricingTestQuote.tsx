import { useCallback, useEffect, useRef, useState } from 'react';
import api, { apiError } from '../lib/api';
import { Button, Card, Input, Select, Tooltip } from '../ui';
import ProductCombobox, { type ProductOption } from '../components/ProductCombobox';

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

/**
 * One breakdown row: label + amount, with an optional sign/emphasis, an ⓘ
 * tooltip explaining the line, and - when it maps to a config knob - a clickable
 * label that jumps to that setting below.
 */
function Row({
  label,
  value,
  currency,
  sign,
  strong,
  muted,
  info,
  target,
  onJump,
}: {
  label: string;
  value: number;
  currency: string;
  sign?: '+' | '−';
  strong?: boolean;
  muted?: boolean;
  info?: string;
  target?: string;
  onJump?: (key: string) => void;
}) {
  const jumpable = target && onJump;
  return (
    <div
      className={`flex items-center justify-between ${strong ? 'border-t border-border pt-2 font-medium text-fg' : muted ? 'text-fg-subtle' : 'text-fg-muted'}`}
    >
      <dt className="flex items-center gap-1.5">
        {sign && <span className="text-fg-subtle">{sign}</span>}
        {jumpable ? (
          <button
            type="button"
            onClick={() => onJump(target)}
            className="text-left underline decoration-dotted decoration-fg-subtle underline-offset-2 hover:text-primary hover:decoration-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring rounded"
            title="Adjust this setting"
          >
            {label}
          </button>
        ) : (
          <span>{label}</span>
        )}
        {info && (
          <Tooltip content={info}>
            <span tabIndex={0} aria-label={`About ${label}`} className="cursor-help text-fg-subtle">
              ⓘ
            </span>
          </Tooltip>
        )}
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
 * staffer can change a config above, re-run, and watch the number move - no
 * need to understand the maths.
 */
export default function TestQuoteCard({ onEditConfig }: { onEditConfig?: (key: string) => void }) {
  const [selectedProduct, setSelectedProduct] = useState<ProductOption | null>(null);
  const productId = selectedProduct?.id ?? null;
  const [qty, setQty] = useState('10');
  const [customized, setCustomized] = useState(false);
  const [logoSize, setLogoSize] = useState('M');
  const [hasText, setHasText] = useState(false);

  const [estimate, setEstimate] = useState<Breakdown | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Seed with the first published product so the panel shows a sample on open;
  // the staffer searches for any other via the combobox (thousands of products).
  useEffect(() => {
    let alive = true;
    void (async () => {
      try {
        const { data } = await api.get<{ data: ProductOption[] }>(
          '/admin/products?publish_state=PUBLISHED&per_page=1',
        );
        if (!alive) return;
        setSelectedProduct((prev) => prev ?? data.data[0] ?? null);
      } catch {
        /* the panel just waits for a manual pick; the editor itself still works */
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

      <div className="mt-4 grid gap-4 md:grid-cols-2">
          <div className="flex flex-col gap-3">
            <ProductCombobox value={selectedProduct} onChange={setSelectedProduct} />
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
                // For 3D items the "base" is really filament + machine time (there
                // is no flat blank cost); for CORE/UV blanks it's the purchase cost.
                const isModel3d = selectedProduct?.class === 'MODEL_3D';
                const baseLabel = isModel3d ? 'Material + machine' : 'Base cost';
                const baseInfo = isModel3d
                  ? 'Filament (grams × rate) + machine time (minutes × rate), from the model estimates. Not a flat cost - edited on the product.'
                  : 'Blank/material cost before margin. Edited on the product, not here.';
                return (
                  <dl className="flex flex-col gap-4 text-sm">
                    {/* Per-item build-up */}
                    <div className="flex flex-col gap-1.5">
                      <p className="text-2xs font-semibold uppercase tracking-wide text-fg-subtle">Per item</p>
                      <Row
                        label={baseLabel}
                        value={l.landed_cost}
                        currency={c}
                        muted
                        info={baseInfo}
                      />
                      <Row
                        label="Margin"
                        value={l.margin}
                        currency={c}
                        sign="+"
                        info="Profit added on top of cost."
                        target="margin.default_pct"
                        onJump={onEditConfig}
                      />
                      {l.print_per_unit > 0 && (
                        <Row
                          label="Print cost"
                          value={l.print_per_unit}
                          currency={c}
                          sign="+"
                          info="Per-item decoration cost by print method."
                          target="print_cost.per_unit"
                          onJump={onEditConfig}
                        />
                      )}
                      {l.bulk_discount > 0 && (
                        <Row
                          label="Bulk discount"
                          value={l.bulk_discount}
                          currency={c}
                          sign="−"
                          info="Discount applied once the order reaches the bulk quantity."
                          target="threshold.bulk_discount_pct"
                          onJump={onEditConfig}
                        />
                      )}
                      <Row
                        label="Price per item"
                        value={l.unit_price}
                        currency={c}
                        strong
                        info="Base + margin + print, minus any bulk discount."
                      />
                    </div>

                    {/* Line build-up */}
                    <div className="flex flex-col gap-1.5">
                      <p className="text-2xs font-semibold uppercase tracking-wide text-fg-subtle">
                        Line ({l.qty} × item)
                      </p>
                      <Row
                        label={`Items (${l.qty} × ${money(l.unit_price, c)})`}
                        value={l.units_total}
                        currency={c}
                        muted
                        info="Price per item × quantity."
                      />
                      {l.customization_flat > 0 && (
                        <Row
                          label="Customization fee"
                          value={l.customization_flat}
                          currency={c}
                          sign="+"
                          info="One-off fee per customized line."
                          target="fee.customization_flat"
                          onJump={onEditConfig}
                        />
                      )}
                      {l.size_surcharge_total > 0 && (
                        <Row
                          label="Logo surcharge"
                          value={l.size_surcharge_total}
                          currency={c}
                          sign="+"
                          info="Per-item charge by logo size (S/M/L)."
                          target="fee.customization_by_size"
                          onJump={onEditConfig}
                        />
                      )}
                      {l.text_fee_total > 0 && (
                        <Row
                          label="Personalisation"
                          value={l.text_fee_total}
                          currency={c}
                          sign="+"
                          info="Per-item fee for names/text."
                          target="fee.customization_per_unit"
                          onJump={onEditConfig}
                        />
                      )}
                      {l.uv_decor_total > 0 && (
                        <Row
                          label="UV decoration"
                          value={l.uv_decor_total}
                          currency={c}
                          sign="+"
                          info="UV print pass applied to a customized 3D item."
                          target="print_cost.per_unit"
                          onJump={onEditConfig}
                        />
                      )}
                      <Row
                        label="Line total"
                        value={l.line_total}
                        currency={c}
                        strong
                        info="Items plus this line's fees."
                      />
                    </div>

                    {/* Quote total */}
                    <div className="flex flex-col gap-1.5">
                      <p className="text-2xs font-semibold uppercase tracking-wide text-fg-subtle">Quote</p>
                      {estimate.setup_fee > 0 && (
                        <Row
                          label="Setup fee"
                          value={estimate.setup_fee}
                          currency={c}
                          sign="+"
                          info="One-off artwork setup fee per order."
                          target="fee.setup_fee"
                          onJump={onEditConfig}
                        />
                      )}
                      <Row
                        label="Subtotal"
                        value={estimate.subtotal}
                        currency={c}
                        muted
                        info="All line totals plus the setup fee."
                      />
                      <Row
                        label={`Delivery (${estimate.delivery_weight_g} g)`}
                        value={estimate.delivery}
                        currency={c}
                        sign="+"
                        info="Shipping by chargeable weight - the greater of actual and volumetric weight."
                        target="delivery.table"
                        onJump={onEditConfig}
                      />
                      <Row
                        label="Total"
                        value={estimate.total}
                        currency={c}
                        strong
                        info="Subtotal plus delivery."
                      />
                    </div>
                  </dl>
                );
              })()
            ) : (
              <p className="text-sm text-fg-subtle">Pick a product to see a sample price.</p>
            )}
          </div>
        </div>
    </Card>
  );
}
