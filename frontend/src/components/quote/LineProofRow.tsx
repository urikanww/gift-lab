import { Badge, Button } from '../../ui';
import { proofStateTone } from '../../lib/quoteStatus';
import ProductThumb from '../product/ProductThumb';
import BuyerBrief from './BuyerBrief';
import ProofFileInput from './ProofFileInput';
import type { ArtworkOption } from './ArtworkPicker';
import type { BadgeTone } from '../../ui';
import type { LineItem, Proof } from '../../types';

/**
 * One artwork line in the staff per-line proof panel: the line's product, the
 * state of its latest proof, and the controls to stage (or replace) a DRAFT
 * proof for it. Purely presentational - every action is a callback the page
 * wires to the store; the row never touches it.
 */

interface LineProofRowProps {
  line: LineItem;
  /** Latest proof for this line, or null when none has been prepared. */
  proof: Proof | null;
  /** Reuse options for the picker (this line's own design defaulted first). */
  artworkOptions: ArtworkOption[];
  busy: boolean;
  /** Stage/replace this line's DRAFT from an uploaded artwork ref. */
  onStage: (artworkRef: string) => void;
  /** Remove this line's staged DRAFT proof (server-side). */
  onRemove: () => void;
  /** Open the existing-artwork picker for this line. */
  onPickExisting: () => void;
  /**
   * Drop this line: opens the line editor. Undefined hides the control
   * entirely (non-staff contexts). When present but `dropDisabledReason` is
   * set, the button renders disabled with that reason as a caption.
   */
  onDrop?: () => void;
  /**
   * When set, the Drop button is disabled and this text is shown beneath it as
   * the reason (e.g. the line editor isn't reachable in the current state).
   */
  dropDisabledReason?: string;
}

/** Badge label + tone for a line's proof state; neutral when none exists yet. */
function proofBadge(state: Proof['state'] | null): { label: string; tone: BadgeTone } {
  switch (state) {
    case 'DRAFT':
      return { label: 'Staged', tone: proofStateTone('DRAFT') };
    case 'SENT':
      return { label: 'Sent', tone: proofStateTone('SENT') };
    case 'CHANGES_REQUESTED':
      return { label: 'In changes', tone: proofStateTone('CHANGES_REQUESTED') };
    case 'APPROVED':
      return { label: 'Approved', tone: proofStateTone('APPROVED') };
    default:
      return { label: 'Not prepared', tone: 'neutral' };
  }
}

export default function LineProofRow({
  line,
  proof,
  artworkOptions,
  busy,
  onStage,
  onRemove,
  onPickExisting,
  onDrop,
  dropDisabledReason,
}: LineProofRowProps) {
  const productName = line.product?.name ?? `Line #${line.id}`;
  const badge = proofBadge(proof?.state ?? null);

  return (
    <div className="flex flex-col gap-3 py-3">
      <div className="flex items-start justify-between gap-3">
        <div className="flex min-w-0 items-start gap-3">
          <ProductThumb product={line.product} className="h-12 w-12" />
          <div className="min-w-0">
            <span className="block truncate font-medium text-fg">{productName}</span>
            <span className="text-xs text-fg-muted tabular-nums">Qty {line.qty}</span>
          </div>
        </div>
        {/* Line-level state + action live together in the header, away from the
            proof-staging controls below, so neither row feels crowded. */}
        <div className="flex shrink-0 flex-col items-end gap-1.5">
          <Badge tone={badge.tone} size="sm">
            {badge.label}
          </Badge>
          {onDrop && (
            <Button
              variant="ghost"
              size="sm"
              className="text-danger hover:bg-danger-bg"
              disabled={busy || Boolean(dropDisabledReason)}
              title={dropDisabledReason}
              onClick={onDrop}
            >
              Drop item
            </Button>
          )}
          {onDrop && dropDisabledReason && (
            <span className="max-w-[12rem] text-right text-2xs text-fg-subtle">
              {dropDisabledReason}
            </span>
          )}
        </div>
      </div>

      {/* The buyer's brief (reference images + placement notes) when they asked
          us to do the design - staff produce the proof artwork from this. */}
      <BuyerBrief customization={line.customization} />

      <ProofFileInput
        label={`Proof for ${productName}`}
        hint="PDF or image, up to 3 MB."
        value={proof?.state === 'DRAFT' ? proof.artwork_version_ref : ''}
        valueLabel={proof?.state === 'DRAFT' ? `Staged ${productName} artwork` : undefined}
        disabled={busy}
        onChange={(ref) => {
          // Staging an uploaded ref goes through onStage; removal is handled by
          // onRemove (the staged DRAFT lives server-side, not locally).
          if (ref) onStage(ref);
        }}
        onRemove={onRemove}
        // Sits on the field row, right of Remove/the picker, so upload and
        // "reuse what's on the order" read as the two ways to fill the proof.
        trailing={
          artworkOptions.length > 0 ? (
            <Button variant="secondary" size="sm" disabled={busy} onClick={onPickExisting}>
              Use existing artwork
            </Button>
          ) : undefined
        }
      />
    </div>
  );
}
