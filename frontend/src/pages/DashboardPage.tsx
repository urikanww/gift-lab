import { Link } from 'react-router-dom';
import { useDashboardStore } from '../stores/dashboardStore';
import { Card, Skeleton } from '../ui';
import { ErrorState } from '../components/ui/States';
import { humanizeState } from '../lib/quoteStatus';

const PIPELINE_ORDER = [
  'DRAFT', 'SENT', 'CHANGES_REQUESTED', 'ACCEPTED', 'PROOFING', 'PROOF_APPROVED',
  'INVOICED', 'CONFIRMED', 'PROCURING', 'READY', 'CLOSED', 'CANCELLED',
] as const;

function StatTile({ label, value, to }: { label: string; value: number; to: string }) {
  return (
    <Link to={to} className="rounded-lg border border-border bg-surface p-4 transition-colors hover:border-primary/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
      <p className="text-sm text-fg-muted">{label}</p>
      <p className="mt-1 font-display text-3xl text-fg">{value}</p>
    </Link>
  );
}

export default function DashboardPage() {
  const { data, loading, error, load } = useDashboardStore();

  if (loading && !data) {
    return (
      <div className="grid gap-4 sm:grid-cols-3">
        {Array.from({ length: 6 }).map((_, i) => <Skeleton key={i} height="6rem" />)}
      </div>
    );
  }

  if (error && !data) return <ErrorState message={error} onRetry={() => void load()} />;
  if (!data) return null;

  const maxPipe = Math.max(1, ...Object.values(data.pipeline));

  return (
    <div className="flex flex-col gap-8">
      <h1 className="font-display text-3xl text-fg">Dashboard</h1>

      <section className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatTile label="Proofs pending" value={data.queues.proofsPending} to="/quotes" />
        <StatTile label="Procurement to reconfirm" value={data.queues.procurementToReconfirm} to="/procurement" />
        <StatTile label="Catalogue pending" value={data.queues.cataloguePending} to="/catalogue-admin" />
        <StatTile label="At-risk / overdue jobs" value={data.production.overdue} to="/production-queue" />
      </section>

      {data.valueBooked && (
        <Card padding="md">
          <p className="text-sm text-fg-muted">Value booked</p>
          <p className="mt-1 font-display text-3xl text-fg">
            {data.valueBooked.currency} {data.valueBooked.amount.toLocaleString()}
          </p>
        </Card>
      )}

      <section className="flex flex-col gap-3">
        <h2 className="font-display text-xl text-fg">Quote pipeline</h2>
        <Card padding="md" className="flex flex-col gap-2">
          {PIPELINE_ORDER.map((s) => {
            const n = data.pipeline[s] ?? 0;
            return (
              <div key={s} className="flex items-center gap-3 text-sm">
                <span className="w-40 shrink-0 text-fg-muted">{humanizeState(s)}</span>
                <div className="h-3 flex-1 overflow-hidden rounded-full bg-surface-2">
                  <div className="h-full rounded-full bg-primary" style={{ width: `${(n / maxPipe) * 100}%` }} />
                </div>
                <span className="w-8 text-right tabular-nums text-fg">{n}</span>
              </div>
            );
          })}
        </Card>
      </section>

      <section className="flex flex-col gap-3">
        <h2 className="font-display text-xl text-fg">Production health</h2>
        <Card padding="md" className="flex flex-wrap gap-6 text-sm">
          {Object.entries(data.production.byState).map(([k, v]) => (
            <div key={k}><span className="text-fg-muted">{humanizeState(k)}: </span><span className="font-semibold text-fg">{v}</span></div>
          ))}
          <div><span className="text-fg-muted">WIP: </span><span className="font-semibold text-fg">{data.production.wip}</span></div>
          <div><span className="text-fg-muted">Overdue: </span><span className="font-semibold text-danger">{data.production.overdue}</span></div>
        </Card>
      </section>

      {data.atRisk.length > 0 && (
        <section className="flex flex-col gap-3">
          <h2 className="font-display text-xl text-fg">At-risk jobs</h2>
          <Card padding="none" className="divide-y divide-border">
            {data.atRisk.map((j) => (
              <Link key={j.jobId} to="/production-queue" className="flex items-center justify-between gap-3 p-3 text-sm hover:bg-surface-2">
                <span className="text-fg">Job #{j.jobId} · Quote #{j.quoteId}</span>
                <span className="text-fg-muted">{j.track} · {j.state}</span>
              </Link>
            ))}
          </Card>
        </section>
      )}

      <section className="flex flex-col gap-3">
        <h2 className="font-display text-xl text-fg">Recent activity</h2>
        <Card padding="none" className="divide-y divide-border">
          {data.activity.length === 0 ? (
            <p className="p-4 text-sm text-fg-muted">No recent activity.</p>
          ) : (
            data.activity.map((a) => (
              <div key={a.id} className="flex items-center justify-between gap-3 p-3 text-sm">
                <span className="text-fg">
                  <span className="font-medium">{a.actor ?? 'System'}</span> · {a.event}
                  <span className="text-fg-muted"> ({a.auditableType} #{a.auditableId})</span>
                </span>
                <span className="shrink-0 text-fg-subtle">{a.at ? new Date(a.at).toLocaleString() : ''}</span>
              </div>
            ))
          )}
        </Card>
      </section>
    </div>
  );
}
