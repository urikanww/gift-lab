import { useCallback, useEffect, useState } from 'react';
import api, { apiError, ensureCsrf } from '../lib/api';
import { AsyncBoundary } from '../components/ui/States';
import { Badge, Button, Card, Input, useToast } from '../ui';
import { Motion, fadeInUp } from '../motion';
import ListFilters, { FilterBadges } from '../components/filters/ListFilters';
import type { FilterValues } from '../components/filters/types';
import { buyListFilterFields, buyListFiltersToParams } from '../lib/buyListFilters';
import type { AdminReorder } from '../types';

interface ReorderMeta {
  current_page: number;
  last_page: number;
  total: number;
}

/**
 * The buy-list: open supplier reorder drafts raised when a variant falls below
 * threshold or a backorder drives on-hand negative. Staff buy the blank from the
 * affiliate source, then mark it received (which restocks through the ledger).
 *
 * The backend paginates this (it's an unbounded, ever-growing backlog until
 * items are received), so this page loads page 1 then appends further pages on
 * demand - same data+meta envelope and "Load more" pattern as the product
 * history panel (ProductAdminDetailPage's HistorySection).
 */
export default function ReorderBuyListPage() {
  const { toast } = useToast();
  const [reorders, setReorders] = useState<AdminReorder[] | null>(null);
  const [meta, setMeta] = useState<ReorderMeta | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [receiving, setReceiving] = useState<number | null>(null);

  // Staff-list filters + free-text search, mirroring QuoteListPage. The popup's
  // values map to API params; the search box folds in as `q`. Applying a filter
  // (or typing) resets back to page 1 via the paramsKey effect below, and the
  // same params are threaded into "Load more" so pagination stays filtered.
  const [filters, setFilters] = useState<FilterValues>({});
  const [term, setTerm] = useState('');
  const activeTerm = term.trim();
  const params: Record<string, string> = {
    ...buyListFiltersToParams(filters),
    ...(activeTerm ? { q: activeTerm } : {}),
  };
  const paramsKey = JSON.stringify(params);
  const hasActiveFilters = Object.keys(params).length > 0;

  // Page 1 with the current params. Kept stable (no params dep) so the effect
  // below controls when it runs — it reads params from the closure via paramsKey.
  const load = useCallback(async (requestParams: Record<string, string>) => {
    setLoading(true);
    setError(null);
    try {
      const { data } = await api.get<{ data: AdminReorder[]; meta: ReorderMeta }>('/admin/supplier-reorders', {
        params: requestParams,
      });
      setReorders(data.data);
      setMeta(data.meta);
    } catch (err) {
      setError(apiError(err));
    } finally {
      setLoading(false);
    }
  }, []);

  const loadMore = async () => {
    if (!meta || meta.current_page >= meta.last_page || loadingMore) return;
    setLoadingMore(true);
    try {
      const { data } = await api.get<{ data: AdminReorder[]; meta: ReorderMeta }>('/admin/supplier-reorders', {
        params: { ...params, page: meta.current_page + 1 },
      });
      setReorders((prev) => [...(prev ?? []), ...data.data]);
      setMeta(data.meta);
    } catch (err) {
      toast({ title: 'Could not load more', description: apiError(err), tone: 'danger' });
    } finally {
      setLoadingMore(false);
    }
  };

  // Debounced so typing coalesces to one request; re-runs when applied filters
  // change too (paramsKey), always resetting to page 1. `params` is read from the
  // closure — paramsKey is the value that actually changed.
  useEffect(() => {
    const id = setTimeout(() => void load(params), 300);
    return () => clearTimeout(id);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [paramsKey, load]);

  const receive = async (r: AdminReorder) => {
    if (receiving !== null) return;
    setReceiving(r.id);
    try {
      await ensureCsrf();
      await api.post(`/admin/supplier-reorders/${r.id}/receive`);
      toast({ title: 'Marked received', description: r.item, tone: 'success' });
      await load(params);
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
          {meta && meta.total > 0 && <Badge tone="warning">{meta.total} open</Badge>}
        </div>
        <p className="mt-1 text-sm text-fg-muted">
          Blanks to reorder - raised when stock falls below threshold or a backorder sells at zero.
          Buy from the source, then mark received to restock.
        </p>
      </Motion>

      {/* Search + filters. The Filters button sits on the same row as the search
          box (bottom-aligned to the input); active filters wrap onto their own
          line below as removable badges. */}
      <div className="flex flex-col gap-3">
        <div className="flex flex-wrap items-end gap-3">
          <div className="w-full max-w-sm">
            <Input
              type="search"
              label="Search buy-list"
              placeholder="Search by SKU or product name"
              value={term}
              onChange={(e) => setTerm(e.target.value)}
            />
          </div>
          <ListFilters fields={buyListFilterFields()} value={filters} onChange={setFilters} />
        </div>
        <FilterBadges fields={buyListFilterFields()} value={filters} onChange={setFilters} />
      </div>

      <AsyncBoundary
        loading={loading}
        error={error}
        isEmpty={(reorders ?? []).length === 0}
        emptyTitle={hasActiveFilters ? 'No reorders match those filters.' : 'Nothing to reorder.'}
        onRetry={() => void load(params)}
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
        {meta && meta.last_page > 1 && meta.current_page < meta.last_page && (
          <div className="mt-3">
            <Button variant="outline" size="sm" loading={loadingMore} onClick={() => void loadMore()}>
              Load more
            </Button>
          </div>
        )}
      </AsyncBoundary>
    </section>
  );
}
