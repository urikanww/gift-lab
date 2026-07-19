import { Input } from '../../ui';
import type { SavedAddressInput } from '../../types';

/** Value carried by the form: the ship-to fields plus an optional book label. */
export interface ShippingFieldsValue extends SavedAddressInput {
  label?: string | null;
}

export const EMPTY_SHIPPING: ShippingFieldsValue = {
  label: '',
  recipient_name: '',
  phone: '',
  email: '',
  line1: '',
  line2: '',
  city: '',
  state: '',
  postal_code: '',
  country: 'SG',
  notes: '',
};

/** The four fields the courier must have; used to gate submission. */
export function isShippingValid(v: ShippingFieldsValue): boolean {
  return (
    v.recipient_name.trim() !== '' &&
    v.phone.trim() !== '' &&
    v.line1.trim() !== '' &&
    v.postal_code.trim() !== ''
  );
}

interface Props {
  value: ShippingFieldsValue;
  onChange: (next: ShippingFieldsValue) => void;
  /** Show the address-book label field (address book only, not checkout). */
  showLabel?: boolean;
}

export default function ShippingFields({ value, onChange, showLabel = false }: Props) {
  const set = (field: keyof ShippingFieldsValue) => (e: React.ChangeEvent<HTMLInputElement>) =>
    onChange({ ...value, [field]: e.target.value });

  return (
    <div className="grid gap-3 sm:grid-cols-2">
      {showLabel && (
        <label className="flex flex-col gap-1 text-sm sm:col-span-2">
          <span className="text-fg-subtle">Label (optional)</span>
          <Input value={value.label ?? ''} onChange={set('label')} placeholder="Office, Warehouse…" />
        </label>
      )}
      <label className="flex flex-col gap-1 text-sm">
        <span className="text-fg-subtle">Recipient name *</span>
        <Input value={value.recipient_name} onChange={set('recipient_name')} required />
      </label>
      <label className="flex flex-col gap-1 text-sm">
        <span className="text-fg-subtle">Phone *</span>
        <Input value={value.phone} onChange={set('phone')} required />
      </label>
      <label className="flex flex-col gap-1 text-sm sm:col-span-2">
        <span className="text-fg-subtle">Address line 1 *</span>
        <Input value={value.line1} onChange={set('line1')} required />
      </label>
      <label className="flex flex-col gap-1 text-sm sm:col-span-2">
        <span className="text-fg-subtle">Address line 2</span>
        <Input value={value.line2 ?? ''} onChange={set('line2')} />
      </label>
      <label className="flex flex-col gap-1 text-sm">
        <span className="text-fg-subtle">City</span>
        <Input value={value.city ?? ''} onChange={set('city')} />
      </label>
      <label className="flex flex-col gap-1 text-sm">
        <span className="text-fg-subtle">Postal code *</span>
        <Input value={value.postal_code} onChange={set('postal_code')} required />
      </label>
      <label className="flex flex-col gap-1 text-sm">
        <span className="text-fg-subtle">State / region</span>
        <Input value={value.state ?? ''} onChange={set('state')} />
      </label>
      <label className="flex flex-col gap-1 text-sm">
        <span className="text-fg-subtle">Country *</span>
        <Input value={value.country} onChange={set('country')} maxLength={2} required />
      </label>
      <label className="flex flex-col gap-1 text-sm sm:col-span-2">
        <span className="text-fg-subtle">Delivery notes</span>
        <Input value={value.notes ?? ''} onChange={set('notes')} />
      </label>
    </div>
  );
}
