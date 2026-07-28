import { useCallback, useEffect, useState } from 'react';
import api, { apiError, ensureCsrf } from '../lib/api';
import { Button, Card, Input, Select, Skeleton, useToast } from '../ui';
import { ErrorState } from '../components/ui/States';
import { Motion, staggerContainer, staggerItem } from '../motion';

/**
 * The pickup (sender) address and collection time window NinjaVan uses on every
 * shipment. Editing it here means staff can move warehouse or change pickup
 * hours without a redeploy - the previous behaviour needed env vars, and a
 * missing pickup address made every real booking fail.
 */

interface PickupAddress {
  name: string;
  phone: string;
  email: string;
  address1: string;
  city: string;
  state: string;
  postcode: string;
  country: string;
}

interface Timeslot {
  start: string;
  end: string;
  timezone: string;
}

interface Schedule {
  weekday: string; // 'any' | 'monday'..'friday'
  blackout_dates: string[];
}

interface CourierConfig {
  pickup: PickupAddress;
  timeslot: Timeslot;
  schedule: Schedule;
}

/** NinjaVan's accepted windows (mirrors CourierConfig::VALID_TIMESLOTS). */
const VALID_SLOTS: { start: string; end: string }[] = [
  { start: '09:00', end: '12:00' },
  { start: '12:00', end: '15:00' },
  { start: '15:00', end: '18:00' },
  { start: '18:00', end: '22:00' },
  { start: '09:00', end: '18:00' },
  { start: '09:00', end: '22:00' },
];
const START_TIMES = [...new Set(VALID_SLOTS.map((s) => s.start))];
const endsForStart = (start: string) => VALID_SLOTS.filter((s) => s.start === start).map((s) => s.end);
/** "09:00" -> "9:00am" for the dropdowns. */
const timeLabel = (t: string) => {
  const [h, m] = t.split(':').map(Number);
  const ap = h < 12 ? 'am' : 'pm';
  const hr = h % 12 === 0 ? 12 : h % 12;
  return `${hr}:${String(m).padStart(2, '0')}${ap}`;
};
const WEEKDAYS = ['any', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday'];
const weekdayLabel = (d: string) => (d === 'any' ? 'Any (earliest available)' : d[0].toUpperCase() + d.slice(1));

const EMPTY: CourierConfig = {
  pickup: { name: '', phone: '', email: '', address1: '', city: '', state: '', postcode: '', country: 'SG' },
  timeslot: { start: '09:00', end: '18:00', timezone: 'Asia/Singapore' },
  schedule: { weekday: 'any', blackout_dates: [] },
};

export default function CourierConfigPage() {
  const [config, setConfig] = useState<CourierConfig>(EMPTY);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  // Blackout dates edited as free text (one YYYY-MM-DD per line), parsed on save.
  const [blackoutText, setBlackoutText] = useState('');
  const { toast } = useToast();

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const { data } = await api.get<CourierConfig>('/admin/courier-config');
      setConfig({
        pickup: { ...EMPTY.pickup, ...data.pickup },
        timeslot: { ...EMPTY.timeslot, ...data.timeslot },
        schedule: { ...EMPTY.schedule, ...data.schedule },
      });
      setBlackoutText((data.schedule?.blackout_dates ?? []).join('\n'));
    } catch (err) {
      setError(apiError(err));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const setPickup = (key: keyof PickupAddress, value: string) =>
    setConfig((c) => ({ ...c, pickup: { ...c.pickup, [key]: value } }));
  const setSlot = (key: keyof Timeslot, value: string) =>
    setConfig((c) => ({ ...c, timeslot: { ...c.timeslot, [key]: value } }));
  // Changing the start time constrains the end to that start's valid options.
  const setStart = (start: string) =>
    setConfig((c) => ({ ...c, timeslot: { ...c.timeslot, start, end: endsForStart(start)[0] } }));

  const save = async () => {
    setSaving(true);
    setFieldErrors({});
    // One date per line (or comma) -> a clean YYYY-MM-DD list.
    const blackout = blackoutText
      .split(/[\n,]/)
      .map((s) => s.trim())
      .filter(Boolean);
    const payload = { ...config, schedule: { ...config.schedule, blackout_dates: blackout } };
    try {
      await ensureCsrf();
      const { data } = await api.patch<CourierConfig>('/admin/courier-config', payload);
      setConfig({
        pickup: { ...EMPTY.pickup, ...data.pickup },
        timeslot: { ...EMPTY.timeslot, ...data.timeslot },
        schedule: { ...EMPTY.schedule, ...data.schedule },
      });
      setBlackoutText((data.schedule?.blackout_dates ?? []).join('\n'));
      toast({ title: 'Courier settings saved', tone: 'success' });
    } catch (err: unknown) {
      // Surface Laravel's per-field validation messages inline.
      const resp = (err as { response?: { status?: number; data?: { errors?: Record<string, string[]> } } })?.response;
      if (resp?.status === 422 && resp.data?.errors) {
        const flat: Record<string, string> = {};
        for (const [k, msgs] of Object.entries(resp.data.errors)) flat[k] = msgs[0];
        setFieldErrors(flat);
        toast({ title: 'Please fix the highlighted fields', tone: 'danger' });
      } else {
        toast({ title: apiError(err), tone: 'danger' });
      }
    } finally {
      setSaving(false);
    }
  };

  if (loading) {
    return (
      <div className="flex flex-col gap-4" aria-hidden="true">
        <Skeleton width="14rem" height="2rem" />
        <Skeleton height="20rem" />
      </div>
    );
  }
  if (error) return <ErrorState message={error} onRetry={() => void load()} />;

  return (
    <Motion variants={staggerContainer} initial="hidden" animate="visible">
      <section className="flex flex-col gap-6" aria-labelledby="courier-heading">
        <Motion variants={staggerItem}>
          <div>
            <h1 id="courier-heading" className="font-display text-3xl text-fg">
              Courier &amp; pickup
            </h1>
            <p className="mt-2 max-w-2xl text-sm text-fg-muted">
              The address NinjaVan collects parcels from, and the daily window they can collect and
              deliver within. Used on every shipment — get this right before you book real orders.
            </p>
          </div>
        </Motion>

        <Motion variants={staggerItem}>
          <Card padding="lg" aria-labelledby="pickup-heading">
            <h2 id="pickup-heading" className="font-display text-xl text-fg">
              Pickup address
            </h2>
            <div className="mt-4 grid gap-3 sm:grid-cols-2">
              <Input label="Business name" value={config.pickup.name} error={fieldErrors['pickup.name']} onChange={(e) => setPickup('name', e.target.value)} />
              <Input label="Phone" value={config.pickup.phone} error={fieldErrors['pickup.phone']} onChange={(e) => setPickup('phone', e.target.value)} />
              <Input label="Email" type="email" value={config.pickup.email} error={fieldErrors['pickup.email']} onChange={(e) => setPickup('email', e.target.value)} />
              <Input label="Address" value={config.pickup.address1} error={fieldErrors['pickup.address1']} onChange={(e) => setPickup('address1', e.target.value)} />
              <Input label="City" value={config.pickup.city} error={fieldErrors['pickup.city']} onChange={(e) => setPickup('city', e.target.value)} />
              <Input label="State / region" value={config.pickup.state} error={fieldErrors['pickup.state']} onChange={(e) => setPickup('state', e.target.value)} />
              <Input label="Postcode" value={config.pickup.postcode} error={fieldErrors['pickup.postcode']} onChange={(e) => setPickup('postcode', e.target.value)} />
              <Input label="Country (ISO-2)" hint="e.g. SG" value={config.pickup.country} error={fieldErrors['pickup.country']} onChange={(e) => setPickup('country', e.target.value.toUpperCase())} />
            </div>
          </Card>
        </Motion>

        <Motion variants={staggerItem}>
          <Card padding="lg" aria-labelledby="window-heading">
            <h2 id="window-heading" className="font-display text-xl text-fg">
              Collection &amp; delivery times
            </h2>
            <p className="mt-2 max-w-2xl text-sm text-fg-muted">
              The window NinjaVan collects and delivers within. Only the start/end times NinjaVan
              supports are offered — a custom time would be rejected at booking.
            </p>
            <div className="mt-4 grid gap-3 sm:grid-cols-3">
              <Select
                label="Start time"
                value={config.timeslot.start}
                error={fieldErrors['timeslot.start']}
                onChange={(e) => setStart(e.target.value)}
              >
                {START_TIMES.map((t) => (
                  <option key={t} value={t}>
                    {timeLabel(t)}
                  </option>
                ))}
              </Select>
              <Select
                label="End time"
                value={config.timeslot.end}
                error={fieldErrors['timeslot.end']}
                onChange={(e) => setSlot('end', e.target.value)}
              >
                {endsForStart(config.timeslot.start).map((t) => (
                  <option key={t} value={t}>
                    {timeLabel(t)}
                  </option>
                ))}
              </Select>
              <Input label="Timezone" hint="e.g. Asia/Singapore" value={config.timeslot.timezone} error={fieldErrors['timeslot.timezone']} onChange={(e) => setSlot('timezone', e.target.value)} />
            </div>
          </Card>
        </Motion>

        <Motion variants={staggerItem}>
          <Card padding="lg" aria-labelledby="day-heading">
            <h2 id="day-heading" className="font-display text-xl text-fg">
              Pickup day
            </h2>
            <p className="mt-2 max-w-2xl text-sm text-fg-muted">
              The day NinjaVan collects. If the chosen day isn’t available — a weekend, a date that’s
              already passed, or a non-collection date below — pickup rolls to the next available day.
            </p>
            <div className="mt-4 grid gap-3 sm:grid-cols-2">
              <Select
                label="Preferred collection day"
                value={config.schedule.weekday}
                error={fieldErrors['schedule.weekday']}
                onChange={(e) => setConfig((c) => ({ ...c, schedule: { ...c.schedule, weekday: e.target.value } }))}
              >
                {WEEKDAYS.map((d) => (
                  <option key={d} value={d}>
                    {weekdayLabel(d)}
                  </option>
                ))}
              </Select>
            </div>
            <div className="mt-4">
              <label htmlFor="blackout" className="mb-1 block text-sm font-medium text-fg">
                Non-collection dates (public holidays)
              </label>
              <textarea
                id="blackout"
                rows={4}
                className="w-full rounded-md border border-border bg-surface p-2 text-sm text-fg shadow-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                placeholder={'One date per line, YYYY-MM-DD\n2026-02-17\n2026-04-03'}
                value={blackoutText}
                onChange={(e) => setBlackoutText(e.target.value)}
              />
              {fieldErrors['schedule.blackout_dates.0'] && (
                <p className="mt-1 text-sm text-danger">Use YYYY-MM-DD, one per line.</p>
              )}
              <p className="mt-1 text-xs text-fg-subtle">
                Weekends are skipped automatically. Add the moving holidays (CNY, Good Friday, Hari
                Raya, Vesak, Deepavali) here each year.
              </p>
            </div>
          </Card>
        </Motion>

        <Motion variants={staggerItem}>
          <div className="flex justify-end">
            <Button variant="primary" loading={saving} onClick={() => void save()}>
              Save courier settings
            </Button>
          </div>
        </Motion>
      </section>
    </Motion>
  );
}
