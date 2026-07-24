import { useState } from 'react';
import { Badge, Button } from '../../ui';
import { humanizeState, proofStateTone } from '../../lib/quoteStatus';
import { safeHref } from '../../lib/safeHref';
import ArtworkPreview from './ArtworkPreview';
import ProofFileInput from './ProofFileInput';
import type { Proof } from '../../types';

/**
 * One artwork line's current proof in the buyer's per-line review. The buyer
 * signs each line off independently: approve it, or send it back with a note
 * (and an optional reference image). A line already sent back for revision shows
 * a passive "being revised" note instead of controls.
 *
 * Presentational: the inline "request changes" reveal is local state, but every
 * store call is a callback the page wires up.
 */
interface BuyerProofItemProps {
  /** This line's latest proof (SENT or CHANGES_REQUESTED). */
  proof: Proof;
  busy: boolean;
  /** Approve this line's proof (decideProof(proof.id, 'approve')). */
  onApprove: () => void;
  /** Send this line back with a required note + optional reference image ref. */
  onRequestChanges: (notes: string, attachmentRef: string | null) => void;
}

export default function BuyerProofItem({
  proof,
  busy,
  onApprove,
  onRequestChanges,
}: BuyerProofItemProps) {
  const [changesOpen, setChangesOpen] = useState(false);
  const [changeNotes, setChangeNotes] = useState('');
  const [changeAttachment, setChangeAttachment] = useState('');

  const productName = proof.product_name ?? `Line #${proof.line_item_id}`;
  const artworkHref = proof.artwork_url ?? safeHref(proof.artwork_version_ref);

  return (
    <div className="flex flex-col gap-3 rounded-md border border-border bg-surface p-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h3 className="font-medium text-fg">{productName}</h3>
        <span className="flex items-center gap-2">
          <span className="text-sm font-medium text-fg">v{proof.version}</span>
          <Badge tone={proofStateTone(proof.state)} size="sm">
            {humanizeState(proof.state)}
          </Badge>
        </span>
      </div>

      {/* The artwork itself, shown in place. artwork_url is resolved
          server-side; safeHref covers legacy rows holding a pasted URL. */}
      <ArtworkPreview url={artworkHref} />

      {proof.state === 'CHANGES_REQUESTED' ? (
        <div className="rounded-md border border-warning/30 bg-warning-bg p-3">
          <p className="text-sm text-fg-muted">
            Being revised — we’ll send you an updated proof.
          </p>
          {proof.notes && (
            <p className="mt-1 text-sm text-fg-muted">
              <span className="font-medium text-fg">Your note: </span>
              {proof.notes}
            </p>
          )}
        </div>
      ) : (
        <div className="flex flex-col gap-3">
          <div className="flex flex-wrap gap-3">
            <Button variant="primary" loading={busy} disabled={busy} onClick={onApprove}>
              Approve proof
            </Button>
            <Button
              variant="outline"
              disabled={busy || changesOpen}
              onClick={() => setChangesOpen(true)}
            >
              Request changes
            </Button>
          </div>

          {/* Inline reveal: capture WHAT to change before sending. */}
          {changesOpen && (
            <div className="flex flex-col gap-3 rounded-md border border-border bg-surface-2/50 p-3">
              <label htmlFor={`change-notes-${proof.id}`} className="text-sm font-medium text-fg">
                What should we change? <span className="font-normal text-danger">(required)</span>
              </label>
              <textarea
                id={`change-notes-${proof.id}`}
                rows={3}
                maxLength={2000}
                value={changeNotes}
                onChange={(e) => setChangeNotes(e.target.value)}
                placeholder="e.g. Move the logo up and use the darker blue from our brand kit."
                className="w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-base text-fg placeholder:text-fg-subtle transition-colors duration-fast ease-standard focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-bg"
              />
              <p className="text-xs text-fg-subtle">
                Tell us what to fix so we can turn around a revised proof — this note goes to our team.
              </p>

              {/* Optional reference image: a mock-up, a photo, a sketch of what
                  they want. Uploads to the buyer artwork endpoint and travels to
                  staff on the change request. */}
              <ProofFileInput
                label="Attach a reference (optional)"
                hint="An image showing what you'd like — PNG, JPG or WEBP, up to 10 MB."
                value={changeAttachment}
                error={undefined}
                disabled={busy}
                endpoint="/uploads/artwork"
                field="artwork"
                accept="image/png,image/jpeg,image/webp"
                maxBytes={10 * 1024 * 1024}
                onChange={(ref) => setChangeAttachment(ref)}
              />

              <div className="flex flex-wrap gap-3">
                <Button
                  variant="primary"
                  loading={busy}
                  // A reason is mandatory: staff need to know WHAT to revise, and
                  // the API rejects request_changes without a note anyway.
                  disabled={busy || changeNotes.trim().length === 0}
                  onClick={() => onRequestChanges(changeNotes.trim(), changeAttachment || null)}
                >
                  Send request
                </Button>
                <Button variant="ghost" disabled={busy} onClick={() => setChangesOpen(false)}>
                  Cancel
                </Button>
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
