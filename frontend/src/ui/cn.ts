/**
 * Minimal className joiner. Accepts strings, falsy values, and objects
 * ({ 'is-active': cond }). Avoids pulling in clsx for a 12-line helper.
 */
export type ClassValue = string | number | false | null | undefined | Record<string, boolean>;

export function cn(...parts: ClassValue[]): string {
  const out: string[] = [];
  for (const part of parts) {
    if (!part) continue;
    if (typeof part === 'string' || typeof part === 'number') {
      out.push(String(part));
    } else {
      for (const [key, active] of Object.entries(part)) {
        if (active) out.push(key);
      }
    }
  }
  return out.join(' ');
}
