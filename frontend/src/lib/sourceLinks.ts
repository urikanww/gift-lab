export interface SourceLink {
  label: string;
  url: string;
  kind: 'local' | 'marketplace';
  price: number | null;
  currency: string;
  last_checked: string | null;
}

/** First local link (fastest to fulfil), else the first link, else null. */
export function primarySourceLink(links: SourceLink[]): SourceLink | null {
  return links.find((l) => l.kind === 'local' && l.url) ?? links[0] ?? null;
}
