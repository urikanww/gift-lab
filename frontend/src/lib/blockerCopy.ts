// Staff-facing copy for the catalogue-gate blocker tokens emitted by the backend
// gates (CompletenessGate + Model3dCatalogueService + resync commands). Shared so
// the gate row and the resolve-blockers popup can't describe the same blocker two
// different ways - they render the same tokens on different surfaces.

/**
 * Human labels for the machine reason tokens. Unknown or future tokens fall back
 * to a prettified form (see {@link blockerLabel}) so a raw enum never renders.
 * Also enumerated as-is to build the gate's Blocker filter dropdown.
 */
export const BLOCKER_LABELS: Record<string, string> = {
  missing_model_file: 'No printable model file',
  awaiting_model_file: 'Awaiting 3D model (skipped until pulled)',
  license_review: 'Licence needs review',
  multi_file_review: 'Multi-file set needs review',
  estimates_unverified: 'Filament estimates unverified',
  missing_price: 'No price from source',
  missing_dimensions: 'Missing dimensions or weight',
  not_printable: 'No print method set',
  stock_unreadable: 'Stock level unreadable',
  source_dead: 'Source listing gone',
  'needs_re-review': 'Needs re-review',
  license_blocked: 'Licence blocks commercial use',
  missing_credit: 'Creator credit missing',
};

export function blockerLabel(token: string): string {
  const known = BLOCKER_LABELS[token];
  if (known) return known;
  if (token.startsWith('ip_flag:')) return `IP flag: ${token.slice('ip_flag:'.length)}`;
  // Fallback prettifier: snake/kebab token → sentence case.
  const pretty = token.replace(/[_-]+/g, ' ').trim();
  return pretty.charAt(0).toUpperCase() + pretty.slice(1);
}

/**
 * The scraped-gate blockers a staffer can clear by typing a fact off the source
 * listing, i.e. the ones the resolve-blockers popup renders a field group for.
 * stock_unreadable is here too: the affiliate feed never carries a quantity, so
 * a sync can't clear it on its own - the staffer enters a manual (indicative)
 * stock in the popup instead. Everything else (source_dead, needs_re-review) is
 * source-truth and resolves on the next sync - see the design spec.
 *
 * Module-private: `isFixableBlocker` is the whole public surface, and nothing
 * needs to enumerate the tokens.
 */
const FIXABLE_BLOCKERS = ['missing_dimensions', 'not_printable', 'missing_price', 'stock_unreadable'] as const;

export function isFixableBlocker(token: string): boolean {
  return (FIXABLE_BLOCKERS as readonly string[]).includes(token);
}

/**
 * Why a source-truth blocker can't be cleared by staff. Deliberately covers ONLY
 * these two: every other blocker is either fixable in the resolve-blockers
 * popup or cleared by an inline row tool, and a blanket "resolves at the source"
 * fallback would actively mislead there (e.g. missing_model_file is cleared by
 * the Attach model file button on the row). Callers must handle `undefined` by
 * saying nothing rather than guessing.
 */
export const BLOCKER_HELP: Record<string, string> = {
  source_dead: 'The source listing is gone. Re-capture the product or archive it.',
  'needs_re-review': 'The source price moved past the drift threshold. It re-checks on the next sync.',
};

export function blockerHelp(token: string): string | undefined {
  return BLOCKER_HELP[token];
}
