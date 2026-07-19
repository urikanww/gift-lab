import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { motion } from 'framer-motion';
import { useCartStore } from '../stores/cartStore';
import { useAuthStore } from '../stores/authStore';
import { useQuoteStore } from '../stores/quoteStore';
import { Button, Card, EmptyState, LinkButton, Modal, Skeleton, useOptionalToast } from '../ui';
import { ErrorState } from '../components/ui/States';
import { CartGlyph, SummaryRow, customizationLabel, optionsLabel } from '../components/cart/CartSummary';
import { Motion, fadeInUp, staggerItem, useReducedMotionSafe } from '../motion';
import TrackingQr from '../components/TrackingQr';
import ShippingFields, {
  EMPTY_SHIPPING,
  isShippingValid,
  type ShippingFieldsValue,
} from '../components/checkout/ShippingFields';
import { useSavedAddressStore } from '../stores/savedAddressStore';
import type { Quote, SavedAddress, ShippingAddressInput, CompanySummary } from '../types';

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

/**
 * Storefront-styled checkout: a thin, celebratory wrapper over the existing B2B
 * quote flow. It does NOT introduce a guest-checkout or direct-order endpoint -
 * "Place order" simply creates the DRAFT quote via useQuoteStore().createQuote,
 * exactly as the cart used to. Login is required first; because the cart is
 * client-side Zustand it survives the /login redirect, so the user returns here
 * and can finish. Everything after DRAFT (send → accept → proofing → pay) stays
 * on /quotes/:id.
 */
export default function CheckoutPage() {
  const { lines, neededBy, estimate, estimating, estimateError, refreshEstimate, clear } = useCartStore();
  const user = useAuthStore((s) => s.user);
  const createQuote = useQuoteStore((s) => s.createQuote);
  const navigate = useNavigate();
  const { toast } = useOptionalToast();

  const [submitting, setSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState<string | null>(null);
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
  const neededByLabel = neededBy
    ? new Date(neededBy).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' })
    : null;

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

  return (
    <section aria-labelledby="checkout-heading">
      <Motion variants={fadeInUp} initial="hidden" animate="visible" className="mb-6">
        <h1 id="checkout-heading" className="font-display text-3xl text-fg">
          Checkout
        </h1>
        <p className="mt-1 text-sm text-fg-muted">
          Review your order and place your quote request.
        </p>
      </Motion>

      <div className="grid gap-6 lg:grid-cols-[1fr_20rem] lg:items-start">
        {/* Order details + place-order / login gate */}
        <div className="flex flex-col gap-6">
          <Card padding="lg" aria-labelledby="items-heading">
            <h2 id="items-heading" className="font-display text-xl text-fg">
              Order summary
            </h2>
            <ul className="mt-4 flex flex-col divide-y divide-border">
              {lines.map((l) => (
                <li key={l.key} className="flex flex-col gap-1 py-3 first:pt-0 last:pb-0">
                  <div className="flex items-baseline justify-between gap-4">
                    <h3 className="min-w-0 truncate font-display text-lg text-fg">{l.product.name}</h3>
                    <span className="shrink-0 text-sm tabular-nums text-fg-muted">× {l.qty}</span>
                  </div>
                  <dl className="flex flex-wrap gap-x-4 gap-y-1 text-sm text-fg-muted">
                    <div className="flex gap-1">
                      <dt className="text-fg-subtle">Options:</dt>
                      <dd>{optionsLabel(l)}</dd>
                    </div>
                    <div className="flex gap-1">
                      <dt className="text-fg-subtle">Finish:</dt>
                      <dd>{customizationLabel(l)}</dd>
                    </div>
                  </dl>
                </li>
              ))}
            </ul>
          </Card>

          {/* Delivery destination - read-only, from the company's stored
              address - plus the buyer's chosen need-by date. Only meaningful
              once signed in (address comes from the authed company). */}
          {user && (
            <Card padding="lg" aria-labelledby="delivery-heading">
              <h2 id="delivery-heading" className="font-display text-xl text-fg">
                Delivery
              </h2>
              <dl className="mt-4 flex flex-col gap-4 text-sm">
                <div className="flex flex-col gap-2">
                  <dt className="text-fg-subtle">Ship to</dt>
                  <dd>
                    <div className="flex flex-col gap-2">
                      <label className="flex flex-col gap-1">
                        <span className="sr-only">Ship to</span>
                        <select
                          value={selection}
                          onChange={(e) => {
                            touchedRef.current = false;
                            setSelection(e.target.value);
                          }}
                          className="h-11 rounded-md border border-border bg-surface px-3 text-sm text-fg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        >
                          {savedAddresses.map((a) => (
                            <option key={a.id} value={String(a.id)}>
                              {a.label ? `${a.label} — ${a.line1}` : a.line1}
                            </option>
                          ))}
                          <option value="company">Company default address</option>
                          <option value="new">Enter a new address</option>
                        </select>
                      </label>
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
                  </dd>
                </div>
                <div className="flex flex-col gap-1">
                  <dt className="text-fg-subtle">Need it by</dt>
                  <dd className="text-fg">{neededByLabel ?? 'No deadline set'}</dd>
                </div>
              </dl>
            </Card>
          )}

          {user ? (
            <Card padding="lg" aria-labelledby="place-heading">
              <h2 id="place-heading" className="font-display text-xl text-fg">
                Place your order
              </h2>
              <p className="mt-1 text-sm text-fg-muted">
                We’ll create a draft quote and confirm formal pricing shortly. No payment is taken now.
              </p>

              {submitError && (
                <div className="mt-4">
                  <ErrorState message={submitError} />
                </div>
              )}

              <Button
                variant="primary"
                fullWidth
                className="mt-5"
                onClick={placeOrder}
                loading={submitting}
                disabled={submitting}
              >
                {submitting ? 'Placing order…' : 'Place order'}
              </Button>
            </Card>
          ) : (
            <Card padding="lg" aria-labelledby="login-heading">
              <h2 id="login-heading" className="font-display text-xl text-fg">
                Log in to place your order
              </h2>
              <p className="mt-1 text-sm text-fg-muted">
                Your cart is saved. Sign in to your company account and you’ll come right back here to finish.
              </p>
              <LinkButton
                to="/login"
                state={{ from: '/checkout' }}
                variant="primary"
                className="mt-5 w-full"
              >
                Log in
              </LinkButton>
              <LinkButton
                to="/register"
                state={{ from: '/checkout' }}
                variant="secondary"
                className="mt-3 w-full"
              >
                New here? Create your company account
              </LinkButton>
            </Card>
          )}
        </div>

        {/* Estimate summary */}
        <Motion variants={staggerItem} className="lg:sticky lg:top-6">
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
                  <SummaryRow
                    label="Estimated delivery"
                    value={
                      estimate.delivery_reliable
                        ? `${estimate.currency} ${estimate.delivery.toFixed(2)}`
                        : 'Confirmed on quote'
                    }
                  />
                  <p className="-mt-1 text-2xs leading-snug text-fg-subtle">
                    {estimate.delivery_reliable
                      ? 'Rough estimate only. Many items fold or stack to shrink the parcel, so our production team confirms the actual delivery fee on your formal quote — it may be lower, and you won’t be charged more without seeing it first.'
                      : 'We can’t estimate delivery for these items yet — our production team confirms the actual fee on your formal quote, before any payment. Nothing is charged until you’ve seen it.'}
                  </p>
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

            <p className="mt-4 text-xs text-fg-subtle">
              Estimate only. Final pricing is confirmed on your formal quote.
            </p>
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
