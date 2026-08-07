import {
  Bar,
  CartesianGrid,
  ComposedChart,
  Legend,
  Line,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts';

export interface TrendPoint {
  weekStart: string;
  orders: number;
  bookedValue: number;
}

const AXIS = 'rgb(var(--color-fg-subtle))';
const GRID = 'rgb(var(--color-border))';
const BAR = 'rgb(var(--color-primary))';
const LINE = 'rgb(var(--color-fg))';

/** Week label like "16 Jun" from an ISO date, without a date lib. */
function weekLabel(iso: string): string {
  const d = new Date(iso);
  return d.toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
}

export default function TrendChart({ data }: { data: TrendPoint[] }) {
  return (
    <div className="h-64 w-full" data-testid="trend-chart">
      <ResponsiveContainer width="100%" height="100%">
        <ComposedChart data={data} margin={{ top: 8, right: 8, bottom: 4, left: 4 }}>
          <CartesianGrid stroke={GRID} vertical={false} />
          <XAxis dataKey="weekStart" tickFormatter={weekLabel} stroke={AXIS} fontSize={12} tickLine={false} />
          <YAxis yAxisId="orders" stroke={AXIS} fontSize={12} tickLine={false} allowDecimals={false} />
          <YAxis yAxisId="value" orientation="right" stroke={AXIS} fontSize={12} tickLine={false} width={64}
            tickFormatter={(v: number) => `$${Number(v).toLocaleString()}`} />
          <Tooltip
            formatter={(value: number, name: string) =>
              name === 'bookedValue' ? [`$${Number(value).toLocaleString()}`, 'Booked value'] : [value, 'Orders']
            }
            labelFormatter={(l: string) => `Week of ${weekLabel(l)}`}
          />
          <Legend formatter={(v: string) => (v === 'bookedValue' ? 'Booked value' : 'Orders')} />
          <Bar yAxisId="orders" dataKey="orders" fill={BAR} radius={[3, 3, 0, 0]} maxBarSize={28} />
          <Line yAxisId="value" type="monotone" dataKey="bookedValue" stroke={LINE} strokeWidth={2} dot={false} />
        </ComposedChart>
      </ResponsiveContainer>
    </div>
  );
}
