/**
 * Human-facing metadata for the pricing/config editor. The backend stays a
 * generic key/value store; this layer maps each `group.key` to a plain-language
 * label, a one-line explanation, and which structured editor to render - so a
 * non-technical shopkeeper never sees raw JSON or machine keys.
 */

export type EditorKind =
  | 'money' // single SGD amount
  | 'percent' // single %
  | 'number' // plain number (optional unit)
  | 'days' // number of days
  | 'toggle' // boolean on/off
  | 'b2cToggle' // pay_now_cutoff object → single b2c_enabled toggle
  | 'moneyMap' // fixed-key map of SGD amounts (S/M/L, UV/FDM/RESIN)
  | 'daysMap' // fixed-key map of day counts (UV/3D)
  | 'deliveryTiers' // array of { max_weight_g, price } weight bands
  | 'tagList'; // array of strings (chips)

export interface FieldMeta {
  label: string;
  help: string;
  editor: EditorKind;
  /** Tucked behind the "Advanced settings" section. */
  advanced?: boolean;
  /** Friendly labels for fixed map keys (moneyMap / daysMap). */
  keyLabels?: Record<string, string>;
  /** Unit suffix for `number` fields (e.g. "min/g", "models"). */
  unit?: string;
}

export const GROUP_ORDER = [
  'margin',
  'fee',
  'print_cost',
  'threshold',
  'delivery',
  'lead_time',
  'config',
  'catalogue',
] as const;

export const GROUP_LABELS: Record<string, string> = {
  margin: 'Margins & profit',
  fee: 'Fees',
  print_cost: 'Print costs',
  threshold: 'Bulk discount',
  delivery: 'Delivery',
  lead_time: 'Lead time',
  config: 'Checkout',
  catalogue: 'Catalogue automation',
};

export const CONFIG_META: Record<string, FieldMeta> = {
  'margin.default_pct': {
    label: 'Default profit margin',
    help: 'Profit added on top of cost for a normal quote.',
    editor: 'percent',
  },
  'margin.floor_pct': {
    label: 'Minimum margin floor',
    help: 'Staff can never discount a quote below cost plus this margin.',
    editor: 'percent',
    advanced: true,
  },

  'fee.customization_flat': {
    label: 'Customization fee (per line)',
    help: 'One-off charge added to any line that has customization.',
    editor: 'money',
  },
  'fee.customization_per_unit': {
    label: 'Personalisation fee (per item)',
    help: 'Extra charge per piece for individual names or text.',
    editor: 'money',
  },
  'fee.customization_by_size': {
    label: 'Logo surcharge by size',
    help: 'Extra per-piece charge based on how big the printed logo is.',
    editor: 'moneyMap',
    keyLabels: { S: 'Small logo', M: 'Medium logo', L: 'Large logo' },
  },
  'fee.setup_fee': {
    label: 'Artwork setup fee (per order)',
    help: 'One-off design/setup charge added once per quote.',
    editor: 'money',
  },

  'print_cost.per_unit': {
    label: 'Print cost by method (per item)',
    help: 'What it costs to decorate one item, by print method.',
    editor: 'moneyMap',
    keyLabels: { UV: 'UV print', FDM: '3D - FDM (plastic)', RESIN: '3D - resin' },
  },
  'print_cost.filament_per_gram': {
    label: 'Filament cost per gram',
    help: 'Material cost for 3D-printed items, per gram.',
    editor: 'money',
  },
  'print_cost.minutes_per_gram': {
    label: 'Print time per gram',
    help: 'Rough print-minutes-per-gram estimate, until the slicer gives real times.',
    editor: 'number',
    unit: 'min/g',
    advanced: true,
  },
  'print_cost.machine_rate_per_min': {
    label: 'Printer running cost per minute',
    help: 'What running the 3D printer costs per minute.',
    editor: 'money',
  },

  'threshold.bulk_qty': {
    label: 'Bulk order size',
    help: 'Order quantity at or above which the bulk discount applies.',
    editor: 'number',
    unit: 'items',
  },
  'threshold.bulk_discount_pct': {
    label: 'Bulk discount',
    help: 'Discount applied once an order reaches the bulk size.',
    editor: 'percent',
  },

  'delivery.table': {
    label: 'Delivery price by weight',
    help: 'Shipping price bands by total order weight. The last band covers everything heavier.',
    editor: 'deliveryTiers',
  },

  'lead_time.production_days': {
    label: 'Production days by type',
    help: 'Base days to make an order, before shipping.',
    editor: 'daysMap',
    keyLabels: { UV: 'UV / printed blanks', '3D': '3D printed' },
  },
  'lead_time.daily_capacity': {
    label: 'Orders finished per day',
    help: 'How many jobs the workshop clears daily (used to estimate queue wait).',
    editor: 'number',
    unit: 'orders/day',
  },
  'lead_time.dispatch_days': {
    label: 'Shipping days',
    help: 'Days in transit added after production.',
    editor: 'days',
  },
  'lead_time.buffer_days': {
    label: 'Safety buffer',
    help: 'Extra days padded onto the latest delivery date shown to customers.',
    editor: 'days',
    advanced: true,
  },
  'lead_time.rush_shave_days': {
    label: 'Rush time saved',
    help: 'Days a rush order shaves off the earliest date. Set 0 to turn rush off.',
    editor: 'days',
  },
  'lead_time.rush_fee': {
    label: 'Rush fee',
    help: 'Extra charge for a rush order.',
    editor: 'money',
  },

  'config.pay_now_cutoff': {
    label: 'Allow B2C pay-now checkout',
    help: 'Let individual (non-account) buyers pay upfront instead of quote-only.',
    editor: 'b2cToggle',
    advanced: true,
  },

  'catalogue.auto_publish': {
    label: 'Auto-publish new 3D models',
    help: 'Automatically list 3D models that pass the licence and IP checks.',
    editor: 'toggle',
    advanced: true,
  },
  'catalogue.drift_pct': {
    label: 'Price re-review trigger',
    help: 'How much a supplier price can move before an item is pulled for re-review.',
    editor: 'percent',
    advanced: true,
  },
  'catalogue.ip_blocklist': {
    label: 'Blocked trademark keywords',
    help: 'A 3D model whose title contains any of these words is rejected.',
    editor: 'tagList',
    advanced: true,
  },
  'catalogue.price_jump_pct': {
    label: 'Price-jump tolerance',
    help: 'Supplier price increase tolerated at re-check before flagging.',
    editor: 'percent',
    advanced: true,
  },
  'catalogue.browse_cap': {
    label: 'Nightly import cap per source',
    help: 'Most 3D models pulled from each source in the nightly sweep.',
    editor: 'number',
    unit: 'models',
    advanced: true,
  },
};

/**
 * Metadata for a config, falling back to a humanised key + a best-guess editor
 * when a row isn't in the registry (so a newly-seeded config still renders).
 */
/** Stable DOM id for a config field, so the breakdown can scroll to it. */
export function fieldDomId(configKey: string): string {
  return `cfg-${configKey.replace(/\./g, '-')}`;
}

export function metaFor(group: string, key: string, value: unknown): FieldMeta {
  const found = CONFIG_META[`${group}.${key}`];
  if (found) return found;

  const label = key.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase());
  let editor: EditorKind = 'number';
  if (typeof value === 'boolean') editor = 'toggle';
  else if (typeof value === 'string') editor = 'number';
  else if (Array.isArray(value)) editor = 'tagList';
  else if (value && typeof value === 'object') editor = 'moneyMap';
  return { label, help: '', editor, advanced: true };
}
