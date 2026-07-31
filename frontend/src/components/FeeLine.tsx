import type { ReactNode } from 'react';

/**
 * The personalisation/decoration fee row in an estimate or order summary.
 *
 * The fee is folded into subtotal, so it is surfaced on its own line to
 * reconcile item rows + fee = subtotal (F3/LT16). One component so the cart
 * estimate and the order-detail summary render it identically: it renders
 * nothing when there is no fee, and formats the amount to 2dp so a raw "12"
 * string and a numeric 12 read the same everywhere. Belongs inside a <dl> — it
 * emits a <dt>/<dd> row matching the surrounding SummaryRow markup.
 */
export function FeeLine({
  label = 'Personalisation',
  amount,
  currency,
}: {
  label?: ReactNode;
  /** Fee amount; a string (server money) or number (client estimate). */
  amount: number | string | null | undefined;
  currency: string;
}) {
  const value = typeof amount === 'string' ? Number(amount) : amount ?? 0;
  if (!Number.isFinite(value) || value <= 0) {
    return null;
  }

  return (
    <div className="flex items-baseline justify-between text-sm">
      <dt className="text-fg-muted">{label}</dt>
      <dd className="tabular-nums text-fg">
        {currency} {value.toFixed(2)}
      </dd>
    </div>
  );
}
