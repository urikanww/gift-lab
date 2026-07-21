import { useCallback, useEffect, useState } from 'react';
import api, { apiError, ensureCsrf } from '../lib/api';
import { Button, Card, Input, Skeleton, useToast } from '../ui';
import { ErrorState } from '../components/ui/States';
import { Motion, staggerContainer, staggerItem } from '../motion';

/**
 * What buyers are told, and how hard they are chased.
 *
 * The application used to send two emails in total; everything else was a phone
 * call somebody had to remember to make. This screen is the counterweight to
 * automating that — the point is that staff can see exactly what a client will
 * receive, and turn any of it off, without asking a developer.
 */

interface MilestoneSetting {
  key: string;
  label: string;
  description: string;
  enabled: boolean;
  default: boolean;
}

interface Cadence {
  quote_days: number[];
  proof_days: number[];
}

/** "3, 7, 12" ⇄ [3, 7, 12]. Kept as text while editing so a half-typed list
 *  does not fight the user by reformatting under them. */
const parseDays = (text: string): number[] =>
  text
    .split(',')
    .map((part) => Number(part.trim()))
    .filter((n) => Number.isFinite(n) && n > 0);

export default function NotificationSettingsPage() {
  const [settings, setSettings] = useState<MilestoneSetting[]>([]);
  const [quoteDays, setQuoteDays] = useState('');
  const [proofDays, setProofDays] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [savingKey, setSavingKey] = useState<string | null>(null);
  const [savingCadence, setSavingCadence] = useState(false);
  const [cadenceError, setCadenceError] = useState<string | undefined>();
  const { toast } = useToast();

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const { data } = await api.get<{ data: MilestoneSetting[]; cadence: Cadence }>(
        '/admin/notification-settings',
      );
      setSettings(data.data);
      setQuoteDays(data.cadence.quote_days.join(', '));
      setProofDays(data.cadence.proof_days.join(', '));
    } catch (err) {
      setError(apiError(err));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const toggle = async (setting: MilestoneSetting) => {
    const next = !setting.enabled;
    setSavingKey(setting.key);
    // Optimistic: a toggle that visibly lags feels broken. Rolled back below if
    // the write fails, so the switch never lies about what is stored.
    setSettings((rows) =>
      rows.map((r) => (r.key === setting.key ? { ...r, enabled: next } : r)),
    );
    try {
      await ensureCsrf();
      await api.patch('/admin/notification-settings', { key: setting.key, enabled: next });
      toast({ title: next ? `${setting.label} is on` : `${setting.label} is off`, tone: 'success' });
    } catch (err) {
      setSettings((rows) =>
        rows.map((r) => (r.key === setting.key ? { ...r, enabled: setting.enabled } : r)),
      );
      toast({ title: apiError(err), tone: 'danger' });
    } finally {
      setSavingKey(null);
    }
  };

  const saveCadence = async () => {
    const quote = parseDays(quoteDays);
    const proof = parseDays(proofDays);

    if (quote.length === 0 || proof.length === 0) {
      setCadenceError('Enter at least one day for each, separated by commas.');
      return;
    }
    if (quote.length > 5 || proof.length > 5) {
      setCadenceError('Five reminders is the maximum — the ladder is meant to end.');
      return;
    }

    setSavingCadence(true);
    setCadenceError(undefined);
    try {
      await ensureCsrf();
      await api.patch('/admin/notification-settings/cadence', {
        quote_days: quote,
        proof_days: proof,
      });
      await load();
      toast({ title: 'Reminder schedule saved', tone: 'success' });
    } catch (err) {
      setCadenceError(apiError(err));
    } finally {
      setSavingCadence(false);
    }
  };

  if (loading) {
    return (
      <div className="flex flex-col gap-4" aria-hidden="true">
        <Skeleton width="14rem" height="2rem" />
        <Skeleton height="16rem" />
      </div>
    );
  }

  if (error) return <ErrorState message={error} onRetry={() => void load()} />;

  return (
    <Motion variants={staggerContainer} initial="hidden" animate="visible">
      <section className="flex flex-col gap-6" aria-labelledby="notifications-heading">
        <Motion variants={staggerItem}>
          <div>
            <h1 id="notifications-heading" className="font-display text-3xl text-fg">
              Client notifications
            </h1>
            <p className="mt-2 max-w-2xl text-sm text-fg-muted">
              What your clients are emailed automatically. Turning something off here means your
              staff handle that conversation themselves.
            </p>
          </div>
        </Motion>

        <Motion variants={staggerItem}>
          <Card padding="lg" aria-labelledby="milestones-heading">
            <h2 id="milestones-heading" className="font-display text-xl text-fg">
              Milestones
            </h2>
            <ul className="mt-4 flex flex-col divide-y divide-border">
              {settings.map((setting) => (
                <li
                  key={setting.key}
                  className="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0"
                >
                  <div className="min-w-0">
                    <span className="block font-medium text-fg">{setting.label}</span>
                    <span className="text-sm text-fg-muted">{setting.description}</span>
                  </div>
                  <label className="flex items-center gap-2 text-sm">
                    <input
                      type="checkbox"
                      checked={setting.enabled}
                      disabled={savingKey === setting.key}
                      onChange={() => void toggle(setting)}
                      aria-label={setting.label}
                      className="h-4 w-4"
                    />
                    <span className={setting.enabled ? 'text-fg' : 'text-fg-subtle'}>
                      {setting.enabled ? 'Sending' : 'Off'}
                    </span>
                  </label>
                </li>
              ))}
            </ul>
          </Card>
        </Motion>

        <Motion variants={staggerItem}>
          <Card padding="lg" aria-labelledby="cadence-heading">
            <h2 id="cadence-heading" className="font-display text-xl text-fg">
              Reminder schedule
            </h2>
            <p className="mt-2 max-w-2xl text-sm text-fg-muted">
              Days to wait before each reminder. After the last one the order is flagged for you to
              phone, and no further emails are sent — someone who has ignored three emails will
              ignore the fourth.
            </p>
            <div className="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end">
              <div className="flex-1">
                <Input
                  label="Unanswered quote"
                  hint="Comma-separated, e.g. 3, 7, 12"
                  value={quoteDays}
                  onChange={(e) => {
                    setQuoteDays(e.target.value);
                    setCadenceError(undefined);
                  }}
                />
              </div>
              <div className="flex-1">
                <Input
                  label="Unapproved proof"
                  hint="Chased sooner — it holds up production"
                  value={proofDays}
                  error={cadenceError}
                  onChange={(e) => {
                    setProofDays(e.target.value);
                    setCadenceError(undefined);
                  }}
                />
              </div>
              <Button variant="primary" loading={savingCadence} onClick={() => void saveCadence()}>
                Save schedule
              </Button>
            </div>
          </Card>
        </Motion>
      </section>
    </Motion>
  );
}
