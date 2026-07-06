import { useCallback, useEffect, useMemo, useState } from 'react';
import api, { apiError, ensureCsrf } from '../lib/api';
import { useAuthStore } from '../stores/authStore';
import { AsyncBoundary } from '../components/ui/States';
import { Badge, Button, Card, Input, useToast } from '../ui';
import { Motion, fadeInUp } from '../motion';

/**
 * Superadmin pricing/config editor (audit E1/D7/E2): margins, floor, fees,
 * size surcharges, print costs, thresholds, drift %, pay-now cutoff — every
 * quote-time number editable without a deploy or DB access.
 */

interface ConfigRow {
  id: number;
  group: string;
  key: string;
  value: unknown;
  label: string | null;
  is_money: boolean;
  currency: string | null;
  updated_at: string | null;
}

const GROUP_LABELS: Record<string, string> = {
  margin: 'Margins',
  fee: 'Customization fees',
  print_cost: 'Print costs',
  threshold: 'Bulk thresholds',
  delivery: 'Delivery',
  lead_time: 'Lead time',
  config: 'Order flow',
  catalogue: 'Catalogue',
};

export default function PricingAdminPage() {
  const isSuperadmin = useAuthStore((s) => s.user?.role === 'superadmin');
  const { toast } = useToast();
  const [rows, setRows] = useState<ConfigRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const { data } = await api.get<{ data: ConfigRow[] }>('/admin/pricing-configs');
      setRows(data.data);
    } catch (err) {
      setError(apiError(err));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    if (isSuperadmin) void load();
  }, [isSuperadmin, load]);

  const groups = useMemo(() => {
    const byGroup = new Map<string, ConfigRow[]>();
    rows.forEach((r) => {
      const list = byGroup.get(r.group) ?? [];
      list.push(r);
      byGroup.set(r.group, list);
    });
    return Array.from(byGroup.entries());
  }, [rows]);

  const save = async (row: ConfigRow, value: unknown) => {
    try {
      await ensureCsrf();
      const { data } = await api.patch<{ data: ConfigRow }>(`/admin/pricing-configs/${row.id}`, { value });
      setRows((prev) => prev.map((r) => (r.id === row.id ? { ...r, value: data.data.value } : r)));
      toast({ title: 'Saved', description: `${row.group}.${row.key} updated.`, tone: 'success' });
      return true;
    } catch (err) {
      toast({ title: 'Not saved', description: apiError(err), tone: 'danger' });
      return false;
    }
  };

  if (!isSuperadmin) {
    return (
      <Card padding="lg">
        <p className="text-sm text-fg-muted">Pricing configuration is restricted to the superadmin.</p>
      </Card>
    );
  }

  return (
    <AsyncBoundary loading={loading} error={error} isEmpty={rows.length === 0} emptyTitle="No configuration found." onRetry={load}>
      <Motion variants={fadeInUp} initial="hidden" animate="visible" className="flex flex-col gap-6">
        <header>
          <h1 className="font-display text-3xl text-fg">Pricing &amp; configuration</h1>
          <p className="mt-1 text-sm text-fg-muted">
            Values are read by the quote engine at quote time — changes apply to the next estimate with no deploy.
            Every change is audit-logged.
          </p>
        </header>

        {groups.map(([group, groupRows]) => (
          <Card key={group} padding="lg" aria-label={GROUP_LABELS[group] ?? group}>
            <h2 className="mb-4 font-display text-xl text-fg">{GROUP_LABELS[group] ?? group}</h2>
            <div className="flex flex-col gap-4">
              {groupRows.map((row) => (
                <ConfigEditor key={row.id} row={row} onSave={save} />
              ))}
            </div>
          </Card>
        ))}
      </Motion>
    </AsyncBoundary>
  );
}

function ConfigEditor({ row, onSave }: { row: ConfigRow; onSave: (row: ConfigRow, value: unknown) => Promise<boolean> }) {
  const isScalar = typeof row.value === 'number' || typeof row.value === 'string';
  const isBoolean = typeof row.value === 'boolean';
  const [draft, setDraft] = useState<string>(
    isScalar ? String(row.value) : JSON.stringify(row.value, null, 2),
  );
  const [saving, setSaving] = useState(false);
  const [jsonError, setJsonError] = useState<string | null>(null);

  const submit = async () => {
    let value: unknown = draft;
    if (typeof row.value === 'number') {
      const parsed = Number(draft);
      if (Number.isNaN(parsed)) {
        setJsonError('Enter a number.');
        return;
      }
      value = parsed;
    } else if (!isScalar && !isBoolean) {
      try {
        value = JSON.parse(draft);
      } catch {
        setJsonError('Invalid JSON.');
        return;
      }
    }
    setJsonError(null);
    setSaving(true);
    await onSave(row, value);
    setSaving(false);
  };

  return (
    <div className="flex flex-col gap-2 rounded-md border border-border p-3">
      <div className="flex flex-wrap items-center gap-2">
        <span className="text-sm font-medium text-fg">{row.label ?? `${row.group}.${row.key}`}</span>
        <Badge tone="neutral" size="sm">{row.group}.{row.key}</Badge>
        {row.is_money && <Badge tone="brand" size="sm">{row.currency ?? 'SGD'}</Badge>}
      </div>
      {isBoolean ? (
        <label className="flex items-center gap-2 text-sm text-fg">
          <input
            type="checkbox"
            checked={draft === 'true'}
            onChange={(e) => setDraft(String(e.target.checked))}
            className="h-4 w-4"
          />
          Enabled
        </label>
      ) : isScalar ? (
        <Input
          label=""
          aria-label={`${row.group}.${row.key} value`}
          type={typeof row.value === 'number' ? 'number' : 'text'}
          value={draft}
          onChange={(e) => setDraft(e.target.value)}
        />
      ) : (
        <textarea
          aria-label={`${row.group}.${row.key} value (JSON)`}
          value={draft}
          onChange={(e) => setDraft(e.target.value)}
          rows={Math.min(10, draft.split('\n').length + 1)}
          className="w-full rounded-md border border-border bg-surface px-3 py-2 font-mono text-xs text-fg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        />
      )}
      {jsonError && (
        <p className="text-xs text-danger" role="alert">{jsonError}</p>
      )}
      <div>
        <Button
          size="sm"
          onClick={() => {
            if (isBoolean) {
              setSaving(true);
              void onSave(row, draft === 'true').finally(() => setSaving(false));
            } else {
              void submit();
            }
          }}
          loading={saving}
        >
          Save
        </Button>
      </div>
    </div>
  );
}
