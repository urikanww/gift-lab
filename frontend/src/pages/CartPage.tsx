import { useEffect, useState } from 'react';
import { AnimatePresence, motion } from 'framer-motion';
import { useCartStore } from '../stores/cartStore';
import { Button, Card, EmptyState, LinkButton, Skeleton, cn } from '../ui';
import { ErrorState } from '../components/ui/States';
import { CartGlyph, SummaryRow, customizationLabel, optionsLabel } from '../components/cart/CartSummary';
import ImageLightbox from '../components/ImageLightbox';
import { safeHref } from '../lib/safeHref';
import { fetchArtworkPreviewUrl } from '../lib/uploadArtwork';
import type { Customization, Product } from '../types';
import {
  Motion,
  fadeInUp,
  springSoft,
  staggerContainer,
  staggerItem,
  useReducedMotionSafe,
} from '../motion';

/** Layout-animated exit/enter for cart rows - the signature moment. */
const rowVariants = {
  hidden: { opacity: 0, y: 10 },
  visible: { opacity: 1, y: 0 },
  exit: { opacity: 0, x: -24, transition: { duration: 0.18 } },
};

export default function CartPage() {
  const { lines, estimate, estimating, estimateError, updateQty, removeLine, refreshEstimate, clear } =
    useCartStore();
  const animate = useReducedMotionSafe();

  // Live estimate is event-driven (debounced on cart change) - never polled.
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
            <LinkButton to="/products" variant="primary">
              Browse products
            </LinkButton>
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
                    <div className="flex min-w-0 gap-3">
                      <ProductThumb product={l.product} />
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
                        <CustomizationPreview
                          customization={l.customization}
                          productName={l.product.name}
                        />
                      </div>
                    </div>

                    <div className="flex items-center justify-between gap-4 sm:justify-end">
                      <QuantityControl
                        qty={l.qty}
                        min={l.product.min_order_qty ?? 1}
                        onChange={(next) => updateQty(l.key, next)}
                        label={`Quantity for ${l.product.name}`}
                      />
                      <Button
                        variant="ghost"
                        size="sm"
                        className="min-h-[44px]"
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
            <Button variant="ghost" size="sm" className="min-h-[44px]" onClick={clear}>
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

/** Square product photo with a letter fallback. */
function ProductThumb({ product }: { product: Product }) {
  const [failed, setFailed] = useState(false);
  const href = safeHref(product.image_url);
  if (!href || failed) {
    return (
      <div className="flex h-16 w-16 shrink-0 items-center justify-center rounded-md bg-gradient-to-br from-brand-100 to-accent-50 font-display text-xl text-brand-700">
        {product.name.charAt(0)}
      </div>
    );
  }
  return (
    <img
      src={href}
      alt=""
      className="h-16 w-16 shrink-0 rounded-md border border-border object-cover"
      loading="lazy"
      referrerPolicy="no-referrer"
      onError={() => setFailed(true)}
    />
  );
}

/** The saved design/reference ref to preview, if any. */
function customizationImageRef(c?: Customization | null): string | null {
  if (!c) return null;
  if (c.artwork_ref) return c.artwork_ref;
  if (c.reference_refs && c.reference_refs.length > 0) return c.reference_refs[0];
  return null;
}

/**
 * Shows the buyer's saved customization (their captured design or reference
 * image) as a thumbnail that opens a zoom viewer - visibility + assurance that
 * what they laid out is on the order. Renders nothing for plain lines.
 */
function CustomizationPreview({
  customization,
  productName,
}: {
  customization?: Customization | null;
  productName: string;
}) {
  const ref = customizationImageRef(customization);
  const [url, setUrl] = useState<string | null>(null);
  const [open, setOpen] = useState(false);

  useEffect(() => {
    if (!ref) {
      setUrl(null);
      return;
    }
    // A ready http(s) URL is used directly; a private storage ref is exchanged
    // for a short-lived signed preview URL.
    if (/^https?:\/\//i.test(ref)) {
      setUrl(ref);
      return;
    }
    let active = true;
    fetchArtworkPreviewUrl(ref).then((u) => {
      if (active) setUrl(u);
    });
    return () => {
      active = false;
    };
  }, [ref]);

  if (!ref || !url) return null;

  return (
    <>
      <button
        type="button"
        onClick={() => setOpen(true)}
        className={cn(
          'mt-2 inline-flex items-center gap-2 rounded-md border border-border bg-surface p-1 pr-2.5 text-left',
          'transition-colors hover:border-primary/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
        )}
        aria-label={`Preview your design for ${productName}`}
      >
        <img
          src={url}
          alt=""
          className="h-12 w-12 rounded bg-[repeating-conic-gradient(var(--color-surface-2)_0%_25%,var(--color-surface)_0%_50%)] bg-[length:12px_12px] object-contain"
        />
        <span className="text-xs font-medium text-fg">Your design</span>
      </button>
      <ImageLightbox src={url} alt={`${productName} customization`} open={open} onClose={() => setOpen(false)} />
    </>
  );
}

/** Accessible +/- quantity stepper wired to the cart store's clamped updateQty. */
function QuantityControl({
  qty,
  min = 1,
  onChange,
  label,
}: {
  qty: number;
  min?: number;
  onChange: (next: number) => void;
  label: string;
}) {
  return (
    <div
      className="inline-flex items-center rounded-md border border-border-strong bg-surface"
      role="group"
      aria-label={label}
    >
      <StepButton onClick={() => onChange(qty - 1)} disabled={qty <= min} aria-label="Decrease quantity">
        <span aria-hidden="true">−</span>
      </StepButton>
      <input
        type="number"
        min={min}
        value={qty}
        onChange={(e) => onChange(Number(e.target.value))}
        aria-label={label}
        className="h-11 w-12 border-x border-border bg-transparent text-center text-sm tabular-nums text-fg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset"
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
      className="flex h-11 w-11 items-center justify-center text-fg-muted transition-colors duration-fast ease-standard hover:text-fg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset disabled:opacity-40 disabled:pointer-events-none"
      {...rest}
    >
      {children}
    </button>
  );
}
