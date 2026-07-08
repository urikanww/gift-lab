import { useCallback, useEffect, useMemo, useState } from 'react';
import api, { apiError, ensureCsrf } from '../lib/api';
import { useAuthStore } from '../stores/authStore';
import { AsyncBoundary } from '../components/ui/States';
import { Button, Card, cn, useToast } from '../ui';
import { Motion, fadeInUp } from '../motion';
import { CONFIG_META, GROUP_LABELS, GROUP_ORDER, fieldDomId, metaFor, type FieldMeta } from '../lib/pricingMeta';
import { ConfigField, type ConfigRow } from './pricingFields';
import TestQuoteCard from './pricingTestQuote';

/**
 * Superadmin pricing/config editor. Every quote-time number is editable without
 * a deploy, and every change is audit-logged. The raw key/value store is
 * presented through plain-language labels and purpose-built controls (no JSON),
 * with expert knobs tucked into an "Advanced settings" section.
 */

interface Field {
  row: ConfigRow;
  meta: FieldMeta;
}

function orderedGroups(fields: Field[]): [string, Field[]][] {
  const byGroup = new Map<string, Field[]>();
  fields.forEach((f) => {
    const list = byGroup.get(f.row.group) ?? [];
    list.push(f);
    byGroup.set(f.row.group, list);
  });
  return Array.from(byGroup.entries()).sort(
    ([a], [b]) => GROUP_ORDER.indexOf(a as never) - GROUP_ORDER.indexOf(b as never),
  );
}

export default function PricingAdminPage() {
  const isSuperadmin = useAuthStore((s) => s.user?.role === 'superadmin');
  const { toast } = useToast();
  const [rows, setRows] = useState<ConfigRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [showAdvanced, setShowAdvanced] = useState(false);
  const [highlight, setHighlight] = useState<string | null>(null);

  // Jump from a breakdown row to the config knob that drives it: open the
  // Advanced section if the knob lives there, scroll to it, and flash it.
  const jumpToConfig = useCallback((configKey: string) => {
    const isAdvanced = Boolean(CONFIG_META[configKey]?.advanced);
    if (isAdvanced) setShowAdvanced(true);
    setHighlight(configKey);
    window.setTimeout(() => {
      document.getElementById(fieldDomId(configKey))?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }, isAdvanced ? 60 : 0);
    window.setTimeout(() => setHighlight(null), 2200);
  }, []);

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

  const { everyday, advanced } = useMemo(() => {
    const fields: Field[] = rows.map((row) => ({ row, meta: metaFor(row.group, row.key, row.value) }));
    return {
      everyday: orderedGroups(fields.filter((f) => !f.meta.advanced)),
      advanced: orderedGroups(fields.filter((f) => f.meta.advanced)),
    };
  }, [rows]);

  const save = async (row: ConfigRow, value: unknown) => {
    try {
      await ensureCsrf();
      const { data } = await api.patch<{ data: ConfigRow }>(`/admin/pricing-configs/${row.id}`, { value });
      setRows((prev) => prev.map((r) => (r.id === row.id ? { ...r, value: data.data.value } : r)));
      const label = CONFIG_META[`${row.group}.${row.key}`]?.label ?? row.label ?? row.key;
      toast({ title: 'Saved', description: `${label} updated.`, tone: 'success' });
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

  const renderGroup = ([group, fields]: [string, Field[]]) => (
    <Card key={group} padding="lg" aria-label={GROUP_LABELS[group] ?? group}>
      <h2 className="mb-4 font-display text-xl text-fg">{GROUP_LABELS[group] ?? group}</h2>
      <div className="flex flex-col gap-3">
        {fields.map((f) => {
          const key = `${f.row.group}.${f.row.key}`;
          return (
            <div
              key={f.row.id}
              id={fieldDomId(key)}
              className={cn(
                'rounded-lg transition-shadow',
                highlight === key && 'ring-2 ring-primary ring-offset-2 ring-offset-bg',
              )}
            >
              <ConfigField row={f.row} meta={f.meta} onSave={save} />
            </div>
          );
        })}
      </div>
    </Card>
  );

  return (
    <AsyncBoundary loading={loading} error={error} isEmpty={rows.length === 0} emptyTitle="No configuration found." onRetry={load}>
      <Motion variants={fadeInUp} initial="hidden" animate="visible" className="flex flex-col gap-6">
        <header>
          <h1 className="font-display text-3xl text-fg">Pricing &amp; settings</h1>
          <p className="mt-1 text-sm text-fg-muted">
            These numbers drive every quote. Changes apply to the next estimate straight away - no deploy - and
            every edit is logged.
          </p>
        </header>

        <TestQuoteCard onEditConfig={jumpToConfig} />

        {everyday.map(renderGroup)}

        {advanced.length > 0 && (
          <div className="flex flex-col gap-3">
            <Button variant="ghost" className="self-start" onClick={() => setShowAdvanced((s) => !s)} aria-expanded={showAdvanced}>
              {showAdvanced ? '▾' : '▸'} Advanced settings
            </Button>
            {showAdvanced && (
              <>
                <p className="text-sm text-fg-muted">
                  Expert knobs - catalogue automation, margin floor, and checkout rules. Leave these unless you know
                  what they do.
                </p>
                {advanced.map(renderGroup)}
              </>
            )}
          </div>
        )}
      </Motion>
    </AsyncBoundary>
  );
}
