import { Fragment } from 'react';
import { Badge } from '../../ui';
import { humanizeState, lineStateTone } from '../../lib/quoteStatus';
import CustomizationPreview from '../CustomizationPreview';
import ProductThumb from '../product/ProductThumb';
import type { LineItem, Quote } from '../../types';

/**
 * The read-only items table and its pricing summary, lifted out of
 * QuoteDetailPage when the staff edit mode landed. Behaviour is unchanged from
 * the page-local versions; the editable counterpart lives in QuoteLineEditor.
 */

/**
 * Buyer-uploaded finished-look lines need their own callout: the artwork is a
 * reference for staff to interpret, not a print-ready design, and production
 * must not treat it as one. Rendered only for that mode.
 */
export function BuyerUploadedNote({
  customization,
}: {
  customization: NonNullable<LineItem['customization']>;
}) {
  const refCount = customization.reference_refs?.length ?? 0;
  return (
    <div className="mt-2 rounded-md border border-warning/30 bg-warning-bg p-2 text-sm">
      <p className="font-medium text-fg">Finished look uploaded — our team proofs this before printing</p>
      {customization.placement_notes && (
        <p className="mt-1 text-fg-muted">Notes: {customization.placement_notes}</p>
      )}
      {refCount > 0 && <p className="mt-1 text-fg-subtle">{refCount} reference image(s) attached</p>}
    </div>
  );
}

export default function QuoteLineItems({ items }: { items: LineItem[] | undefined }) {
  if (!items || items.length === 0) {
    return <p className="px-5 py-6 text-sm text-fg-muted">No items on this quote.</p>;
  }
  return (
    <>
      {/* Desktop table */}
      <table className="hidden w-full text-left text-sm md:table">
        <thead>
          <tr className="border-b border-border text-xs uppercase tracking-wide text-fg-subtle">
            <th scope="col" className="px-5 py-3 font-medium">
              Item
            </th>
            <th scope="col" className="px-5 py-3 text-right font-medium">
              Qty
            </th>
            <th scope="col" className="px-5 py-3 text-right font-medium">
              Unit
            </th>
            <th scope="col" className="px-5 py-3 text-right font-medium">
              Line total
            </th>
            <th scope="col" className="px-5 py-3 font-medium">
              Status
            </th>
          </tr>
        </thead>
        <tbody>
          {items.map((li) => (
            <Fragment key={li.id}>
              <tr
                className={
                  'border-b border-border ' +
                  (li.customization?.mode === 'buyer_uploaded' ? '' : 'last:border-0')
                }
              >
                <td className="px-5 py-4 text-fg">
                  <div className="flex items-start gap-3">
                    <ProductThumb product={li.product} className="h-12 w-12" zoomable />
                    <div className="min-w-0">
                      <span className="block">{li.product?.name ?? `Product #${li.product_id}`}</span>
                      {/* The buyer's design follows the line it belongs to - seeing
                          it here is the assurance that their work made the order. */}
                      <CustomizationPreview
                        customization={li.customization}
                        productName={li.product?.name ?? `Product #${li.product_id}`}
                        productImageUrl={li.product?.image_url}
                      />
                    </div>
                  </div>
                </td>
                <td className="px-5 py-4 text-right tabular-nums text-fg">{li.qty}</td>
                <td className="px-5 py-4 text-right tabular-nums text-fg-muted">
                  {li.currency} {li.unit_price}
                </td>
                <td className="px-5 py-4 text-right tabular-nums text-fg">
                  {li.currency} {li.line_total}
                </td>
                <td className="px-5 py-4">
                  <Badge tone={lineStateTone(li.line_state)} size="sm">
                    {humanizeState(li.line_state)}
                  </Badge>
                </td>
              </tr>
              {li.customization?.mode === 'buyer_uploaded' && (
                <tr className="border-b border-border last:border-0">
                  <td colSpan={5} className="px-5 pb-4">
                    <BuyerUploadedNote customization={li.customization} />
                  </td>
                </tr>
              )}
            </Fragment>
          ))}
        </tbody>
      </table>

      {/* Mobile stacked */}
      <ul className="flex flex-col divide-y divide-border md:hidden">
        {items.map((li) => (
          <li key={li.id} className="flex flex-col gap-2 px-5 py-4">
            <div className="flex items-start justify-between gap-3">
              <div className="flex min-w-0 items-start gap-3">
                <ProductThumb product={li.product} className="h-12 w-12" zoomable />
                <div className="min-w-0">
                  <span className="font-medium text-fg">
                    {li.product?.name ?? `Product #${li.product_id}`}
                  </span>
                  <CustomizationPreview
                    customization={li.customization}
                    productName={li.product?.name ?? `Product #${li.product_id}`}
                    productImageUrl={li.product?.image_url}
                  />
                </div>
              </div>
              <Badge tone={lineStateTone(li.line_state)} size="sm">
                {humanizeState(li.line_state)}
              </Badge>
            </div>
            <div className="flex justify-between text-sm text-fg-muted">
              <span>
                {li.qty} × {li.currency} {li.unit_price}
              </span>
              <span className="tabular-nums text-fg">
                {li.currency} {li.line_total}
              </span>
            </div>
            {li.customization?.mode === 'buyer_uploaded' && (
              <BuyerUploadedNote customization={li.customization} />
            )}
          </li>
        ))}
      </ul>
    </>
  );
}

export function PricingSummary({ quote }: { quote: Quote }) {
  return (
    <div className="border-t border-border bg-surface-2/50 px-5 py-4">
      <dl className="ml-auto flex max-w-xs flex-col gap-2">
        <div className="flex justify-between text-sm">
          <dt className="text-fg-muted">Subtotal</dt>
          <dd className="tabular-nums text-fg">
            {quote.currency} {quote.subtotal}
          </dd>
        </div>
        <div className="flex justify-between text-sm">
          <dt className="text-fg-muted">Delivery</dt>
          <dd className="tabular-nums text-fg">
            {quote.currency} {quote.delivery}
          </dd>
        </div>
        <div className="mt-1 flex items-baseline justify-between border-t border-border pt-2">
          <dt className="font-medium text-fg">Total</dt>
          <dd className="font-display text-xl text-fg">
            {quote.currency} {quote.total}
          </dd>
        </div>
      </dl>
    </div>
  );
}
