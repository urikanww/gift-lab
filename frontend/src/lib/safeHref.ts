/**
 * Allow only http(s) and same-origin relative URLs as link targets. Blocks
 * javascript:, data:, and other dangerous schemes that could execute script if
 * a stored artwork reference were ever attacker-influenced (defence-in-depth
 * against stored XSS via href).
 */
export function safeHref(url: string | null | undefined): string | undefined {
  if (!url) return undefined;
  const trimmed = url.trim();
  if (trimmed.startsWith('/')) return trimmed;
  try {
    const parsed = new URL(trimmed, window.location.origin);
    return parsed.protocol === 'http:' || parsed.protocol === 'https:' ? trimmed : undefined;
  } catch {
    return undefined;
  }
}
