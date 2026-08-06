import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useProcurementStore, type BuyListRow } from '../stores/procurementStore';
import { Badge, Button, Card, EmptyState, Skeleton, useToast } from '../ui';
import { Motion, fadeInUp } from '../motion';

type BuyView = 'product' | 'order';

/**
 * The buy link for a row: 3D items point at their source/model page; marketplace
 * blanks prefer the staff-entered affiliate deeplink, falling back to the plain
 * source URL. Null when neither is set (staff see a "no source link" note).
 */
function buyLink(product: BuyListRow['product']): string | null {
  if (product.class === 'MODEL_3D') {
    return product.source_url ?? null;
  }
  return product.affiliate_url ?? product.source_url ?? null;
}

function BuyListSkeleton() {
  return (
    <ul className="flex list-none flex-col gap-3 p-0" aria-hidden="true">
      {Array.from({ length: 3 }).map((_, i) => (
        <li key={i}>
          <Card padding="md" className="flex flex-col gap-3">
            <Skeleton width="40%" height={18} />
            <Skeleton width="100%" height={36} />
          </Card>
        </li>
      ))}
    </ul>
  );
}

export default function ProcurementPage() {
  const { buyList, loading, error, fetchBuyList, markBought, markProductBought } =
    useProcurementStore();
  const { toast } = useToast();
  const [view, setView] = useState<BuyView>('product');
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    void fetchBuyList();
  }, [fetchBuyList]);

  // Group by product (bulk-buy the same blank across orders) or by order
  // (everything one customer needs). Grouping is a display concern only - the
  // rows are the same line items either way.
  const groups = useMemo(() => {
    const map = new Map<string, BuyListRow[]>();
    for (const row of buyList) {
      const key = view === 'product' ? `p:${row.product_id}` : `o:${row.quote_reference ?? row.quote_id}`;
      const bucket = map.get(key);
      if (bucket) bucket.push(row);
      else map.set(key, [row]);
    }
    return Array.from(map.values());
  }, [buyList, view]);

  const onBought = async (lineItemId: number) => {
    if (busy) return;
    setBusy(true);
    try {
      await markBought(lineItemId);
      toast({ title: 'Marked bought', description: 'Bill raised and item sent to production.', tone: 'success' });
    } catch {
      toast({ title: 'Could not mark bought', tone: 'danger' });
    } finally {
      setBusy(false);
    }
  };

  const onProductBought = async (productId: number) => {
    if (busy) return;
    setBusy(true);
    try {
      await markProductBought(productId);
      toast({ title: 'Marked all bought', description: 'Every order for this product was sent to production.', tone: 'success' });
    } catch {
      toast({ title: 'Could not mark bought', tone: 'danger' });
    } finally {
      setBusy(false);
    }
  };

  return (
    <section className="flex flex-col gap-6">
      <Motion variants={fadeInUp} initial="hidden" animate="visible">
        <div className="flex flex-wrap items-center gap-3">
          <h1 className="font-display text-3xl text-fg sm:text-4xl">Buy list</h1>
          {buyList.length > 0 && <Badge tone="brand">{buyList.length} to buy</Badge>}
        </div>
        <p className="mt-1 text-sm text-fg-muted">
          Items to buy for approved orders. Buy the blank, then mark it bought - that raises the bill
          and sends it to the production floor.
        </p>
      </Motion>

      <div className="flex gap-2" role="tablist" aria-label="Buy list grouping">
        <Button
          variant={view === 'product' ? 'primary' : 'outline'}
          aria-pressed={view === 'product'}
          onClick={() => setView('product')}
        >
          By product
        </Button>
        <Button
          variant={view === 'order' ? 'primary' : 'outline'}
          aria-pressed={view === 'order'}
          onClick={() => setView('order')}
        >
          By order
        </Button>
      </div>

      {error && (
        <p className="rounded-md border border-danger/30 bg-danger-bg px-3 py-2 text-sm text-danger" role="alert">
          {error}
        </p>
      )}

      {loading && buyList.length === 0 && <BuyListSkeleton />}

      {!loading && !error && buyList.length === 0 && (
        <EmptyState
          title="Nothing to buy"
          description="Items appear here once an order's artwork and price are both approved."
        />
      )}

      {buyList.length > 0 && (
        <ul className="flex list-none flex-col gap-3 p-0">
          {groups.map((rows) => {
            const first = rows[0];
            const heading =
              view === 'product'
                ? first.product.name
                : `Order ${first.quote_reference ?? first.quote_id}`;
            return (
              <li key={view === 'product' ? `p:${first.product_id}` : `o:${first.quote_reference ?? first.quote_id}`}>
                <Card padding="md" className="flex flex-col gap-3">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="font-display text-lg leading-tight text-fg">{heading}</p>
                    {view === 'product' && (
                      <Button disabled={busy} onClick={() => void onProductBought(first.product_id)}>
                        Mark all bought
                      </Button>
                    )}
                  </div>

                  <ul className="flex list-none flex-col gap-2 p-0">
                    {rows.map((row) => {
                      const href = buyLink(row.product);
                      return (
                        <li
                          key={row.id}
                          className="flex flex-wrap items-center justify-between gap-2 border-t border-border pt-2 first:border-t-0 first:pt-0"
                        >
                          <div className="flex flex-wrap items-center gap-2 text-sm">
                            <span className="font-medium text-fg">
                              {view === 'product' ? (
                                <Link
                                  to={`/orders/${row.quote_reference ?? row.quote_id}`}
                                  className="text-brand underline underline-offset-2"
                                >
                                  {row.quote_reference ?? `#${row.quote_id}`}
                                </Link>
                              ) : (
                                row.product.name
                              )}
                            </span>
                            <span className="text-fg-muted">× {row.qty}</span>
                          </div>
                          <div className="flex items-center gap-2">
                            {href ? (
                              <a
                                href={href}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="text-sm font-medium text-brand underline underline-offset-2"
                              >
                                Buy
                              </a>
                            ) : (
                              <span className="text-sm text-fg-subtle">No source link</span>
                            )}
                            {view === 'order' && (
                              <Button variant="outline" disabled={busy} onClick={() => void onBought(row.id)}>
                                Bought
                              </Button>
                            )}
                          </div>
                        </li>
                      );
                    })}
                  </ul>
                </Card>
              </li>
            );
          })}
        </ul>
      )}
    </section>
  );
}
