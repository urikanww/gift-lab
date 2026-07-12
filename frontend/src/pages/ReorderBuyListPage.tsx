import { useCallback, useEffect, useState } from 'react';
import api, { apiError, ensureCsrf } from '../lib/api';
import { AsyncBoundary } from '../components/ui/States';
import { Badge, Button, Card, useToast } from '../ui';
import { Motion, fadeInUp } from '../motion';
import type { AdminReorder } from '../types';

/**
 * The buy-list: open supplier reorder drafts raised when a variant falls below
 * threshold or a backorder drives on-hand negative. Staff buy the blank from the
 * affiliate source, then mark it received (which restocks through the ledger).
 */
export default function ReorderBuyListPage() {
  const { toast } = useToast();
  const [reorders, setReorders] = useState<AdminReorder[] | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [receiving, setReceiving] = useState<number | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const { data } = await api.get<{ data: AdminReorder[] }>('/admin/supplier-reorders');
      setReorders(data.data);
    } catch (err) {
      setError(apiError(err));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const receive = async (r: AdminReorder) => {
    if (receiving !== null) return;
    setReceiving(r.id);
    try {
      await ensureCsrf();
      await api.post(`/admin/supplier-reorders/${r.id}/receive`);
      toast({ title: 'Marked received', description: r.item, tone: 'success' });
      await load();
    } catch (err) {
      toast({ title: 'Could not receive', description: apiError(err), tone: 'danger' });
    } finally {
      setReceiving(null);
    }
  };

  return (
    <section className="flex flex-col gap-6">
      <Motion variants={fadeInUp} initial="hidden" animate="visible">
        <div className="flex flex-wrap items-center gap-3">
          <h1 className="font-display text-3xl text-fg sm:text-4xl">Buy-list</h1>
          {reorders && reorders.length > 0 && <Badge tone="warning">{reorders.length} open</Badge>}
        </div>
        <p className="mt-1 text-sm text-fg-muted">
          Blanks to reorder - raised when stock falls below threshold or a backorder sells at zero.
          Buy from the source, then mark received to restock.
        </p>
      </Motion>

      <AsyncBoundary
        loading={loading}
        error={error}
        isEmpty={(reorders ?? []).length === 0}
        emptyTitle="Nothing to reorder."
        onRetry={load}
      >
        <ul className="flex list-none flex-col gap-3 p-0">
          {(reorders ?? []).map((r) => (
              <li key={r.id}>
                <Card padding="md" className="flex flex-col gap-3">
                  <div className="flex flex-wrap items-start justify-between gap-2">
                    <div>
                      <p className="font-display text-lg leading-tight text-fg">{r.item || 'Item'}</p>
                      <p className="text-sm text-fg-muted">
                        {r.sku ? `SKU ${r.sku} · ` : ''}Reorder #{r.id}
                      </p>
                    </div>
                    <Badge tone={r.kind === 'variant' ? 'brand' : 'neutral'}>{r.kind}</Badge>
                  </div>

                  <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm sm:grid-cols-4">
                    <div>
                      <dt className="text-fg-subtle">Reorder qty</dt>
                      <dd className="font-medium text-fg">{r.qty}</dd>
                    </div>
                    <div>
                      <dt className="text-fg-subtle">On hand</dt>
                      <dd className={r.stock_on_hand != null && r.stock_on_hand < 0 ? 'font-medium text-danger' : 'font-medium text-fg'}>
                        {r.stock_on_hand ?? '-'}
                      </dd>
                    </div>
                    <div>
                      <dt className="text-fg-subtle">State</dt>
                      <dd className="font-medium text-fg">{r.state}</dd>
                    </div>
                    <div className="col-span-2 sm:col-span-1">
                      <dt className="text-fg-subtle">Source</dt>
                      <dd className="flex flex-wrap gap-2">
                        {(r.source_links ?? []).length > 0 ? (
                          (r.source_links ?? []).map((l, i) => (
                            <a
                              key={l.url}
                              href={l.url}
                              target="_blank"
                              rel="noopener noreferrer"
                              className={i === 0 ? 'font-medium text-primary underline' : 'text-fg-muted underline'}
                            >
                              {l.label}
                              {l.price != null ? ` · ${l.currency} ${l.price}` : ''}
                            </a>
                          ))
                        ) : r.source_url ? (
                          <a href={r.source_url} target="_blank" rel="noopener noreferrer" className="text-primary underline">
                            Buy
                          </a>
                        ) : (
                          <span className="text-fg-muted">-</span>
                        )}
                      </dd>
                      {(r.source_links ?? []).length > 0 && (
                        <p className="mt-1 text-xs text-fg-subtle">Prices indicative — re-check stock &amp; price on the listing before buying.</p>
                      )}
                    </div>
                  </dl>

                  <div className="border-t border-border pt-3">
                    <Button
                      variant="outline"
                      loading={receiving === r.id}
                      disabled={receiving !== null}
                      onClick={() => void receive(r)}
                    >
                      Mark received
                    </Button>
                  </div>
                </Card>
              </li>
          ))}
        </ul>
      </AsyncBoundary>
    </section>
  );
}
