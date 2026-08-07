import { Link } from 'react-router-dom';
import { useDashboardStore } from '../stores/dashboardStore';
import { Card, Skeleton } from '../ui';
import { ErrorState } from '../components/ui/States';
import { humanizeState } from '../lib/quoteStatus';
import { humanizeActivity, type ActivityCategory } from '../lib/activityHumanize';
import { OrderIcon, CatalogueIcon, UserIcon, ProductionIcon, SystemIcon } from '../components/icons';
import type { DashboardActivity } from '../lib/dashboard';
import TrendChart from '../components/dashboard/TrendChart';

const PIPELINE_ORDER = [
  'DRAFT', 'SENT', 'CHANGES_REQUESTED', 'ACCEPTED', 'PROOFING', 'ARTWORK_APPROVED', 'PROOF_APPROVED',
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

function money(v: { currency: string; amount: number }): string {
  return `${v.currency} ${v.amount.toLocaleString()}`;
}

function MoneyTile({ label, value, to }: { label: string; value: string; to: string }) {
  return (
    <Link to={to} className="rounded-lg border border-border bg-surface p-4 transition-colors hover:border-primary/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
      <p className="text-sm text-fg-muted">{label}</p>
      <p className="mt-1 font-display text-2xl text-fg">{value}</p>
    </Link>
  );
}

const CATEGORY_ICON: Record<ActivityCategory, (p: { className?: string }) => JSX.Element> = {
  order: OrderIcon,
  catalogue: CatalogueIcon,
  user: UserIcon,
  production: ProductionIcon,
  system: SystemIcon,
};

function ActivityRow({ activity }: { activity: DashboardActivity }) {
  const h = humanizeActivity(activity);
  const Icon = CATEGORY_ICON[h.category];
  return (
    <div className="flex items-center gap-3 p-3 text-sm">
      <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-surface-2 text-fg-muted">
        <Icon className="h-4 w-4" />
      </span>
      <span className="min-w-0 flex-1 truncate text-fg">{h.text}</span>
      <span className="shrink-0 text-fg-subtle" title={h.title}>{h.when}</span>
    </div>
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
        <StatTile label="Catalogue pending" value={data.queues.cataloguePending} to="/catalogue-admin" />
        <StatTile label="At-risk / overdue jobs" value={data.production.overdue} to="/production-queue" />
        {/* LT14: delivered orders whose invoice is still outstanding - a
            completed-yet-unpaid order used to have no flag and nothing to chase. */}
        <StatTile label="Delivered · unpaid" value={data.queues.unpaidDelivered} to="/quotes?filter=delivered_unpaid" />
      </section>

      {data.kpis && (
        <section className="grid gap-4 sm:grid-cols-3">
          <StatTile label="Orders this week" value={data.kpis.ordersThisWeek} to="/quotes" />
          <MoneyTile label="Booked value (this month)" value={money(data.kpis.bookedThisMonth)} to="/quotes" />
          <MoneyTile label="Outstanding to collect" value={money(data.kpis.outstanding)} to="/quotes?filter=delivered_unpaid" />
        </section>
      )}

      {data.trends && data.trends.length > 0 && (
        <section className="flex flex-col gap-3">
          <h2 className="font-display text-xl text-fg">Orders &amp; booked value · last 8 weeks</h2>
          <Card padding="md">
            <TrendChart data={data.trends} />
          </Card>
        </section>
      )}

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
                <span className="text-fg">Job #{j.jobId} · Order {j.quoteReference}</span>
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
            data.activity.map((a) => <ActivityRow key={a.id} activity={a} />)
          )}
        </Card>
      </section>
    </div>
  );
}
