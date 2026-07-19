import { useEffect, useState } from 'react';
import { Button, Card } from '../ui';
import { MAX_SAVED_ADDRESSES, useSavedAddressStore } from '../stores/savedAddressStore';
import ShippingFields, {
  EMPTY_SHIPPING,
  isShippingValid,
  type ShippingFieldsValue,
} from '../components/checkout/ShippingFields';
import type { SavedAddress } from '../types';

function toValue(a: SavedAddress): ShippingFieldsValue {
  return {
    label: a.label ?? '',
    recipient_name: a.recipient_name,
    phone: a.phone,
    email: a.email ?? '',
    line1: a.line1,
    line2: a.line2 ?? '',
    city: a.city ?? '',
    state: a.state ?? '',
    postal_code: a.postal_code,
    country: a.country || 'SG',
    notes: a.notes ?? '',
  };
}

export default function AddressBookPage() {
  const { addresses, error, fetch, create, update, remove } = useSavedAddressStore();
  const [editing, setEditing] = useState<number | 'new' | null>(null);
  const [form, setForm] = useState<ShippingFieldsValue>(EMPTY_SHIPPING);

  useEffect(() => {
    void fetch();
  }, [fetch]);

  const startNew = () => {
    setForm(EMPTY_SHIPPING);
    setEditing('new');
  };
  const startEdit = (a: SavedAddress) => {
    setForm(toValue(a));
    setEditing(a.id);
  };

  const save = async () => {
    const payload = {
      label: form.label?.trim() || null,
      recipient_name: form.recipient_name.trim(),
      phone: form.phone.trim(),
      email: form.email?.trim() || null,
      line1: form.line1.trim(),
      line2: form.line2?.trim() || null,
      city: form.city?.trim() || null,
      state: form.state?.trim() || null,
      postal_code: form.postal_code.trim(),
      country: (form.country || 'SG').trim(),
      notes: form.notes?.trim() || null,
    };
    const ok = editing === 'new' ? await create(payload) : await update(editing as number, payload);
    if (ok) setEditing(null);
  };

  return (
    <section aria-labelledby="addresses-heading" className="mx-auto max-w-2xl">
      <h1 id="addresses-heading" className="mb-6 font-display text-3xl text-fg">
        Saved addresses
      </h1>

      {error && <p className="mb-4 text-sm text-danger" role="alert">{error}</p>}

      <div className="flex flex-col gap-3">
        {addresses.map((a) => (
          <Card key={a.id} padding="lg">
            <div className="flex items-start justify-between gap-4">
              <div className="min-w-0 text-sm">
                {a.label && <p className="font-medium text-fg">{a.label}</p>}
                <p className="text-fg">{a.recipient_name}</p>
                <p className="text-fg-muted">{a.line1}{a.line2 ? `, ${a.line2}` : ''}</p>
                <p className="text-fg-muted">{a.postal_code} · {a.country}</p>
              </div>
              <div className="flex shrink-0 gap-2">
                <Button variant="ghost" size="sm" onClick={() => startEdit(a)}>Edit</Button>
                <Button variant="ghost" size="sm" onClick={() => void remove(a.id)}>Delete</Button>
              </div>
            </div>
          </Card>
        ))}
      </div>

      {editing !== null ? (
        <Card padding="lg" className="mt-4">
          <h2 className="mb-3 font-display text-xl text-fg">
            {editing === 'new' ? 'Add address' : 'Edit address'}
          </h2>
          <ShippingFields value={form} onChange={setForm} showLabel />
          <div className="mt-4 flex gap-2">
            <Button variant="primary" onClick={() => void save()} disabled={!isShippingValid(form)}>
              Save
            </Button>
            <Button variant="ghost" onClick={() => setEditing(null)}>Cancel</Button>
          </div>
        </Card>
      ) : (
        addresses.length < MAX_SAVED_ADDRESSES && (
          <Button variant="secondary" className="mt-4" onClick={startNew}>
            Add address
          </Button>
        )
      )}

      {addresses.length >= MAX_SAVED_ADDRESSES && editing === null && (
        <p className="mt-3 text-xs text-fg-subtle">
          You&rsquo;ve saved the maximum of {MAX_SAVED_ADDRESSES} addresses. Delete one to add another.
        </p>
      )}
    </section>
  );
}
