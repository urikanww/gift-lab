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

interface CourierConfig {
  pickup: PickupAddress;
  timeslot: Timeslot;
}

/** NinjaVan's accepted collection/delivery windows (mirrors CourierConfig::VALID_TIMESLOTS). */
const VALID_SLOTS: { start: string; end: string; label: string }[] = [
  { start: '09:00', end: '12:00', label: '9:00am – 12:00pm' },
  { start: '12:00', end: '15:00', label: '12:00pm – 3:00pm' },
  { start: '15:00', end: '18:00', label: '3:00pm – 6:00pm' },
  { start: '18:00', end: '22:00', label: '6:00pm – 10:00pm' },
  { start: '09:00', end: '18:00', label: '9:00am – 6:00pm (business hours)' },
  { start: '09:00', end: '22:00', label: '9:00am – 10:00pm (all day)' },
];
const slotKey = (start: string, end: string) => `${start}-${end}`;

const EMPTY: CourierConfig = {
  pickup: { name: '', phone: '', email: '', address1: '', city: '', state: '', postcode: '', country: 'SG' },
  timeslot: { start: '09:00', end: '18:00', timezone: 'Asia/Singapore' },
};

export default function CourierConfigPage() {
  const [config, setConfig] = useState<CourierConfig>(EMPTY);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const { toast } = useToast();

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const { data } = await api.get<CourierConfig>('/admin/courier-config');
      setConfig({ pickup: { ...EMPTY.pickup, ...data.pickup }, timeslot: { ...EMPTY.timeslot, ...data.timeslot } });
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

  const save = async () => {
    setSaving(true);
    setFieldErrors({});
    try {
      await ensureCsrf();
      const { data } = await api.patch<CourierConfig>('/admin/courier-config', config);
      setConfig({ pickup: { ...EMPTY.pickup, ...data.pickup }, timeslot: { ...EMPTY.timeslot, ...data.timeslot } });
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
              The window NinjaVan collects and delivers within. Only the windows NinjaVan supports are
              offered — a custom time would be rejected at booking.
            </p>
            <div className="mt-4 grid gap-3 sm:grid-cols-2">
              <Select
                label="Collection window"
                value={slotKey(config.timeslot.start, config.timeslot.end)}
                error={fieldErrors['timeslot.end'] ?? fieldErrors['timeslot.start']}
                onChange={(e) => {
                  const [start, end] = e.target.value.split('-');
                  setConfig((c) => ({ ...c, timeslot: { ...c.timeslot, start, end } }));
                }}
              >
                {VALID_SLOTS.map((s) => (
                  <option key={slotKey(s.start, s.end)} value={slotKey(s.start, s.end)}>
                    {s.label}
                  </option>
                ))}
              </Select>
              <Input label="Timezone" hint="e.g. Asia/Singapore" value={config.timeslot.timezone} error={fieldErrors['timeslot.timezone']} onChange={(e) => setSlot('timezone', e.target.value)} />
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
