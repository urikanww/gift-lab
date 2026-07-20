import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { motion } from 'framer-motion';
import { useCartStore } from '../stores/cartStore';
import { useAuthStore } from '../stores/authStore';
import { useQuoteStore } from '../stores/quoteStore';
import { Button, Card, EmptyState, LinkButton, Modal, Skeleton, cn, useOptionalToast } from '../ui';
import { ErrorState } from '../components/ui/States';
import { CartGlyph, SummaryRow, customizationLabel, optionsLabel } from '../components/cart/CartSummary';
import { safeHref } from '../lib/safeHref';
import { Motion, fadeInUp, staggerItem, useReducedMotionSafe } from '../motion';
import TrackingQr from '../components/TrackingQr';
import ShippingFields, {
  EMPTY_SHIPPING,
  isShippingValid,
  type ShippingFieldsValue,
} from '../components/checkout/ShippingFields';
import NeedByField from '../components/checkout/NeedByField';
import { useLeadTimeEstimate } from '../lib/useLeadTimeEstimate';
import { useSavedAddressStore } from '../stores/savedAddressStore';
import type { Quote, SavedAddress, ShippingAddressInput, CompanySummary, Product } from '../types';

/** Prefill the shipping form from the company's stored default address. */
function companyToShipping(company: CompanySummary | null): ShippingFieldsValue {
  return {
    ...EMPTY_SHIPPING,
    recipient_name: company?.name ?? '',
    line1: company?.address ?? '',
  };
}

/** Prefill the shipping form from a saved address book entry. */
function savedToShipping(a: SavedAddress): ShippingFieldsValue {
  return {
    label: a.label ?? '',
    recipient_name: a.recipient_name,
    phone: a.phone,
    email: a.email ?? '',
    line1: a.line1,
    line2: a.line2 ?? '',
    city: a.city ?? '',
    state: a.state ?? '',
    postal_code: a.postal_code,
    country: a.country || 'SG',
    notes: a.notes ?? '',
  };
}

/** Trim the form values into the payload createQuote sends to the API. */
function toShippingInput(v: ShippingFieldsValue): ShippingAddressInput {
  return {
    recipient_name: v.recipient_name.trim(),
    phone: v.phone.trim(),
    email: v.email?.trim() || null,
    line1: v.line1.trim(),
    line2: v.line2?.trim() || null,
    city: v.city?.trim() || null,
    state: v.state?.trim() || null,
    postal_code: v.postal_code.trim(),
    country: (v.country || 'SG').trim(),
    notes: v.notes?.trim() || null,
  };
}

const DELIVERY_NOTE_RELIABLE =
  'Rough estimate only. Many items fold or stack to shrink the parcel, so our production team confirms the actual delivery fee on your formal quote — it may be lower, and you won’t be charged more without seeing it first.';
const DELIVERY_NOTE_UNRELIABLE =
  'We can’t estimate delivery for these items yet — our production team confirms the actual fee on your formal quote, before any payment. Nothing is charged until you’ve seen it.';

/**
 * Storefront-styled checkout: a thin, celebratory wrapper over the existing B2B
 * quote flow. It does NOT introduce a guest-checkout or direct-order endpoint -
 * "Place order" simply creates the DRAFT quote via useQuoteStore().createQuote,
 * exactly as the cart used to. Login is required first; because the cart is
 * client-side Zustand it survives the /login redirect, so the user returns here
 * and can finish. Everything after DRAFT (send → accept → proofing → pay) stays
 * on /quotes/:id.
 *
 * Layout: order summary + delivery address on the left; a sticky "action rail"
 * on the right holding the estimate totals, the order deadline, and the primary
 * action (place order when signed in, sign-in panel when not).
 */
export default function CheckoutPage() {
  const { lines, neededBy, setNeededBy, estimate, estimating, estimateError, refreshEstimate, clear } =
    useCartStore();
  // Order-level delivery window for the whole cart, so the buyer can set/adjust
  // the deadline here with the same feasibility feedback the designer shows.
  const lead = useLeadTimeEstimate(lines.map((l) => l.product.id));
  const user = useAuthStore((s) => s.user);
  const createQuote = useQuoteStore((s) => s.createQuote);
  const navigate = useNavigate();
  const { toast } = useOptionalToast();

  const [submitting, setSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState<string | null>(null);
  // Delivery explanation collapses by default to keep the rail short; the buyer
  // expands it inline (tap-friendly, unlike a hover tooltip).
  const [deliveryOpen, setDeliveryOpen] = useState(false);
  const [celebrating, setCelebrating] = useState<number | null>(null);
  // The full created quote is retained so the confirmation can surface its
  // signed tracking_link (Track button + QR); celebrating only holds the id.
  const [placedQuote, setPlacedQuote] = useState<Quote | null>(null);

  // One replay token per checkout attempt: a double-click or a retry after a
  // slow network re-sends the same key, so the server returns the original
  // quote instead of creating a duplicate draft (audit A12).
  const idempotencyKey = useRef<string>(crypto.randomUUID());

  const savedAddresses = useSavedAddressStore((s) => s.addresses);
  const fetchSaved = useSavedAddressStore((s) => s.fetch);

  const [selection, setSelection] = useState<string>('company'); // 'company' | 'new' | saved id
  const [shipping, setShipping] = useState<ShippingFieldsValue>(EMPTY_SHIPPING);
  // True once the buyer edits any field: prevents later fetch resolutions /
  // dep churn from re-prefilling and wiping their typed-in address.
  const touchedRef = useRef(false);

  // Refresh the estimate on mount / whenever the cart changes.
  useEffect(() => {
    void refreshEstimate();
  }, [lines, refreshEstimate]);

  useEffect(() => {
    if (!user) return;
    void fetchSaved();
  }, [user, fetchSaved]);

  // Default to the first saved address once addresses arrive (only while still on company default).
  useEffect(() => {
    if (!touchedRef.current && savedAddresses.length > 0 && selection === 'company') {
      setSelection(String(savedAddresses[0].id));
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [savedAddresses]);

  const totalUnits = lines.reduce((sum, l) => sum + l.qty, 0);
  // Delivery destination defaults to a saved address (or the company's stored
  // address), but the buyer can pick another saved address or enter a new one.
  const company = user?.company ?? null;

  // Prefill the form whenever the selection (or the underlying defaults) change.
  useEffect(() => {
    if (touchedRef.current) return;
    if (selection === 'company') {
      setShipping(companyToShipping(company));
    } else if (selection === 'new') {
      setShipping(EMPTY_SHIPPING);
    } else {
      const picked = savedAddresses.find((a) => String(a.id) === selection);
      if (picked) setShipping(savedToShipping(picked));
    }
  }, [selection, company, savedAddresses]);

  // Choosing a ship-to resets the "touched" guard so the form re-prefills from
  // the new selection (an explicit pick is intent to replace the current form).
  const selectShipTo = (value: string) => {
    touchedRef.current = false;
    setSelection(value);
  };

  const placeOrder = async () => {
    // Login gate - should be unreachable while anonymous (button is not
    // rendered), but guard defensively before touching the write path.
    if (!user) {
      navigate('/login', { state: { from: '/checkout' } });
      return;
    }
    if (user.company_id === null) {
      setSubmitError('Your account is not linked to a company. Contact your administrator.');
      return;
    }
    if (!isShippingValid(shipping)) {
      setSubmitError('Please complete the shipping address (recipient, phone, address, postal code).');
      return;
    }
    setSubmitting(true);
    setSubmitError(null);
    const quote = await createQuote(
      user.company_id,
      lines,
      null,
      neededBy,
      idempotencyKey.current,
      toShippingInput(shipping),
    );
    setSubmitting(false);
    if (quote) {
      setPlacedQuote(quote);
      setCelebrating(quote.id);
      // The cart was converted; a future checkout is a new attempt.
      idempotencyKey.current = crypto.randomUUID();
    } else {
      setSubmitError('Could not place your order. Please review your cart and try again.');
    }
  };

  const finishCelebration = () => {
    const id = celebrating;
    setCelebrating(null);
    clear();
    toast({ title: 'Order placed', description: `Quote #${id} is on its way.`, tone: 'success' });
    if (id) navigate(`/quotes/${id}`);
  };

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

  const deliveryNote = estimate?.delivery_reliable ? DELIVERY_NOTE_RELIABLE : DELIVERY_NOTE_UNRELIABLE;

  return (
    <section aria-labelledby="checkout-heading">
      <Motion variants={fadeInUp} initial="hidden" animate="visible" className="mb-6">
        <h1 id="checkout-heading" className="font-display text-3xl text-fg">
          Checkout
        </h1>
        <p className="mt-1 text-sm text-fg-muted">Review your order and place your quote request.</p>
      </Motion>

      <div className="grid gap-6 lg:grid-cols-[1fr_21rem] lg:items-start">
        {/* Left: what's being ordered + where it ships */}
        <div className="flex flex-col gap-6">
          <Card padding="lg" aria-labelledby="items-heading">
            <h2 id="items-heading" className="font-display text-xl text-fg">
              Order summary
            </h2>
            <ul className="mt-4 flex flex-col divide-y divide-border">
              {lines.map((l) => (
                <li key={l.key} className="flex items-center gap-3 py-3 first:pt-0 last:pb-0">
                  <ProductThumb product={l.product} />
                  <div className="min-w-0 flex-1">
                    <h3 className="truncate font-display text-base text-fg">{l.product.name}</h3>
                    <dl className="mt-0.5 flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-fg-muted">
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
                  <span className="shrink-0 text-sm tabular-nums text-fg-muted">× {l.qty}</span>
                </li>
              ))}
            </ul>
          </Card>

          {/* Delivery address - only meaningful once signed in (the picker
              defaults from the authed company + the buyer's saved book). */}
          {user ? (
            <Card padding="lg" aria-labelledby="delivery-heading">
              <h2 id="delivery-heading" className="font-display text-xl text-fg">
                Delivery address
              </h2>
              <div className="mt-4 flex flex-col gap-3">
                <div>
                  <span className="mb-1.5 block text-xs text-fg-subtle">Ship to</span>
                  <div role="radiogroup" aria-label="Ship to" className="flex flex-wrap gap-2">
                    {savedAddresses.map((a) => (
                      <ShipToChip
                        key={a.id}
                        selected={selection === String(a.id)}
                        onClick={() => selectShipTo(String(a.id))}
                      >
                        {a.label || a.line1}
                      </ShipToChip>
                    ))}
                    <ShipToChip selected={selection === 'company'} onClick={() => selectShipTo('company')}>
                      Company default
                    </ShipToChip>
                    <ShipToChip selected={selection === 'new'} onClick={() => selectShipTo('new')}>
                      New address
                    </ShipToChip>
                  </div>
                </div>
                <ShippingFields
                  value={shipping}
                  onChange={(next) => {
                    touchedRef.current = true;
                    setShipping(next);
                  }}
                />
                {!isShippingValid(shipping) && (
                  <p className="text-xs text-fg-subtle" aria-live="polite">
                    Complete recipient, phone, address line 1, and postal code to place the order.
                  </p>
                )}
              </div>
            </Card>
          ) : (
            <Card padding="lg">
              <p className="text-sm text-fg-muted">
                You’ll choose the delivery address after signing in — your company’s saved addresses
                appear here.
              </p>
            </Card>
          )}
        </div>

        {/* Right: sticky action rail. top-20 clears the 64px sticky site header
            (top-6 hid the card behind it); the max-height + scroll keeps a tall
            rail from clipping on short viewports. */}
        <Motion
          variants={staggerItem}
          className="lg:sticky lg:top-20 lg:max-h-[calc(100vh-6rem)] lg:overflow-y-auto"
        >
          <Card padding="lg" aria-labelledby="estimate-heading">
            <h2 id="estimate-heading" className="font-display text-xl text-fg">
              Estimate
            </h2>
            <p className="mt-1 text-sm text-fg-muted">
              {lines.length} {lines.length === 1 ? 'item' : 'items'} · {totalUnits}{' '}
              {totalUnits === 1 ? 'unit' : 'units'}
            </p>

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
                  <div>
                    <div className="flex items-baseline justify-between text-sm">
                      <dt>
                        <button
                          type="button"
                          onClick={() => setDeliveryOpen((o) => !o)}
                          aria-expanded={deliveryOpen}
                          aria-controls="delivery-note"
                          className="-mx-1 flex items-center gap-1 rounded px-1 text-fg-muted transition-colors hover:text-fg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        >
                          Delivery
                          <ChevronIcon
                            className={cn('h-3.5 w-3.5 transition-transform', deliveryOpen && 'rotate-180')}
                          />
                        </button>
                      </dt>
                      <dd className="tabular-nums text-fg">
                        {estimate.delivery_reliable
                          ? `${estimate.currency} ${estimate.delivery.toFixed(2)}`
                          : 'Confirmed on quote'}
                      </dd>
                    </div>
                    {deliveryOpen && (
                      <p id="delivery-note" className="mt-1.5 text-2xs leading-snug text-fg-subtle">
                        {deliveryNote}
                      </p>
                    )}
                  </div>
                  <div className="my-1 border-t border-border" />
                  <div className="flex items-baseline justify-between">
                    <dt className="font-medium text-fg">
                      {estimate.delivery_reliable ? 'Estimated total' : 'Est. total (excl. delivery)'}
                    </dt>
                    <dd className="font-display text-2xl text-fg">
                      {estimate.currency}{' '}
                      {(estimate.delivery_reliable ? estimate.total : estimate.subtotal).toFixed(2)}
                    </dd>
                  </div>
                </dl>
              ) : (
                <p className="text-sm text-fg-muted">Add items to see an estimate.</p>
              )}
            </div>

            {/* Order deadline + delivery-window feasibility, inset under the total. */}
            <div className="mt-4 rounded-lg bg-surface-2 p-3">
              <NeedByField lead={lead} value={neededBy} onChange={setNeededBy} />
            </div>

            <div className="mt-5 border-t border-border pt-5">
              {user ? (
                <>
                  {submitError && (
                    <div className="mb-3">
                      <ErrorState message={submitError} />
                    </div>
                  )}
                  <Button
                    variant="primary"
                    fullWidth
                    onClick={placeOrder}
                    loading={submitting}
                    disabled={submitting}
                  >
                    {submitting ? 'Placing order…' : 'Place order'}
                  </Button>
                  <p className="mt-2 flex items-center justify-center gap-1 text-2xs text-fg-subtle">
                    <LockIcon /> No payment now · formal quote follows
                  </p>
                </>
              ) : (
                <div className="flex flex-col items-center gap-2 text-center">
                  <div className="flex h-10 w-10 items-center justify-center rounded-full bg-brand-100 text-brand-700">
                    <LockIcon />
                  </div>
                  <p className="font-display text-base text-fg">Sign in to place your order</p>
                  <p className="text-xs text-fg-muted">
                    Your cart’s saved — you’ll come right back here to finish.
                  </p>
                  <LinkButton
                    to="/login"
                    state={{ from: '/checkout' }}
                    variant="primary"
                    className="mt-1 w-full"
                  >
                    Log in
                  </LinkButton>
                  <LinkButton
                    to="/register"
                    state={{ from: '/checkout' }}
                    variant="secondary"
                    className="w-full"
                  >
                    Create company account
                  </LinkButton>
                </div>
              )}
            </div>
          </Card>
        </Motion>
      </div>

      {/* Celebratory confirmation before we clear + route to the quote. */}
      <Modal
        open={celebrating !== null}
        onClose={finishCelebration}
        title="Order placed!"
        description="We’ve received your request and will confirm formal pricing shortly."
        hideClose
        size="sm"
        footer={
          <Button variant="primary" onClick={finishCelebration}>
            View quote
          </Button>
        }
      >
        <div className="flex flex-col items-center gap-3 py-2 text-center">
          <SuccessBurst />
          <p className="text-sm text-fg-muted">
            Quote{celebrating ? ` #${celebrating}` : ''} has been created. You can track its status any time
            from your quotes.
          </p>
          {placedQuote?.tracking_link && (
            <div className="mt-2 flex flex-col items-center gap-3 border-t border-border pt-4">
              <a href={placedQuote.tracking_link} className="text-sm font-medium text-primary underline">
                Track your order
              </a>
              <TrackingQr link={placedQuote.tracking_link} />
              <p className="text-xs text-fg-subtle">
                Scan or bookmark to follow this order — no login needed.
              </p>
            </div>
          )}
        </div>
      </Modal>
    </section>
  );
}

/** Small square product photo with a letter fallback (matches the cart's thumb). */
function ProductThumb({ product }: { product: Product }) {
  const [failed, setFailed] = useState(false);
  const href = safeHref(product.image_url);
  if (!href || failed) {
    return (
      <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-md bg-gradient-to-br from-brand-100 to-accent-50 font-display text-base text-brand-700">
        {product.name.charAt(0)}
      </div>
    );
  }
  return (
    <img
      src={href}
      alt=""
      className="h-11 w-11 shrink-0 rounded-md border border-border object-cover"
      loading="lazy"
      referrerPolicy="no-referrer"
      onError={() => setFailed(true)}
    />
  );
}

/** A selectable ship-to option, rendered as a toggle chip (replaces a select). */
function ShipToChip({
  selected,
  onClick,
  children,
}: {
  selected: boolean;
  onClick: () => void;
  children: string;
}) {
  return (
    <button
      type="button"
      role="radio"
      aria-checked={selected}
      onClick={onClick}
      title={children}
      className={cn(
        'inline-flex max-w-[13rem] items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-medium transition-colors',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
        selected
          ? 'border-transparent bg-primary text-primary-fg'
          : 'border-border bg-surface text-fg-muted hover:border-border-strong hover:text-fg',
      )}
    >
      {selected && <CheckIcon />}
      <span className="truncate">{children}</span>
    </button>
  );
}

function CheckIcon() {
  return (
    <svg viewBox="0 0 16 16" className="h-3 w-3 shrink-0" fill="none" aria-hidden="true">
      <path d="M3.5 8.5l3 3 6-7" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  );
}

function ChevronIcon({ className }: { className?: string }) {
  return (
    <svg viewBox="0 0 16 16" className={className} fill="none" aria-hidden="true">
      <path d="M4 6l4 4 4-4" stroke="currentColor" strokeWidth="1.4" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  );
}

function LockIcon() {
  return (
    <svg viewBox="0 0 20 20" className="h-5 w-5" fill="none" aria-hidden="true">
      <rect x="4.5" y="8.5" width="11" height="8" rx="1.5" stroke="currentColor" strokeWidth="1.4" />
      <path d="M7 8.5V6.5a3 3 0 0 1 6 0v2" stroke="currentColor" strokeWidth="1.4" strokeLinecap="round" />
    </svg>
  );
}

/** Small celebratory checkmark that pops in (reduced-motion aware). */
function SuccessBurst() {
  const animate = useReducedMotionSafe();
  return (
    <motion.div
      className="flex h-16 w-16 items-center justify-center rounded-full bg-success-bg text-success"
      initial={animate ? { scale: 0.4, opacity: 0 } : false}
      animate={{ scale: 1, opacity: 1 }}
      transition={{ type: 'spring', stiffness: 480, damping: 18 }}
    >
      <svg viewBox="0 0 24 24" className="h-8 w-8" fill="none" aria-hidden="true">
        <path
          d="M5 13l4 4L19 7"
          stroke="currentColor"
          strokeWidth="2.2"
          strokeLinecap="round"
          strokeLinejoin="round"
        />
      </svg>
    </motion.div>
  );
}
