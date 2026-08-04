import { useEffect, useMemo, useState } from 'react';
import { Card, Skeleton } from '../ui';
import { ErrorState } from '../components/ui/States';
import { fetchReports, reportsExportUrl, type ReportsPayload } from '../lib/reports';

function isoDate(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

const PRESETS: Record<string, () => { from: string; to: string }> = {
  'Last 90 days': () => {
    const to = new Date();
    const from = new Date();
    from.setDate(from.getDate() - 90);
    return { from: isoDate(from), to: isoDate(to) };
  },
  'This month': () => {
    const now = new Date();
    return { from: isoDate(new Date(now.getFullYear(), now.getMonth(), 1)), to: isoDate(now) };
  },
  'Year to date': () => {
    const now = new Date();
    return { from: isoDate(new Date(now.getFullYear(), 0, 1)), to: isoDate(now) };
  },
};

export default function ReportsPage() {
  const [preset, setPreset] = useState<string>('Last 90 days');
  const range = useMemo(() => PRESETS[preset](), [preset]);
  const [data, setData] = useState<ReportsPayload | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let live = true;
    setLoading(true);
    setError(null);
    fetchReports(range.from, range.to)
      .then((d) => live && setData(d))
      .catch(() => live && setError('Could not load reports.'))
      .finally(() => live && setLoading(false));
    return () => { live = false; };
  }, [range.from, range.to]);

  const maxRevenue = useMemo(
    () => Math.max(1, ...(data?.revenueTrend.flatMap((m) => [m.bookings, m.billed]) ?? [])),
    [data],
  );

  return (
    <div className="flex flex-col gap-8">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="font-display text-3xl text-fg">Reports</h1>
        <div className="flex items-center gap-2">
          <select
            aria-label="Date range"
            value={preset}
            onChange={(e) => setPreset(e.target.value)}
            className="rounded-md border border-border bg-surface px-3 py-1.5 text-sm text-fg"
          >
            {Object.keys(PRESETS).map((p) => <option key={p} value={p}>{p}</option>)}
          </select>
          <a
            href={reportsExportUrl(range.from, range.to)}
            className="rounded-md border border-border bg-surface px-3 py-1.5 text-sm font-medium text-fg hover:border-primary/50"
          >
            Download CSV
          </a>
        </div>
      </div>

      {loading && !data ? (
        <div className="grid gap-4 sm:grid-cols-3">{Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} height="8rem" />)}</div>
      ) : error && !data ? (
        <ErrorState message={error} />
      ) : data ? (
        <>
          <Card padding="lg">
            <h2 className="font-display text-xl text-fg">Revenue (net of GST)</h2>
            <p className="mt-1 text-sm text-fg-muted">Bookings (accepted) vs Billed (invoiced), by month.</p>
            <table className="mt-4 w-full text-sm">
              <thead>
                <tr className="text-left text-fg-subtle">
                  <th className="py-1">Month</th><th className="py-1 text-right">Bookings</th><th className="py-1 text-right">Billed</th>
                </tr>
              </thead>
              <tbody>
                {data.revenueTrend.map((m) => (
                  <tr key={m.month} className="border-t border-border">
                    <td className="py-1.5">{m.month}</td>
                    <td className="py-1.5 text-right tabular-nums">{m.bookings.toFixed(2)}</td>
                    <td className="py-1.5 text-right tabular-nums">{m.billed.toFixed(2)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
            {/* Lightweight inline bars (no chart dep). */}
            <div className="mt-4 flex items-end gap-3" aria-hidden="true">
              {data.revenueTrend.map((m) => (
                <div key={m.month} className="flex flex-1 flex-col items-center gap-1">
                  <div className="flex h-24 w-full items-end justify-center gap-0.5">
                    <div className="w-1/3 bg-primary/70" style={{ height: `${(m.bookings / maxRevenue) * 100}%` }} />
                    <div className="w-1/3 bg-accent-500/70" style={{ height: `${(m.billed / maxRevenue) * 100}%` }} />
                  </div>
                  <span className="text-2xs text-fg-subtle">{m.month.slice(5)}</span>
                </div>
              ))}
            </div>
          </Card>

          <Card padding="lg">
            <h2 className="font-display text-xl text-fg">Top products</h2>
            <table className="mt-4 w-full text-sm">
              <thead><tr className="text-left text-fg-subtle"><th className="py-1">Product</th><th className="py-1 text-right">Units</th><th className="py-1 text-right">Revenue</th></tr></thead>
              <tbody>
                {data.topProducts.map((p) => (
                  <tr key={p.productId} className="border-t border-border">
                    <td className="py-1.5">{p.name ?? `Product #${p.productId}`}</td>
                    <td className="py-1.5 text-right tabular-nums">{p.units}</td>
                    <td className="py-1.5 text-right tabular-nums">{p.revenue.toFixed(2)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </Card>

          <Card padding="lg">
            <h2 className="font-display text-xl text-fg">Repeat customers</h2>
            <p className="mt-2 font-display text-3xl text-fg">{Math.round(data.repeatCustomerRate.rate * 100)}%</p>
            <p className="text-sm text-fg-muted">
              {data.repeatCustomerRate.repeatCompanies} of {data.repeatCustomerRate.activeCompanies} active companies have ordered 2+ times.
            </p>
          </Card>
        </>
      ) : null}
    </div>
  );
}
