import { useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { AnimatePresence, motion } from 'framer-motion';
import { useCartStore } from '../stores/cartStore';
import { Button, Card, EmptyState, LinkButton, Skeleton } from '../ui';
import { ErrorState } from '../components/ui/States';
import {
  Motion,
  fadeInUp,
  springSoft,
  staggerContainer,
  staggerItem,
  useReducedMotionSafe,
} from '../motion';
import type { CartLine } from '../types';

/** Layout-animated exit/enter for cart rows — the signature moment. */
const rowVariants = {
  hidden: { opacity: 0, y: 10 },
  visible: { opacity: 1, y: 0 },
  exit: { opacity: 0, x: -24, transition: { duration: 0.18 } },
};

function customizationLabel(line: CartLine): string {
  const { logo_size, name_text } = line.customization;
  const parts: string[] = [];
  if (logo_size) parts.push(`Logo ${logo_size}`);
  if (name_text) parts.push(`“${name_text}”`);
  return parts.length ? parts.join(' · ') : 'Blank';
}

function optionsLabel(line: CartLine): string {
  return line.variant ? Object.values(line.variant.attributes).join(' / ') : '—';
}

export default function CartPage() {
  const { lines, estimate, estimating, estimateError, updateQty, removeLine, refreshEstimate, clear } =
    useCartStore();
  const navigate = useNavigate();
  const animate = useReducedMotionSafe();

  // Live estimate is event-driven (debounced on cart change) — never polled.
  useEffect(() => {
    const t = setTimeout(() => void refreshEstimate(), 400);
    return () => clearTimeout(t);
  }, [lines, refreshEstimate]);

  const totalUnits = lines.reduce((sum, l) => sum + l.qty, 0);

  if (lines.length === 0) {
    return (
      <Motion variants={fadeInUp} initial="hidden" animate="visible">
        <EmptyState
          icon={<CartGlyph />}
          title="Your cart is empty"
          description="Browse the catalogue and customise a product to start your gift order."
          action={
            <Button variant="primary" onClick={() => navigate('/catalogue')}>
              Browse catalogue
            </Button>
          }
        />
      </Motion>
    );
  }

  return (
    <section aria-labelledby="cart-heading">
      <Motion variants={fadeInUp} initial="hidden" animate="visible" className="mb-6">
        <h1 id="cart-heading" className="font-display text-3xl text-fg">
          Your cart
        </h1>
        <p className="mt-1 text-sm text-fg-muted">
          {lines.length} {lines.length === 1 ? 'item' : 'items'} · {totalUnits}{' '}
          {totalUnits === 1 ? 'unit' : 'units'}
        </p>
      </Motion>

      <div className="grid gap-6 lg:grid-cols-[1fr_20rem] lg:items-start">
        {/* Line items */}
        <Motion
          variants={staggerContainer}
          initial="hidden"
          animate="visible"
          className="flex flex-col gap-3"
        >
          <AnimatePresence initial={false} mode="popLayout">
            {lines.map((l) => (
              <motion.div
                key={l.key}
                layout={animate}
                variants={rowVariants}
                initial="hidden"
                animate="visible"
                exit="exit"
                transition={springSoft}
              >
                <Card padding="none" className="overflow-hidden">
                  <div className="flex flex-col gap-4 p-4 sm:flex-row sm:items-start sm:justify-between">
                    <div className="min-w-0">
                      <h2 className="font-display text-lg text-fg">{l.product.name}</h2>
                      <dl className="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-sm text-fg-muted">
                        <div className="flex gap-1">
                          <dt className="text-fg-subtle">Options:</dt>
                          <dd>{optionsLabel(l)}</dd>
                        </div>
                        <div className="flex gap-1">
                          <dt className="text-fg-subtle">Finish:</dt>
                          <dd>{customizationLabel(l)}</dd>
                        </div>
                      </dl>
                    </div>

                    <div className="flex items-center justify-between gap-4 sm:justify-end">
                      <QuantityControl
                        qty={l.qty}
                        onChange={(next) => updateQty(l.key, next)}
                        label={`Quantity for ${l.product.name}`}
                      />
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => removeLine(l.key)}
                        aria-label={`Remove ${l.product.name} from cart`}
                      >
                        Remove
                      </Button>
                    </div>
                  </div>
                </Card>
              </motion.div>
            ))}
          </AnimatePresence>

          <div className="flex justify-end pt-1">
            <Button variant="ghost" size="sm" onClick={clear}>
              Clear cart
            </Button>
          </div>
        </Motion>

        {/* Summary */}
        <Motion variants={staggerItem} className="lg:sticky lg:top-6">
          <Card padding="lg" aria-labelledby="estimate-heading">
            <h2 id="estimate-heading" className="font-display text-xl text-fg">
              Estimate
            </h2>

            <div className="mt-4" aria-live="polite">
              {estimateError ? (
                <ErrorState message={estimateError} onRetry={refreshEstimate} />
              ) : estimating ? (
                <div className="flex flex-col gap-3">
                  <Skeleton height="1rem" />
                  <Skeleton height="1rem" width="70%" />
                  <Skeleton height="1.5rem" width="55%" />
                </div>
              ) : estimate ? (
                <dl className="flex flex-col gap-3">
                  <SummaryRow label="Subtotal" value={`${estimate.currency} ${estimate.subtotal.toFixed(2)}`} />
                  <SummaryRow label="Delivery" value={`${estimate.currency} ${estimate.delivery.toFixed(2)}`} />
                  <div className="my-1 border-t border-border" />
                  <div className="flex items-baseline justify-between">
                    <dt className="font-medium text-fg">Estimated total</dt>
                    <dd className="font-display text-2xl text-fg">
                      {estimate.currency} {estimate.total.toFixed(2)}
                    </dd>
                  </div>
                </dl>
              ) : (
                <p className="text-sm text-fg-muted">Add items to see an estimate.</p>
              )}
            </div>

            <p className="mt-4 text-xs text-fg-subtle">
              Estimate only. Final pricing is confirmed on your formal quote.
            </p>

            <LinkButton to="/checkout" variant="primary" className="mt-5 w-full">
              Proceed to checkout
            </LinkButton>
          </Card>
        </Motion>
      </div>
    </section>
  );
}

function SummaryRow({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-baseline justify-between text-sm">
      <dt className="text-fg-muted">{label}</dt>
      <dd className="tabular-nums text-fg">{value}</dd>
    </div>
  );
}

/** Accessible +/- quantity stepper wired to the cart store's clamped updateQty. */
function QuantityControl({
  qty,
  onChange,
  label,
}: {
  qty: number;
  onChange: (next: number) => void;
  label: string;
}) {
  return (
    <div
      className="inline-flex items-center rounded-md border border-border-strong bg-surface"
      role="group"
      aria-label={label}
    >
      <StepButton onClick={() => onChange(qty - 1)} disabled={qty <= 1} aria-label="Decrease quantity">
        <span aria-hidden="true">−</span>
      </StepButton>
      <input
        type="number"
        min={1}
        value={qty}
        onChange={(e) => onChange(Number(e.target.value))}
        aria-label={label}
        className="h-9 w-12 border-x border-border bg-transparent text-center text-sm tabular-nums text-fg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset"
      />
      <StepButton onClick={() => onChange(qty + 1)} aria-label="Increase quantity">
        <span aria-hidden="true">+</span>
      </StepButton>
    </div>
  );
}

function StepButton({
  children,
  ...rest
}: React.ButtonHTMLAttributes<HTMLButtonElement>) {
  return (
    <button
      type="button"
      className="flex h-9 w-9 items-center justify-center text-fg-muted transition-colors duration-fast ease-standard hover:text-fg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset disabled:opacity-40 disabled:pointer-events-none"
      {...rest}
    >
      {children}
    </button>
  );
}

function CartGlyph() {
  return (
    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
      <path
        d="M3 4h2l2.4 12.3a1 1 0 0 0 1 .7h8.7a1 1 0 0 0 1-.8L21 8H6"
        stroke="currentColor"
        strokeWidth="1.5"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
      <circle cx="9" cy="20" r="1.2" fill="currentColor" />
      <circle cx="18" cy="20" r="1.2" fill="currentColor" />
    </svg>
  );
}
