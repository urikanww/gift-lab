export interface VariantAxis {
  /** Display name (label-only; not stored). */
  name?: string;
  /** Comma-separated values, e.g. "S, M, L". */
  values: string;
}

/** Parse one axis' comma list: trim, drop blanks, de-dupe case-insensitively, keep order. */
function parseAxis(values: string): string[] {
  const seen = new Set<string>();
  const out: string[] = [];
  for (const raw of values.split(',')) {
    const v = raw.trim();
    if (v === '') continue;
    const key = v.toLowerCase();
    if (seen.has(key)) continue;
    seen.add(key);
    out.push(v);
  }
  return out;
}

/**
 * Cross-product the axes into combined labels ("M / Black"). A single axis
 * yields bare values. Empty axes are ignored; no values anywhere -> [].
 */
export function generateVariantLabels(axes: VariantAxis[]): string[] {
  const lists = axes.map((a) => parseAxis(a.values)).filter((l) => l.length > 0);
  if (lists.length === 0) return [];

  return lists.reduce<string[]>(
    (acc, list) => acc.flatMap((prefix) => list.map((v) => (prefix ? `${prefix} / ${v}` : v))),
    [''],
  );
}
