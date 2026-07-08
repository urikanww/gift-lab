import { useCallback, useState } from 'react';
import { uploadArtworkFile } from '../lib/uploadArtwork';
import { cn, useOptionalToast } from '../ui';

export interface FinishedLookValue {
  reference_refs: string[];
  logo_ref: string | null;
  placement_notes: string;
}

interface Props {
  onChange: (value: FinishedLookValue) => void;
}

const MAX_REFERENCES = 6;

/**
 * Fallback panel: the buyer uploads reference image(s) of the finished look,
 * their logo file, and placement notes. Production proofs this before printing
 * (existing Quote -> Proof loop); it never produces a ready-to-print file.
 */
export default function FinishedLookUploader({ onChange }: Props) {
  const { toast } = useOptionalToast();
  const [refs, setRefs] = useState<string[]>([]);
  const [logoRef, setLogoRef] = useState<string | null>(null);
  const [notes, setNotes] = useState('');
  const [busy, setBusy] = useState(false);

  const emit = useCallback(
    (next: Partial<FinishedLookValue>) => {
      onChange({
        reference_refs: next.reference_refs ?? refs,
        logo_ref: next.logo_ref !== undefined ? next.logo_ref : logoRef,
        placement_notes: next.placement_notes ?? notes,
      });
    },
    [refs, logoRef, notes, onChange],
  );

  const addReference = async (file: File) => {
    if (refs.length >= MAX_REFERENCES) return;
    setBusy(true);
    try {
      const ref = await uploadArtworkFile(file);
      const nextRefs = [...refs, ref];
      setRefs(nextRefs);
      emit({ reference_refs: nextRefs });
    } catch {
      toast({ title: 'Upload failed', description: 'Please try a PNG/JPG under 10 MB.', tone: 'danger' });
    } finally {
      setBusy(false);
    }
  };

  const addLogo = async (file: File) => {
    setBusy(true);
    try {
      const ref = await uploadArtworkFile(file);
      setLogoRef(ref);
      emit({ logo_ref: ref });
    } catch {
      toast({ title: 'Upload failed', description: 'Please try a PNG/JPG under 10 MB.', tone: 'danger' });
    } finally {
      setBusy(false);
    }
  };

  const dropzone = 'flex flex-col items-center justify-center gap-1 rounded-md border border-dashed border-border-strong bg-surface-2/50 px-3 py-4 text-center text-sm cursor-pointer hover:border-primary hover:bg-surface-2';

  return (
    <div className="flex flex-col gap-3">
      <label className={cn(dropzone)}>
        <span className="font-medium text-fg">Reference image(s) of the final look</span>
        <span className="text-2xs text-fg-subtle">PNG or JPG, up to 6 images</span>
        <input
          type="file"
          accept="image/png,image/jpeg,image/webp"
          aria-label="Reference image"
          className="sr-only"
          disabled={busy}
          onChange={(e) => {
            const f = e.target.files?.[0];
            if (f) void addReference(f);
            e.target.value = '';
          }}
        />
      </label>
      {refs.length > 0 && <p className="text-2xs text-fg-subtle">{refs.length} reference image(s) attached</p>}

      <label className={cn(dropzone)}>
        <span className="font-medium text-fg">Your logo file</span>
        <span className="text-2xs text-fg-subtle">PNG or JPG</span>
        <input
          type="file"
          accept="image/png,image/jpeg,image/webp"
          aria-label="Logo file"
          className="sr-only"
          disabled={busy}
          onChange={(e) => {
            const f = e.target.files?.[0];
            if (f) void addLogo(f);
            e.target.value = '';
          }}
        />
      </label>
      {logoRef && <p className="text-2xs text-fg-subtle">Logo attached</p>}

      <label className="flex flex-col gap-1">
        <span className="text-2xs font-medium text-fg-subtle">Placement notes</span>
        <textarea
          aria-label="Placement notes"
          rows={3}
          maxLength={2000}
          value={notes}
          placeholder="e.g. centre of the lid, ~4cm wide"
          onChange={(e) => {
            setNotes(e.target.value);
            emit({ placement_notes: e.target.value });
          }}
          className="rounded-md border border-border bg-surface px-3 py-2 text-sm text-fg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        />
      </label>

      <p className="text-2xs text-fg-subtle">
        We confirm producibility before printing. If something's off, we'll request changes.
      </p>
      {busy && <p className="text-2xs text-fg-subtle" role="status">Uploading…</p>}
    </div>
  );
}
