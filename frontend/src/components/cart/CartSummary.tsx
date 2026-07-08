import type { CartLine } from '../../types';

/** Human label for a cart line's customization (filament colour + logo size). */
export function customizationLabel(line: CartLine): string {
  const { logo_size, filament_color } = line.customization;
  const parts: string[] = [];
  if (filament_color) parts.push(`${filament_color} filament`);
  if (logo_size) parts.push(`Logo ${logo_size}`);
  return parts.length ? parts.join(' · ') : 'Blank';
}

/** Human label for a cart line's selected variant attributes. */
export function optionsLabel(line: CartLine): string {
  return line.variant ? Object.values(line.variant.attributes).join(' / ') : '-';
}

/** A single label/value row in an estimate summary. */
export function SummaryRow({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-baseline justify-between text-sm">
      <dt className="text-fg-muted">{label}</dt>
      <dd className="tabular-nums text-fg">{value}</dd>
    </div>
  );
}

/** Decorative cart glyph for empty states. */
export function CartGlyph() {
  return (
    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
      <path
        d="M3 4h2l2.4 12.3a1 1 0 0 0 1 .7h8.7a1 1 0 0 0 1-.8L21 8H6"
        stroke="currentColor"
        strokeWidth="1.5"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
      <circle cx="9" cy="20" r="1.2" fill="currentColor" />
      <circle cx="18" cy="20" r="1.2" fill="currentColor" />
    </svg>
  );
}
