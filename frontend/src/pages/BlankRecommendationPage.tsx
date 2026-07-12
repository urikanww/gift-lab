import { useCallback, useEffect, useRef, useState } from 'react';
import { Badge, Button, Card, Input, Modal, Select, useOptionalToast } from '../ui';
import { apiError } from '../lib/api';
import { ShopeeLink } from '../components/ShopeeLink';
import {
  addBlank,
  featureCandidate,
  searchCandidates,
  type Candidate,
  type CandidateSort,
} from '../lib/recommendations';

const PAGE_SIZE = 20;

const SORT_OPTIONS: { value: CandidateSort; label: string }[] = [
  { value: 'relevance', label: 'Relevance' },
  { value: 'sales', label: 'Top sales' },
  { value: 'commission', label: 'Commission %' },
  { value: 'price_asc', label: 'Price: low → high' },
  { value: 'price_desc', label: 'Price: high → low' },
];

export default function BlankRecommendationPage() {
  const { toast } = useOptionalToast();
  const [keyword, setKeyword] = useState('');
  const [sort, setSort] = useState<CandidateSort>('sales');
  const [active, setActive] = useState(''); // the keyword the current results belong to
  const [activeSort, setActiveSort] = useState<CandidateSort>('sales'); // sort the current results use
  const [loading, setLoading] = useState(false); // initial search
  const [loadingMore, setLoadingMore] = useState(false);
  const [candidates, setCandidates] = useState<Candidate[]>([]);
  const [page, setPage] = useState(0);
  const [hasMore, setHasMore] = useState(false);
  const [searched, setSearched] = useState(false);
  const [busy, setBusy] = useState<string | null>(null);
  const [zoom, setZoom] = useState<Candidate | null>(null);
  const [help, setHelp] = useState(false);
  const sentinel = useRef<HTMLDivElement | null>(null);

  const run = async (sortOverride?: CandidateSort) => {
    if (loading) return;
    const kw = keyword.trim(); // empty = browse Shopee's top-sales feed
    const s = sortOverride ?? sort;
    setLoading(true);
    setSearched(true);
    try {
      const res = await searchCandidates(kw, PAGE_SIZE, 1, s);
      setCandidates(res.data);
      setPage(res.page);
      setHasMore(res.has_more);
      setActive(kw);
      setActiveSort(s);
    } catch (err) {
      setCandidates([]);
      setHasMore(false);
      toast({ title: 'Search failed', description: apiError(err), tone: 'danger' });
    } finally {
      setLoading(false);
    }
  };

  const loadMore = useCallback(async () => {
    if (loadingMore || loading || !hasMore || !searched) return;
    setLoadingMore(true);
    try {
      const res = await searchCandidates(active, PAGE_SIZE, page + 1, activeSort);
      setCandidates((prev) => {
        const seen = new Set(prev.map((c) => c.source_product_id));
        return [...prev, ...res.data.filter((c) => !seen.has(c.source_product_id))];
      });
      setPage(res.page);
      setHasMore(res.has_more);
    } catch (err) {
      setHasMore(false);
      toast({ title: 'Could not load more', description: apiError(err), tone: 'danger' });
    } finally {
      setLoadingMore(false);
    }
  }, [active, activeSort, hasMore, loading, loadingMore, page, searched, toast]);

  // First open: browse Shopee's top sellers so the page isn't empty (no keyword).
  useEffect(() => {
    void run();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Auto-load the next page when the sentinel scrolls into view.
  useEffect(() => {
    if (typeof IntersectionObserver === 'undefined') return; // jsdom / SSR guard
    const el = sentinel.current;
    if (!el || !hasMore) return;
    const io = new IntersectionObserver(
      (entries) => {
        if (entries[0]?.isIntersecting) void loadMore();
      },
      { rootMargin: '400px' },
    );
    io.observe(el);
    return () => io.disconnect();
  }, [hasMore, loadMore]);

  const act = async (c: Candidate, kind: 'add' | 'feature') => {
    setBusy(`${kind}:${c.source_product_id}`);
    try {
      if (kind === 'add') await addBlank(c);
      else await featureCandidate(c);
      toast({ title: kind === 'add' ? 'Added to gate' : 'Featured', description: c.name, tone: 'success' });
    } catch (err) {
      toast({ title: 'Action failed', description: apiError(err), tone: 'danger' });
    } finally {
      setBusy(null);
    }
  };

  return (
    <section className="flex flex-col gap-6">
      <div>
        <div className="flex items-center gap-2">
          <h1 className="font-display text-3xl text-fg">Blank recommendations</h1>
          <button
            type="button"
            onClick={() => setHelp(true)}
            aria-label="What do these actions do?"
            className="inline-flex h-6 w-6 items-center justify-center rounded-full border border-border-strong text-sm font-semibold text-fg-muted transition hover:bg-surface-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          >
            ?
          </button>
        </div>
        <p className="mt-1 text-sm text-fg-muted">
          Search Shopee for UV-printable blanks. Add promising ones to the gate, or feature them on the public gift-ideas page.
        </p>
        <p className="mt-2 rounded-md border border-warning/40 bg-warning/10 px-3 py-2 text-xs text-fg-muted">
          <strong className="text-fg">Preview only.</strong> “View on Shopee” opens your affiliate link. To
          <strong className="text-fg"> buy a blank, purchase in a separate/incognito browser</strong> so your own
          order isn’t attributed to your affiliate account (self-referral). Procurement links on the catalogue gate stay plain.
        </p>
      </div>

      <div className="flex flex-wrap items-end gap-2">
        <div className="min-w-[12rem] flex-1">
          <Input
            label="Keyword"
            value={keyword}
            onChange={(e) => setKeyword(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Enter') {
                e.preventDefault();
                void run();
              }
            }}
            placeholder="ceramic mug, acrylic keychain…"
          />
        </div>
        <div className="w-44">
          <Select
            label="Sort by"
            options={SORT_OPTIONS}
            value={sort}
            onChange={(e) => {
              const s = e.target.value as CandidateSort;
              setSort(s);
              if (active) void run(s);
            }}
          />
        </div>
        <Button loading={loading} onClick={() => void run()}>Search</Button>
      </div>

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-5">
        {candidates.map((c) => (
          <Card key={c.source_product_id} padding="md" className="flex flex-col gap-2">
            {c.image_url && (
              <button
                type="button"
                onClick={() => setZoom(c)}
                aria-label={`Zoom image of ${c.name}`}
                className="group relative block cursor-zoom-in overflow-hidden rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              >
                <img src={c.image_url} alt="" className="aspect-square w-full object-cover transition-transform duration-200 group-hover:scale-105" referrerPolicy="no-referrer" />
                <span className="pointer-events-none absolute bottom-1 right-1 rounded bg-ink-900/60 px-1.5 py-0.5 text-[10px] font-medium text-ink-0 opacity-0 transition-opacity group-hover:opacity-100">
                  Click to zoom
                </span>
              </button>
            )}
            <p className="line-clamp-2 text-sm font-medium text-fg">{c.name}</p>
            <div className="flex flex-wrap gap-1.5 text-xs text-fg-subtle">
              <span className="font-semibold text-fg">{c.currency} {c.price ?? '—'}</span>
              <span>{`· ${c.sales} sold`}</span>
              {c.rating_star != null && <span>· ★ {c.rating_star}</span>}
              {c.commission_rate != null && (
                <span className="font-medium text-primary">{`· ${Math.round(c.commission_rate * 100)}% comm`}</span>
              )}
            </div>
            <div className="flex flex-wrap gap-1.5">
              {c.ip_flag && <Badge tone="danger" size="sm">IP: {c.ip_flag}</Badge>}
              {c.material_flag && <Badge tone="warning" size="sm">{c.material_flag}</Badge>}
            </div>
            <div className="mt-auto flex flex-col gap-2 pt-2">
              {/* Preview via the affiliate link (view only — see the self-referral
                  note above; procurement uses the plain link on the gate). */}
              <ShopeeLink href={c.offer_link} rel="sponsored nofollow noopener noreferrer" className="w-full">View on Shopee</ShopeeLink>
              <div className="flex flex-wrap gap-2">
                <Button size="sm" loading={busy === `add:${c.source_product_id}`} onClick={() => void act(c, 'add')}>Add as blank</Button>
                <Button size="sm" variant="outline" loading={busy === `feature:${c.source_product_id}`} onClick={() => void act(c, 'feature')}>Feature</Button>
              </div>
            </div>
          </Card>
        ))}
      </div>

      {/* Infinite-scroll sentinel + status line. */}
      <div ref={sentinel} className="py-2 text-center text-xs text-fg-subtle">
        {loadingMore && 'Loading more…'}
        {!loadingMore && searched && !loading && candidates.length === 0 && 'No matches — try another keyword.'}
        {!loadingMore && !hasMore && candidates.length > 0 && '— End of results —'}
        {!loadingMore && hasMore && (
          <Button size="sm" variant="outline" onClick={() => void loadMore()}>Load more</Button>
        )}
      </div>

      <Modal open={zoom !== null} onClose={() => setZoom(null)} title={zoom?.name ?? ''} size="lg">
        {zoom?.image_url && (
          <img src={zoom.image_url} alt={zoom.name} className="mx-auto max-h-[70vh] w-auto rounded object-contain" referrerPolicy="no-referrer" />
        )}
      </Modal>

      <Modal open={help} onClose={() => setHelp(false)} title="What do these actions do?" size="md">
        <dl className="flex flex-col gap-3 text-sm">
          <div>
            <dt className="font-semibold text-fg">Add as blank</dt>
            <dd className="text-fg-muted">Import into your catalogue to make &amp; sell. Lands in the gate as a draft — complete size/weight, then publish.</dd>
          </div>
          <div>
            <dt className="font-semibold text-fg">Feature</dt>
            <dd className="text-fg-muted">Show on the public gift-ideas page as an affiliate link. Customers buy on Shopee; you earn a commission.</dd>
          </div>
          <div>
            <dt className="font-semibold text-fg">View on Shopee</dt>
            <dd className="text-fg-muted">Preview the listing via your affiliate link. Don’t buy through it — purchase in a separate/incognito browser (self-referral). Procurement links on the gate stay plain.</dd>
          </div>
        </dl>
      </Modal>
    </section>
  );
}
